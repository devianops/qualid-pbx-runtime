<?php
/**
 * Quali-D PBX OS — Setup Wizard API
 *
 * Served by nginx on port 80 (pre-activation only).
 * After activation the wizard is disabled and nginx serves a static
 * "PBX is active" page instead.
 *
 * Actions (POST JSON or form-encoded):
 *   status       — check activation state (GET safe)
 *   login        — phone + password → token or totp_required
 *   verify_totp  — temp_token + code → token
 *   activate     — token → provision + write configs + enable timers
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only allow requests from the same device (LAN wizard — no CORS needed)
// Block direct internet access attempts
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

define('QUALID_IS_AUTH', 1);
define('FREEPBX_IS_AUTH', 1);   // so functions.inc.php guard passes
define('WIZARD_ACTIVATED_FLAG', '/etc/qualid/activated');

require_once dirname(__FILE__) . '/../sync-scripts/functions.inc.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function ok(array $data = []) {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function already_activated(): bool {
    return file_exists(WIZARD_ACTIVATED_FLAG);
}

// ---------------------------------------------------------------------------
// status — called on page load to decide which screen to show
// ---------------------------------------------------------------------------
if ($action === 'status') {
    ok(['activated' => already_activated()]);
}

// If already activated, reject all write actions
if (already_activated() && in_array($action, ['login','verify_totp','activate'])) {
    fail('PBX is already activated.', 403);
}

// ---------------------------------------------------------------------------
// login — phone + password
// ---------------------------------------------------------------------------
if ($action === 'login') {
    $phone    = trim($_POST['phone']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$phone || !$password) fail('Phone and password are required.');

    // Turnstile intentionally empty — LAN device, no bot risk
    $result = qualid_login($phone, $password, '');

    if (empty($result['success'])) {
        fail(isset($result['error']) ? $result['error'] : 'Login failed.');
    }

    if (!empty($result['totp_required'])) {
        ok([
            'totp_required' => true,
            'temp_token'    => $result['temp_token'] ?? '',
        ]);
    }

    // No TOTP — return token directly
    ok([
        'totp_required' => false,
        'token'         => $result['token']        ?? '',
        'name'          => $result['name']          ?? '',
        'company_name'  => $result['company_name']  ?? '',
    ]);
}

// ---------------------------------------------------------------------------
// verify_totp — temp_token + 6-digit code
// ---------------------------------------------------------------------------
if ($action === 'verify_totp') {
    $temp_token = trim($_POST['temp_token'] ?? '');
    $code       = trim($_POST['code']       ?? '');

    if (!$temp_token || !$code) fail('temp_token and code are required.');

    $result = qualid_verify_totp($temp_token, $code);

    if (empty($result['success'])) {
        fail(isset($result['error']) ? $result['error'] : 'Invalid code.');
    }

    ok([
        'token'        => $result['token']        ?? '',
        'name'         => $result['name']          ?? '',
        'company_name' => $result['company_name']  ?? '',
    ]);
}

// ---------------------------------------------------------------------------
// activate — provision trunk, write Asterisk config, enable timers
// ---------------------------------------------------------------------------
if ($action === 'activate') {
    $token = trim($_POST['token'] ?? '');
    if (!$token) fail('token is required.');

    // Step 1: Provision trunk with cloud (gets SIP creds + TURN config)
    $result = qualid_provision_trunk($token);
    if (empty($result['success'])) {
        fail(isset($result['error']) ? $result['error'] : 'Provisioning failed.');
    }

    // Step 2: Save token + all provisioned SIP credentials to config in one write
    $pdata = $result['data'];
    $cfg = qualid_load_config();
    $cfg['token']        = $token;
    $cfg['trunk_user']   = isset($pdata['trunk_username']) ? $pdata['trunk_username'] : '';
    $cfg['trunk_pass']   = isset($pdata['trunk_password']) ? $pdata['trunk_password'] : '';
    $cfg['sip_domain']   = isset($pdata['sip_domain'])   ? $pdata['sip_domain']   : '';
    $cfg['user_name']    = isset($pdata['user_name'])    ? $pdata['user_name']    : (isset($pdata['name']) ? $pdata['name'] : '');
    $cfg['company_name'] = isset($pdata['company_name']) ? $pdata['company_name'] : '';
    $cfg['connected']    = '1';
    $cfg['connected_at'] = date('Y-m-d H:i:s');
    if (!empty($pdata['turn_server'])) { $cfg['turn_server'] = $pdata['turn_server']; }
    if (!empty($pdata['turn_domain'])) { $cfg['turn_domain'] = $pdata['turn_domain']; }
    if (!empty($pdata['turn_secret'])) { $cfg['turn_secret'] = $pdata['turn_secret']; }
    qualid_save_config($cfg);

    // Step 3: Write Asterisk PJSIP config + RTP/TURN config + dialplan
    if (!empty($cfg['trunk_user']) && !empty($cfg['trunk_pass'])) {
        qualid_write_asterisk_config($cfg);
    }

    // Step 3b: Deploy AGI scripts + register AGI secret with cloud
    // The IVR AGI script uses this secret to authenticate requests to the API.
    $agi_secret = qualid_generate_agi_secret();
    qualid_set('agi_secret', $agi_secret);
    qualid_write_agi_files($agi_secret);
    qualid_register_agi_secret($token, $agi_secret);

    // Step 4: First sync — pull extensions + queues from cloud
    qualid_sync_extensions($token);
    qualid_sync_queues($token);
    qualid_send_heartbeat($token);

    // Step 5: Enable systemd timers (requires sudo rule — see install.sh)
    // exec() may be disabled in hardened environments — guard every call.
    if (function_exists('exec')) {
        exec('sudo /bin/systemctl enable --now qualid-sync.timer 2>/dev/null');
        exec('sudo /bin/systemctl enable --now qualid-updater.timer 2>/dev/null');
    }

    // Step 6: Reload Asterisk
    // The asterisk.ctl socket is root-owned; use sudo so www-data can reach it.
    // The sudoers rule (installed by install.sh) grants www-data NOPASSWD access.
    if (function_exists('exec')) {
        exec('sudo /usr/sbin/asterisk -rx "dialplan reload" 2>/dev/null');
        exec('sudo /usr/sbin/asterisk -rx "module reload res_pjsip.so" 2>/dev/null');
        exec('sudo /usr/sbin/asterisk -rx "module reload res_rtp_asterisk.so" 2>/dev/null');
        exec('sudo /usr/sbin/asterisk -rx "module reload app_queue.so" 2>/dev/null');
    }

    // Step 7: Write activated flag — wizard will not run again
    file_put_contents(WIZARD_ACTIVATED_FLAG, date('Y-m-d H:i:s') . "\n");

    // Step 8: Swap nginx from wizard → active static page, then reload
    // www-data cannot write /etc/nginx/sites-available/ directly;
    // the sudoers rule grants exactly this cp command as root.
    if (function_exists('exec')) {
        exec('sudo /bin/cp /usr/share/qualid/setup-wizard/nginx-active.conf /etc/nginx/sites-available/qualid 2>/dev/null');
        exec('sudo /bin/systemctl reload nginx 2>/dev/null');
    }

    ok([
        'name'         => qualid_get('user_name',    ''),
        'company_name' => qualid_get('company_name', ''),
    ]);
}

fail('Unknown action.', 400);
