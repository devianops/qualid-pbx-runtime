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

    // Ensure this device has a stable UUID before any API calls are made.
    // On first activation this generates and persists the UUID to config.json.
    qualid_get_device_uuid();

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

// ---------------------------------------------------------------------------
// get_device_info — returns device uuid + user-set display name (GET-safe)
// ---------------------------------------------------------------------------
if ($action === 'get_device_info') {
    ok([
        'display_name' => qualid_get('display_name', ''),
        'company_name' => qualid_get('company_name', ''),
        'hostname'     => gethostname(),
    ]);
}

// ---------------------------------------------------------------------------
// set_device_name — save display name locally + sync to cloud
// ---------------------------------------------------------------------------
if ($action === 'set_device_name') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
    $name = trim($_POST['display_name'] ?? '');
    if (!$name) fail('display_name is required.');
    if (mb_strlen($name) > 100) fail('Name too long (max 100 characters).');

    qualid_set('display_name', $name);

    // Best-effort sync to cloud — non-fatal if offline
    $token = qualid_get('token', '');
    if ($token) {
        qualid_curl_post(
            QUALID_MAIN_API . '/pbx/device-name',
            ['device_uuid' => qualid_get_device_uuid(), 'display_name' => $name],
            ['Authorization: Bearer ' . $token]
        );
    }

    ok(['display_name' => $name]);
}

// ---------------------------------------------------------------------------
// diagnose — system health checklist (GET-safe, available post-activation)
// ---------------------------------------------------------------------------
if ($action === 'diagnose') {
    $checks = [];

    function chk(string $id, string $label, string $status, string $detail): array {
        return compact('id', 'label', 'status', 'detail');
    }

    function ast(string $cmd): string {
        return trim((string) shell_exec('sudo /usr/sbin/asterisk -rx ' . escapeshellarg($cmd) . ' 2>/dev/null'));
    }

    function svc(string $unit): string {
        return trim((string) shell_exec('/usr/bin/systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null'));
    }

    // 1. Config file
    $configFile = '/etc/qualid/config.json';
    $config = file_exists($configFile) ? (json_decode((string)file_get_contents($configFile), true) ?: []) : [];
    $checks[] = file_exists($configFile)
        ? chk('config', 'Config file present', 'ok', $configFile)
        : chk('config', 'Config file present', 'fail', 'Missing — PBX may not be provisioned');

    // 2. Asterisk running
    $asteriskOut = ast('core show version');
    $checks[] = (strpos($asteriskOut, 'Asterisk') !== false)
        ? chk('asterisk', 'Asterisk running', 'ok', strtok($asteriskOut, "\n"))
        : chk('asterisk', 'Asterisk running', 'fail', 'Not responding — may need: systemctl restart asterisk');

    // 3. Sync timer (every 1 min)
    $syncState = svc('qualid-sync.timer');
    $checks[] = chk('sync_timer', 'Sync timer (every 1 min)', $syncState === 'active' ? 'ok' : 'fail',
        $syncState === 'active' ? 'active' : 'inactive — run: systemctl enable --now qualid-sync.timer');

    // 4. Updater timer (hourly)
    $updaterState = svc('qualid-updater.timer');
    $checks[] = chk('updater_timer', 'Updater timer (hourly)', $updaterState === 'active' ? 'ok' : 'warn',
        $updaterState === 'active' ? 'active' : 'inactive — run: systemctl enable --now qualid-updater.timer');

    // 5. PJSIP extensions config written
    $pjsipConf = '/etc/asterisk/pjsip_qualid_extensions.conf';
    if (file_exists($pjsipConf)) {
        preg_match_all('/^type=endpoint/m', (string)file_get_contents($pjsipConf), $m);
        $n = count($m[0]);
        $checks[] = chk('pjsip_conf', 'PJSIP extensions config', $n > 0 ? 'ok' : 'warn',
            $n . ' local endpoint(s) written' . ($n === 0 ? ' — no local extensions synced yet' : ''));
    } else {
        $checks[] = chk('pjsip_conf', 'PJSIP extensions config', 'warn',
            'Not written yet — no local extensions or sync not run');
    }

    // 6. Queue config written
    $queueConf = '/etc/asterisk/queues_qualid.conf';
    if (file_exists($queueConf)) {
        preg_match_all('/^\[[0-9]+\]/m', (string)file_get_contents($queueConf), $m);
        $n = count($m[0]);
        $checks[] = chk('queue_conf', 'Queue config', $n > 0 ? 'ok' : 'warn',
            $n . ' queue(s) written' . ($n === 0 ? ' — no queues configured yet' : ''));
    } else {
        $checks[] = chk('queue_conf', 'Queue config', 'warn', 'Not written yet — no queues configured');
    }

    // 7. PJSIP endpoints loaded in Asterisk
    $epOut = ast('pjsip show endpoints');
    if (preg_match('/Objects found:\s*(\d+)/i', $epOut, $m)) {
        $n = (int)$m[1];
        $checks[] = chk('endpoints', 'PJSIP endpoints loaded', $n > 0 ? 'ok' : 'warn',
            $n . ' endpoint(s) in Asterisk (includes trunk)');
    } else {
        $checks[] = chk('endpoints', 'PJSIP endpoints loaded', 'fail', 'Could not query Asterisk');
    }

    // 8. Cloud VPS trunk reachable
    $trunkOut = ast('pjsip show aors');
    if (strpos($trunkOut, 'Avail') !== false) {
        preg_match('/(\d+\.\d+)\s*$/', preg_replace('/\s+/', ' ', $trunkOut), $m);
        $rtt = isset($m[1]) ? ' — RTT ' . $m[1] . ' ms' : '';
        $checks[] = chk('trunk', 'Cloud VPS trunk', 'ok', 'Available' . $rtt);
    } elseif (strpos($trunkOut, 'QualidRemote') !== false) {
        $checks[] = chk('trunk', 'Cloud VPS trunk', 'fail', 'Registered but not responding — check VPS/Kamailio');
    } else {
        $checks[] = chk('trunk', 'Cloud VPS trunk', 'fail', 'Not found — check pjsip_qualid.conf');
    }

    // 9. Queue members in Asterisk
    $queueOut = ast('queue show');
    $memberCount = preg_match_all('/^\s+(PJSIP|SIP|Local)\//m', $queueOut);
    $hasQueues = strpos($queueOut, 'has') !== false;
    $checks[] = $hasQueues
        ? chk('queue_members', 'Queue members in Asterisk', $memberCount > 0 ? 'ok' : 'warn',
            $memberCount . ' member(s) across all queues')
        : chk('queue_members', 'Queue members in Asterisk', 'warn', 'No queues loaded — check queues_qualid.conf');

    // 10. FXO / SIP Gateways
    $gatewayConf = '/etc/asterisk/pjsip_qualid_gateways.conf';
    if (!file_exists($gatewayConf)) {
        $checks[] = chk('gateways', 'FXO / SIP Gateways', 'ok', 'No gateways configured yet');
    } else {
        preg_match_all('/^type=endpoint$/m', (string)file_get_contents($gatewayConf), $gm);
        $gwTotal = count($gm[0]);
        if ($gwTotal === 0) {
            $checks[] = chk('gateways', 'FXO / SIP Gateways', 'ok', 'No gateways configured yet');
        } else {
            $contactsOut = ast('pjsip show contacts');
            // Contacts for gateway AORs appear as "{username}/sip:..." in pjsip show contacts
            // Extract gateway usernames from the conf to match against contacts output
            preg_match_all('/^; --- (.+?) ---$/m', (string)file_get_contents($gatewayConf), $usernames);
            $gwRegistered = 0;
            foreach ($usernames[1] as $gwUser) {
                if (preg_match('/' . preg_quote($gwUser, '/') . '\//i', $contactsOut)) {
                    $gwRegistered++;
                }
            }
            $gwStatus = ($gwRegistered === $gwTotal) ? 'ok' : 'warn';
            $detail = $gwRegistered . '/' . $gwTotal . ' registered';
            if ($gwRegistered < $gwTotal) {
                $detail .= ' — check SIP credentials on unregistered device(s)';
            }
            $checks[] = chk('gateways', 'FXO / SIP Gateways', $gwStatus, $detail);
        }
    }

    // 11. Version vs latest release
    $installed = trim((string)@file_get_contents('/etc/qualid/version')) ?: 'unknown';
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: qualid-pbx\r\n", 'timeout' => 5]]);
    $relJson = @file_get_contents('https://api.github.com/repos/devianops/qualid-pbx-runtime/releases/latest', false, $ctx);
    $latest = 'unknown';
    if ($relJson) { $rel = json_decode($relJson, true); $latest = ltrim($rel['tag_name'] ?? 'unknown', 'v'); }
    $upToDate = ($installed !== 'unknown' && $installed === $latest);
    $checks[] = chk('version', 'Runtime version',
        $latest === 'unknown' ? 'warn' : ($upToDate ? 'ok' : 'warn'),
        'Installed: v' . $installed . ' — Latest: v' . $latest . ($upToDate ? '' : ' (update available — will auto-apply within 1 hour)'));

    ok(['checks' => $checks]);
}

// ---------------------------------------------------------------------------
// check_update — compare installed version with latest GitHub release
// ---------------------------------------------------------------------------
if ($action === 'check_update') {
    $installed = trim((string)@file_get_contents('/etc/qualid/version')) ?: 'unknown';
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: qualid-pbx\r\n", 'timeout' => 6]]);
    $relJson = @file_get_contents('https://api.github.com/repos/devianops/qualid-pbx-runtime/releases/latest', false, $ctx);
    if (!$relJson) {
        ok(['installed' => $installed, 'latest' => null, 'has_update' => false, 'offline' => true]);
    }
    $rel       = json_decode($relJson, true);
    $latest    = ltrim($rel['tag_name'] ?? '', 'v');
    $hasUpdate = ($latest && $installed !== 'unknown' && version_compare($latest, $installed, '>'));
    ok(['installed' => $installed, 'latest' => $latest ?: null, 'has_update' => $hasUpdate]);
}

// ---------------------------------------------------------------------------
// run_update — trigger qualid-updater.service (runs update.sh as root)
// ---------------------------------------------------------------------------
if ($action === 'run_update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
    // Write a start-time marker so update_status can distinguish "not started"
    // from "just finished". update.sh clears /etc/qualid/update.log on its own.
    file_put_contents('/tmp/qualid-update-started', time() . "\n");
    // systemctl start is blocking for a oneshot service, so background it.
    // Note: service stdout goes to journal; actual progress is in /etc/qualid/update.log
    // written by update.sh itself (PrivateTmp isolates the service's /tmp from ours).
    shell_exec('sudo /bin/systemctl start qualid-updater.service 2>/dev/null &');
    ok(['started' => true]);
}

// ---------------------------------------------------------------------------
// update_status — is the updater running? what version are we on?
// ---------------------------------------------------------------------------
if ($action === 'update_status') {
    $startedAt = (int)trim((string)@file_get_contents('/tmp/qualid-update-started'));
    $state     = trim((string)shell_exec('/usr/bin/systemctl is-active qualid-updater.service 2>/dev/null'));
    $version   = trim((string)@file_get_contents('/etc/qualid/version')) ?: 'unknown';

    // Grace period: service may still be spinning up for the first 15 s after
    // run_update was called — treat it as "running" to avoid a false "done".
    $running = ($state === 'activating')
            || ($startedAt > 0 && $state === 'inactive' && (time() - $startedAt) < 15);
    $failed  = ($state === 'failed');
    $done    = ($startedAt > 0 && !$running && !$failed);

    // update.sh writes its log to /etc/qualid/update.log (writable by root, readable
    // by www-data since /etc/qualid is chowned to www-data during install).
    $log      = (string)@file_get_contents('/etc/qualid/update.log');
    $logLines = $log
        ? array_values(array_filter(array_slice(explode("\n", trim($log)), -20)))
        : [];

    ok([
        'running'    => $running,
        'done'       => $done,
        'failed'     => $failed,
        'state'      => $state,
        'version'    => $version,
        'log'        => $logLines,
        'started_at' => $startedAt,
    ]);
}

fail('Unknown action.', 400);
