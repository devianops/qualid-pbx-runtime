<?php
/**
 * Quali-D PBX OS — Sync Layer Helper Functions
 * Standalone PHP — no FreePBX dependency.
 * Handles config storage, QUALI-D API calls, and Asterisk config generation.
 */

// Standalone guard — sync.php and deferred_rtp.php define this before including us.
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define('QUALID_MAIN_API',      'https://api.quali-d.com');
define('QUALID_RELAY_URL',     'https://qualidapi1.1215515.xyz');
define('QUALID_CLOUD_SERVER',  'qualidsip1.1215515.xyz');
define('QUALID_CLOUD_PORT',    443);
define('QUALID_CLOUD_IP',      '13.140.143.85');  // VPS direct IP — used for SIP/TCP trunk on port 443

define('QUALID_TRUNK_NAME',    'QualidRemote');
define('QUALID_CONTEXT',       'qualid-remote-agents');
define('QUALID_IVR_CONF',      '/etc/asterisk/qualid_ivr.conf');
define('QUALID_AGI_BIN',       '/var/lib/asterisk/agi-bin/qualid_ivr.php');
define('QUALID_AGI_SRC',       dirname(__FILE__) . '/agi/qualid_ivr.php');
define('QUALID_OB_AGI_BIN',    '/var/lib/asterisk/agi-bin/qualid_outbound_ivr.php');
define('QUALID_OB_AGI_SRC',    dirname(__FILE__) . '/agi/qualid_outbound_ivr.php');
define('QUALID_GITHUB_REPO',   'devianops/qualid-pbx-runtime');   // Runtime update repo (public)
define('QUALID_MODULE_DIR',    '/etc/qualid');                    // config lives here on PBX OS
define('QUALID_DEVICE_TYPE',   'qualid_pbx_os');                  // sent in login + provision calls

// ---------------------------------------------------------------------------
// Config storage (JSON file — no FreePBX API dependency)
// ---------------------------------------------------------------------------

function qualid_config_path() {
    return '/etc/qualid/config.json';
}

function qualid_load_config() {
    $path = qualid_config_path();
    if (!file_exists($path)) { return []; }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function qualid_save_config($cfg) {
    file_put_contents(qualid_config_path(), json_encode($cfg));
}

function qualid_get($key, $default = null) {
    $cfg = qualid_load_config();
    return isset($cfg[$key]) ? $cfg[$key] : $default;
}

function qualid_set($key, $value) {
    $cfg = qualid_load_config();
    $cfg[$key] = $value;
    qualid_save_config($cfg);
}

function qualid_get_all() {
    return [
        'token'              => qualid_get('token',              ''),
        'user_name'          => qualid_get('user_name',          ''),
        'company_name'       => qualid_get('company_name',       ''),
        'sip_domain'         => qualid_get('sip_domain',         ''),
        'trunk_user'         => qualid_get('trunk_user',         ''),
        'trunk_pass'         => qualid_get('trunk_pass',         ''),
        'turn_server'        => qualid_get('turn_server',        ''),
        'connected'          => qualid_get('connected',          '0'),
        'connected_at'       => qualid_get('connected_at',       ''),
        'last_error'         => qualid_get('last_error',         ''),
        'agi_secret'         => qualid_get('agi_secret',         ''),
        'device_uuid'        => qualid_get('device_uuid',        ''),
    ];
}

/**
 * Return this device's stable UUID, generating one on first call.
 * Persisted in config.json — never changes after generation.
 */
function qualid_get_device_uuid() {
    $uuid = qualid_get('device_uuid', '');
    if (empty($uuid)) {
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        qualid_set('device_uuid', $uuid);
    }
    return $uuid;
}

// ---------------------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------------------

/**
 * Resolve the relay hostname to an IP address, bypassing the system DNS.
 * Strategy (automatic, no CLI required):
 *   1. PHP's built-in gethostbyname() — works on most systems.
 * The API domain (qualidapi1.1215515.xyz) is behind Cloudflare CDN and must
 * NOT be DNS-resolved for SIP signalling — Cloudflare terminates HTTPS but does
 * not relay raw TCP/TLS SIP.  The direct VPS IP is already the QUALID_CLOUD_IP
 * constant, so return it immediately.
 */
function qualid_resolve_relay_ip() {
    return QUALID_CLOUD_IP;
}

/**
 * Build a CURLOPT_RESOLVE list that pins our .xyz hostnames to their real IP,
 * bypassing whatever system DNS is configured on this machine.
 *
 * Only qualidsip1 is pinned here (used by Asterisk trunk, connects directly to
 * the VPS on port 443 where Kamailio listens for TLS/SIP).
 *
 * qualidapi1 is intentionally NOT pinned — it routes through the Cloudflare
 * tunnel to Node.js:3000 and is NOT directly accessible on the VPS IP:443.
 * Pinning it to the VPS IP would cause API calls to fail when system DNS works
 * but falls back to the VPS IP as last resort.
 */
function qualid_curl_resolve_list() {
    $ip = qualid_resolve_relay_ip();
    return array(
        'qualidsip1.1215515.xyz:443:' . $ip,
    );
}

function qualid_curl_get($url, $extra_headers = array()) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => array_merge(array('Accept: application/json'), $extra_headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE        => qualid_curl_resolve_list(),
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('_error' => $err);
    $data = json_decode($raw, true);
    return ($data !== null) ? $data : array('_http_error' => $code);
}

function qualid_curl_post($url, $payload, $extra_headers = array()) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array_merge(array('Content-Type: application/json'), $extra_headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE        => qualid_curl_resolve_list(),
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('_error' => $err);
    $data = json_decode($raw, true);
    return ($data !== null) ? $data : array('_http_error' => $code);
}

// ---------------------------------------------------------------------------
// QUALI-D Main API — Authentication
// ---------------------------------------------------------------------------

/**
 * Step 1: Login with phone + password + Cloudflare Turnstile token.
 * Country code is hardcoded to "2" (Egypt only).
 *
 * Returns one of:
 *   ['success'=>true, 'totp_required'=>true, 'temp_token'=>'...']
 *   ['success'=>true, 'token'=>'...', 'name'=>'...', 'company_name'=>'...']
 *   ['success'=>false, 'error'=>'...']
 */
function qualid_login($phone, $password, $turnstile_token) {
    $data = qualid_curl_post(QUALID_MAIN_API . '/login', [
        'country_code'          => '2',
        'phone'                 => $phone,
        'password'              => $password,
        'platform'              => 'web',
        'device_fingerprint'    => QUALID_DEVICE_TYPE,
        'device_name'           => 'Quali-D PBX OS',
        'cf_turnstile_response' => $turnstile_token,
    ]);

    if (isset($data['_error'])) {
        return ['success' => false, 'error' => 'Network error: ' . $data['_error']];
    }
    if (!(isset($data['success']) ? $data['success'] : false)) {
        $msg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? $data['error'] : 'Login failed');
        return ['success' => false, 'error' => $msg];
    }
    return $data;
}

/**
 * Step 2: Verify TOTP code (only needed when totp_required was true).
 * Returns: ['success'=>true, 'token'=>'...', 'name'=>'...', 'company_name'=>'...']
 *       or ['success'=>false, 'error'=>'...']
 */
function qualid_verify_totp($temp_token, $code) {
    $data = qualid_curl_post(QUALID_MAIN_API . '/login/verify-totp', [
        'temp_token'          => $temp_token,
        'code'                => $code,
        'platform'            => 'web',
        'device_fingerprint'  => QUALID_DEVICE_TYPE,
        'device_name'         => 'Quali-D PBX OS',
    ]);

    if (isset($data['_error'])) {
        return ['success' => false, 'error' => 'Network error: ' . $data['_error']];
    }
    if (!(isset($data['success']) ? $data['success'] : false)) {
        $msg = isset($data['message']) ? $data['message'] : 'TOTP verification failed';
        return ['success' => false, 'error' => $msg];
    }
    return $data;
}

// ---------------------------------------------------------------------------
// QUALI-D Relay Server — Trunk Provisioning
// ---------------------------------------------------------------------------

/**
 * Provision the FreePBX SIP trunk via the relay server.
 * Uses qualid_curl_resolve_list() to bypass system DNS for the .xyz hostname.
 */
function qualid_provision_trunk($token) {
    $ch = curl_init(QUALID_RELAY_URL . '/api/pbx/provision');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(array(
            'bearer_token' => $token,
            'pbx_label'    => gethostname(),
            'device_type'  => QUALID_DEVICE_TYPE,
            'pbx_version'  => @file_get_contents('/etc/qualid/version') ?: '0.1.0',
        )),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE        => qualid_curl_resolve_list(),
    ));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return array('success' => false, 'error' => 'Relay server unreachable: ' . $err);
    }

    $data = json_decode($raw, true);
    if (!$data) {
        return array('success' => false, 'error' => 'Relay server returned an unexpected response.');
    }
    if (!(isset($data['success']) && $data['success'])) {
        return array('success' => false, 'error' => isset($data['error']) ? $data['error'] : 'Trunk provisioning failed');
    }
    return array('success' => true, 'data' => $data);
}


// ---------------------------------------------------------------------------
// Asterisk config generation
// ---------------------------------------------------------------------------

function qualid_write_asterisk_config($config) {
    qualid_write_pjsip($config);
    qualid_write_dialplan($config);
    qualid_write_outbound_dialplan();
    qualid_write_rtp_conf();
    // Seed an empty extensions file on activation — will be populated by
    // qualid_sync_extensions() once the cloud returns the extension list.
    if (!file_exists('/etc/asterisk/pjsip_qualid_extensions.conf')) {
        qualid_write_pjsip_extensions([]);
    }
    if (function_exists('needreload')) { needreload(); }
}

/**
 * Write the [qualid-outbound-ivr] Asterisk dialplan context used by outbound
 * campaign call files.  Writes to extensions_qualid_outbound.conf and ensures
 * it is #include'd in extensions_custom.conf.  Safe to call multiple times.
 *
 * Wait(3) gives the called party time to put the phone to their ear before
 * the IVR audio begins.
 */
function qualid_write_outbound_dialplan() {
    $conf = <<<'DIALPLAN'
; QUALI-D Outbound IVR - auto-generated. Do not edit manually.
[qualid-outbound-ivr]
; AMD() waits for actual voice on the line instead of relying on static timing.
; FXO gateways send 200 OK on PSTN polarity reversal (before customer picks up).
; AMD listens for speech ("hello") before starting the IVR flow.
; Also detects and skips answering machines.
exten => s,1,NoOp(Outbound IVR - contact=${CONTACT_ID} campaign=${CAMPAIGN_ID})
 same =>  n,Answer()
 same =>  n,AMD(30000,1500,500,60000,100,256,3,0)
 same =>  n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?end,1)
 same =>  n,AGI(qualid_outbound_ivr.php,${CONTACT_ID},${CAMPAIGN_ID})
 same =>  n,Hangup()
exten => end,1,Hangup()
DIALPLAN;

    $path = '/etc/asterisk/extensions_qualid_outbound.conf';
    file_put_contents($path, $conf . "\n");
    $ext_parent = file_exists('/etc/asterisk/extensions_custom.conf')
        ? '/etc/asterisk/extensions_custom.conf'
        : '/etc/asterisk/extensions.conf';
    qualid_ensure_include($ext_parent, 'extensions_qualid_outbound.conf');

    // Reload dialplan live so the context is available immediately
    @exec('/usr/sbin/asterisk -rx "dialplan reload" > /dev/null 2>&1');
}

function qualid_write_pjsip($cfg) {
    $trunk_user = $cfg['trunk_user'];
    $trunk_pass = $cfg['trunk_pass'];
    $cloud_srv  = QUALID_CLOUD_SERVER;
    $cloud_ip   = QUALID_CLOUD_IP;
    $trunk_port = QUALID_CLOUD_PORT;   // 443 — SIP/TCP on port 443 bypasses Egyptian ISP blocking
    $trunk_name = QUALID_TRUNK_NAME;

    // FreePBX→Kamailio uses SIP/TLS on port 443 directly to the VPS IP.
    // Plain TCP on port 443 is blocked by Egyptian ISP DPI (detects INVITE keyword).
    // TLS encrypts the payload so DPI sees only an HTTPS-looking stream.
    // verify_server=no / verify_client=no because Kamailio uses a self-signed cert.
    $conf = <<<CONF
; =============================================================================
; Quali-D Connect — PJSIP Trunk Configuration
; Auto-generated by the qualid_remote FreePBX module. DO NOT EDIT manually.
; To reconfigure, use the Quali-D Connect admin page.
; =============================================================================

; UDP transport — required for local SIP device registrations (FXO gateways, extensions)
[qualid_lan_udp_transport]
type=transport
protocol=udp
bind=0.0.0.0:5060

[{$trunk_name}_tls_transport]
type=transport
protocol=tls
bind=0.0.0.0
method=tlsv1_2
verify_server=no
verify_client=no

[{$trunk_name}_auth]
type=auth
auth_type=userpass
username={$trunk_user}
password={$trunk_pass}

[{$trunk_name}_registration]
type=registration
transport={$trunk_name}_tls_transport
outbound_auth={$trunk_name}_auth
server_uri=sip:{$cloud_ip}:{$trunk_port};transport=tls
client_uri=sip:{$trunk_user}@{$cloud_srv}:{$trunk_port};transport=tls
contact_user={$trunk_user}
retry_interval=60
expiration=3600

[{$trunk_name}_aor]
type=aor
contact=sip:{$cloud_ip}:{$trunk_port};transport=tls
qualify_frequency=60

[{$trunk_name}_endpoint]
type=endpoint
transport={$trunk_name}_tls_transport
outbound_auth={$trunk_name}_auth
aors={$trunk_name}_aor
context={$trunk_name}-inbound
disallow=all
allow=opus
allow=ulaw
allow=alaw
webrtc=yes
dtls_auto_generate_cert=yes
ice_support=yes
media_use_received_transport=yes
rtcp_mux=yes
force_rport=yes
rewrite_contact=yes
direct_media=no
from_user={$trunk_user}
from_domain={$cloud_srv}
CONF;

    $path = '/etc/asterisk/pjsip_qualid.conf';
    file_put_contents($path, $conf);
    // Always include directly in pjsip.conf — we run on vanilla Asterisk (no FreePBX GUI).
    // pjsip_custom_post.conf is not automatically included by the vanilla pjsip.conf.
    qualid_ensure_include('/etc/asterisk/pjsip.conf', 'pjsip_qualid.conf');
    // Include per-extension PJSIP configs (written by qualid_write_pjsip_extensions).
    qualid_ensure_include($path, 'pjsip_qualid_extensions.conf');

    // Pin the SIP hostname → VPS IP in /etc/hosts so Asterisk resolves it
    // correctly and sends the right TLS SNI even when Egyptian ISP DNS
    // blocks .xyz TLD resolution.
    qualid_pin_hosts_entry(QUALID_CLOUD_IP, QUALID_CLOUD_SERVER);
}

/**
 * Write (or overwrite) /etc/asterisk/pjsip_qualid_extensions.conf with a
 * PJSIP endpoint, AOR, and auth section for each extension in $extensions.
 *
 * Called by qualid_sync_extensions() every time the cloud returns a fresh
 * extension list, and by qualid_write_asterisk_config() during activation.
 *
 * Extensions without a 'secret' field get an endpoint + AOR only (no auth).
 * This allows manual secret configuration while still creating the endpoint.
 *
 * The file is included by pjsip_qualid.conf via #include added by
 * qualid_ensure_include() in qualid_write_pjsip().
 */
function qualid_write_pjsip_extensions($extensions) {
    $conf = "; Quali-D Extensions — auto-generated by sync. Do not edit manually.\n\n";

    if (!empty($extensions)) {
        foreach ($extensions as $ext) {
            $exten       = isset($ext['extension'])    ? trim($ext['extension'])    : '';
            $name        = isset($ext['display_name']) ? trim($ext['display_name']) : $exten;
            $type        = isset($ext['type'])         ? strtolower($ext['type'])   : 'pjsip';
            $secret      = isset($ext['secret'])       ? $ext['secret']             : '';
            $routingType = isset($ext['routing_type']) ? $ext['routing_type']       : 'local';

            if (!$exten || $type !== 'pjsip') continue;

            $callerid  = $name . ' <' . $exten . '>';
            $isCloud   = ($routingType === 'remote');

            if ($secret !== '') {
                $conf .= "[{$exten}-auth]\n";
                $conf .= "type=auth\n";
                $conf .= "auth_type=userpass\n";
                $conf .= "username={$exten}\n";
                $conf .= "password={$secret}\n\n";
            }

            $conf .= "[{$exten}]\n";
            $conf .= "type=aor\n";
            $conf .= "max_contacts=5\n";
            $conf .= "remove_existing=yes\n";
            $conf .= "qualify_frequency=60\n\n";

            $conf .= "[{$exten}]\n";
            $conf .= "type=endpoint\n";
            $conf .= "context=qualid-agents-local\n";
            $conf .= "disallow=all\n";
            $conf .= "allow=opus\n";
            $conf .= "allow=ulaw\n";
            $conf .= "allow=alaw\n";

            if ($isCloud) {
                // CLOUD mode — softphone connects via relay; use WebRTC/DTLS
                $conf .= "webrtc=yes\n";
                $conf .= "dtls_auto_generate_cert=yes\n";
                $conf .= "ice_support=yes\n";
                $conf .= "media_use_received_transport=yes\n";
                $conf .= "rtcp_mux=yes\n";
            } else {
                // LAN mode — softphone is on the local network; plain SIP/RTP
                $conf .= "force_rport=yes\n";
                $conf .= "rtp_symmetric=yes\n";
            }

            $conf .= "aors={$exten}\n";
            if ($secret !== '') {
                $conf .= "auth={$exten}-auth\n";
            }
            $conf .= "direct_media=no\n";
            $conf .= "callerid={$callerid}\n\n";
        }
    }

    file_put_contents('/etc/asterisk/pjsip_qualid_extensions.conf', $conf);
}

/**
 * Ensure a socat UDP-over-TCP tunnel is running so Asterisk's STUN/TURN
 * traffic can reach the coturn server on the VPS even when the ISP blocks
 * ALL outbound UDP.
 *
 * Tunnel chain:
 *   Asterisk → UDP:127.0.0.1:3479 → socat(FreePBX) → TCP:VPS:80 → socat(VPS) → UDP:coturn:3478
 *
 * Uses a simple background process checked via pgrep — no systemd required
 * (avoids permission issues when PHP runs as the asterisk user).
 * The cron job (sync.php, every 5 min) keeps it alive if it dies.
 * Also writes a @reboot cron entry so the tunnel starts on boot.
 */
function qualid_setup_stun_tunnel() {
    if (!file_exists('/usr/bin/socat')) {
        return false;
    }

    $cloud_ip = QUALID_CLOUD_IP;

    // Check if socat tunnel is already running on port 3479
    exec('pgrep -f "socat.*UDP4-LISTEN:3479" 2>/dev/null', $pids);
    if (!empty($pids)) {
        return true; // already running
    }

    // Start socat as a fully detached background process.
    // setsid creates a new session so the process survives when fwconsole
    // (Apply Config) exits and kills its process group.
    exec("setsid /usr/bin/socat UDP4-LISTEN:3479,reuseaddr,fork TCP4:{$cloud_ip}:80,nodelay </dev/null > /dev/null 2>&1 &");

    // Reboot persistence is handled by the @reboot entry in qualid_write_cron()
    return true;
}

/**
 * Append TURN/ICE settings to /etc/asterisk/rtp_additional.conf so Asterisk's
 * native WebRTC endpoint can traverse NAT via the coturn relay on the VPS.
 *
 * NOTE: rtp_custom.conf is broken in FreePBX 17 / Asterisk 22 (the #include
 * is present in rtp.conf but the file contents are silently ignored). Writing
 * to rtp_additional.conf works because it's the first #include in rtp.conf.
 *
 * STUN/TURN addresses point to 127.0.0.1:3479 — the local socat tunnel
 * endpoint. The tunnel converts UDP→TCP so it works even when the ISP blocks
 * all outbound UDP (Egyptian fixed-line).
 *
 * Credentials are ephemeral HMAC-SHA1 (same scheme as coturn use-auth-secret):
 *   username = "<expiry_timestamp>:qualid_asterisk"
 *   password = base64(HMAC-SHA1(turn_secret, username))
 * TTL = 24 hours -- the sync cron (every 5 min) refreshes well before expiry.
 */
function qualid_write_rtp_conf() {
    $turn_domain = qualid_get('turn_domain', '');
    $turn_secret = qualid_get('turn_secret', '');
    if (empty($turn_domain) || empty($turn_secret)) {
        return; // TURN not provisioned yet -- skip
    }

    // Ensure the UDP-over-TCP tunnel is running before configuring STUN/TURN
    qualid_setup_stun_tunnel();

    // Ephemeral TURN credentials (24h TTL)
    $expiry   = time() + 86400;
    $username = $expiry . ':qualid_asterisk';
    $password = base64_encode(hash_hmac('sha1', $username, $turn_secret, true));

    // Point to the local socat tunnel endpoint (UDP:3479 → TCP:VPS:80 → coturn)
    // instead of the VPS IP directly, because all outbound UDP is blocked.
    $stun_turn_addr = '127.0.0.1:3479';

    // Write to rtp_custom.conf, NOT rtp_additional.conf.
    //
    // rtp_additional.conf is regenerated from scratch on every FreePBX
    // Apply Config, wiping anything we write there.  rtp_custom.conf is
    // the standard FreePBX "user customisations" file — it is NEVER
    // regenerated and survives every Apply Config cycle.
    //
    // We confirmed rtp_custom.conf is loaded by Asterisk (stale settings
    // there previously overrode our config, so it must be included).
    //
    // [general](+) is the standard Asterisk syntax for appending to an
    // already-defined section across #include files.
    $turn_block = <<<CONF
; -- qualid-turn-start --
[general](+)
icesupport=yes
turnaddr={$stun_turn_addr}
turnusername={$username}
turnpassword={$password}
; -- qualid-turn-end --
CONF;

    $path    = '/etc/asterisk/rtp_custom.conf';
    $content = @file_get_contents($path);
    if ($content === false) { $content = ''; }  // create if absent

    // Remove any existing qualid TURN block and bare stale STUN/TURN lines
    $content = preg_replace('/; -- qualid-turn-start --.*?; -- qualid-turn-end --\n?/s', '', $content);
    $content = preg_replace('/^(stunaddr|turnaddr|turnusername|turnpassword)=.*\n?/m', '', $content);

    $content = rtrim($content) . "\n" . $turn_block . "\n";
    file_put_contents($path, $content);

    // Also erase our old block from rtp_additional.conf if it's still there
    // from a previous module version (cleanup only — we no longer write there).
    $additional = '/etc/asterisk/rtp_additional.conf';
    $ac = @file_get_contents($additional);
    if ($ac !== false && strpos($ac, 'qualid-turn-start') !== false) {
        $ac = preg_replace('/; -- qualid-turn-start --.*?; -- qualid-turn-end --\n?/s', '', $ac);
        $ac = preg_replace('/^(stunaddr|turnaddr|turnusername|turnpassword)=.*\n?/m', '', $ac);
        @file_put_contents($additional, $ac);
    }

    // Clean stale STUN/TURN overrides from rtp.conf (do NOT touch rtp_custom.conf
    // here — we own it now and just wrote our block above).
    qualid_clean_rtp_overrides();

    // Pin TURN hostname in /etc/hosts as DNS safety net
    qualid_pin_hosts_entry(QUALID_CLOUD_IP, $turn_domain);

    // Reload the RTP module so Asterisk picks up the new TURN credentials
    exec('/usr/sbin/asterisk -rx "module reload res_rtp_asterisk.so" 2>/dev/null');
}

/**
 * Remove stale STUN/TURN settings from rtp.conf and rtp_custom.conf that
 * would override the correct settings we write to rtp_additional.conf.
 */
function qualid_clean_rtp_overrides() {
    // NOTE: We do NOT touch rtp_custom.conf here.  Our module now owns that
    // file for TURN settings (using [general](+) blocks that survive Apply Config).
    // Only clean rtp.conf which may still have stale manual overrides.

    // --- rtp.conf: remove any [general](+) block with stun/turn settings ---
    $rtp = '/etc/asterisk/rtp.conf';
    $r = @file_get_contents($rtp);
    if ($r !== false) {
        // Remove the entire appended [general](+) section that contains
        // stun/turn settings (added during manual debugging).
        $cleaned = preg_replace('/\[general\]\(\+\)\s*\n((?:(?:icesupport|stunaddr|turnaddr|turnusername|turnpassword)=.*\n?)*)/s', '', $r);
        // Also strip any standalone stun/turn lines outside a section header
        $cleaned = preg_replace('/^(stunaddr|turnaddr|turnusername|turnpassword)=.*\n?/m', '', $cleaned);
        if ($cleaned !== $r) {
            file_put_contents($rtp, $cleaned);
        }
    }
}

function qualid_write_dialplan($cfg) {
    $sip_domain = $cfg['sip_domain'];
    $trunk_name = QUALID_TRUNK_NAME;
    $context    = QUALID_CONTEXT;

    $conf = <<<CONF
; =============================================================================
; Quali-D Connect — Dialplan
; Auto-generated. DO NOT EDIT manually.
; =============================================================================

[{$trunk_name}-inbound]
exten => _.,1,NoOp(Quali-D Connect inbound call from \${CALLERID(all)})
 same => n,Set(CALLERID(name)=\${CALLERID(name)})
 same => n,Goto(qualid-ivr,\${EXTEN},1)

[{$context}]
exten => h,1,Hangup()
exten => _.,1,NoOp(Routing call to remote agent \${EXTEN})
 same => n,Dial(PJSIP/\${EXTEN}@{$trunk_name}_endpoint,60,rU)
 same => n,Hangup()

; ── Locally registered agents ────────────────────────────────────────────────
; Context for agents (3001, 3002…) that register directly to this Asterisk PBX.
; Outbound calls from local agents are sent through the Kamailio trunk so the
; VPS can apply its routing rules (PSTN, number rewriting, etc.).
[qualid-agents-local]
exten => _.,1,NoOp(Local agent outbound - \${EXTEN})
 same => n,Dial(PJSIP/\${EXTEN}@{$trunk_name}_endpoint,60,rU)
 same => n,Hangup()
exten => h,1,Hangup()

; ── Quali-D IVR ──────────────────────────────────────────────────────────────
; Route inbound calls through the Quali-D IVR flow engine.
; In FreePBX: Admin → Custom Destinations → add "qualid-ivr,<DID>,1"
; then set your Inbound Route destination to that Custom Destination.
; The DID passed as the extension becomes the trigger_number matched by the API.
[qualid-ivr]
exten => _.,1,NoOp(Quali-D IVR - DID:\${EXTEN} Raw CID:\${CALLERID(all)})
 same => n,Set(QUALID_PAI=\${PJSIP_HEADER(read,P-Asserted-Identity)})
 same => n,Set(QUALID_CID=\${FILTER(0-9+,\${CUT(QUALID_PAI,@,1)})})
 same => n,GotoIf(\$["\${QUALID_CID}" != ""]?override)
 same => n,Set(QUALID_PAI=\${PJSIP_HEADER(read,Remote-Party-ID)})
 same => n,Set(QUALID_CID=\${FILTER(0-9+,\${CUT(QUALID_PAI,@,1)})})
 same => n,GotoIf(\$["\${QUALID_CID}" != ""]?override)
 same => n,Set(QUALID_PAI=\${PJSIP_HEADER(read,X-ATA-CallerID)})
 same => n,Set(QUALID_CID=\${FILTER(0-9+,\${CUT(QUALID_PAI,@,1)})})
 same => n,GotoIf(\$["\${QUALID_CID}" = ""]?run)
 same => n(override),Set(CALLERID(num)=\${QUALID_CID})
 same => n(run),NoOp(Final CID: \${CALLERID(num)})
 same => n,AGI(qualid_ivr.php)
 same => n,Hangup()
exten => h,1,Hangup()
CONF;

    $path = '/etc/asterisk/extensions_qualid.conf';
    file_put_contents($path, $conf);
    $ext_parent = file_exists('/etc/asterisk/extensions_custom.conf')
        ? '/etc/asterisk/extensions_custom.conf'
        : '/etc/asterisk/extensions.conf';
    qualid_ensure_include($ext_parent, 'extensions_qualid.conf');
}

function qualid_ensure_include($target_file, $include_file) {
    $include_line = '#include ' . $include_file;
    if (!file_exists($target_file)) {
        // Create the file rather than silently skipping — vanilla Asterisk has
        // no pjsip_custom_post.conf / extensions_custom.conf out of the box.
        file_put_contents($target_file, '; Auto-created by Quali-D' . "\n" . $include_line . "\n");
        return;
    }
    $contents = file_get_contents($target_file);
    if (strpos($contents, $include_file) === false) {
        file_put_contents($target_file, $contents . "\n" . $include_line . "\n");
    }
}

/**
 * Upsert a single hostname → IP entry in /etc/hosts.
 * Removes any existing line for the same hostname first, then appends.
 * This pins the SIP server hostname to its VPS IP so Asterisk can resolve
 * it (and send the correct TLS SNI) even when system DNS blocks .xyz TLDs.
 */
function qualid_pin_hosts_entry($ip, $hostname) {
    $hosts = '/etc/hosts';
    if (!file_exists($hosts) || !is_writable($hosts)) return;
    $lines = file($hosts, FILE_IGNORE_NEW_LINES);
    // Remove any pre-existing entry for this hostname
    $lines = array_filter($lines, function($line) use ($hostname) {
        return !preg_match('/\s+' . preg_quote($hostname, '/') . '(\s|$)/', $line);
    });
    $lines[] = $ip . ' ' . $hostname . '  # qualid-remote';
    file_put_contents($hosts, implode("\n", $lines) . "\n");
}

function qualid_remove_asterisk_config() {
    $files = [
        '/etc/asterisk/pjsip_qualid.conf',
        '/etc/asterisk/extensions_qualid.conf',
    ];
    foreach ($files as $f) {
        if (file_exists($f)) unlink($f);
    }
    foreach (['/etc/asterisk/pjsip_custom_post.conf', '/etc/asterisk/extensions_custom.conf'] as $f) {
        if (!file_exists($f)) continue;
        $lines = file($f, FILE_IGNORE_NEW_LINES);
        $lines = array_filter($lines, function($line) {
            return strpos($line, 'qualid') === false;
        });
        file_put_contents($f, implode("\n", $lines) . "\n");
    }
    if (function_exists('needreload')) { needreload(); }
}

// ---------------------------------------------------------------------------
// Utility
// ---------------------------------------------------------------------------

function qualid_connection_status() {
    $connected = qualid_get('connected', '0') === '1';
    if (!$connected) return ['status' => 'disconnected', 'label' => 'Not Connected', 'class' => 'danger'];
    if (!file_exists('/etc/asterisk/pjsip_qualid.conf')) {
        return ['status' => 'warning', 'label' => 'Config Missing', 'class' => 'warning'];
    }
    return ['status' => 'connected', 'label' => 'Connected', 'class' => 'success'];
}

// ---------------------------------------------------------------------------
// IVR AGI Helpers
// ---------------------------------------------------------------------------

/**
 * Generate a cryptographically random AGI secret (48 hex chars).
 */
function qualid_generate_agi_secret() {
    return bin2hex(random_bytes(24));
}

/**
 * Write /etc/asterisk/qualid_ivr.conf (read by the AGI script at runtime)
 * and copy the AGI script from the module's agi/ directory to the Asterisk
 * agi-bin directory.  Called automatically during qualid_complete_login().
 */
function qualid_write_agi_files($agi_secret) {
    // 1. Write the conf file (api_base + secret — the only runtime variables)
    $conf = "; QUALI-D IVR — auto-generated by the qualid_remote FreePBX module.\n"
          . "; Do NOT edit manually — reconnect via Admin → Quali-D Connect.\n"
          . "api_base=" . QUALID_MAIN_API . "\n"
          . "agi_secret=" . $agi_secret . "\n";
    file_put_contents(QUALID_IVR_CONF, $conf);

    // 2. Copy AGI scripts from the module's agi/ directory to agi-bin
    $agi_dir = dirname(QUALID_AGI_BIN);
    if (!is_dir($agi_dir)) {
        @mkdir($agi_dir, 0755, true);
    }
    foreach ([
        QUALID_AGI_SRC    => QUALID_AGI_BIN,
        QUALID_OB_AGI_SRC => QUALID_OB_AGI_BIN,
    ] as $src => $bin) {
        if (file_exists($src)) {
            copy($src, $bin);
            chmod($bin, 0755);
            @chown($bin, 'asterisk');
            @chgrp($bin, 'asterisk');
        }
    }
}

/**
 * Remove the AGI conf file and the deployed AGI script.
 * Called on disconnect and uninstall.
 */
function qualid_remove_agi_files() {
    foreach ([QUALID_IVR_CONF, QUALID_AGI_BIN, QUALID_OB_AGI_BIN] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
}

/**
 * Register the AGI secret with the QUALI-D cloud API so the middleware
 * can validate inbound requests from this FreePBX instance.
 * Returns the API response array.
 */
function qualid_register_agi_secret($token, $agi_secret) {
    return qualid_curl_post(
        QUALID_MAIN_API . '/ivr/set-agi-secret',
        ['agi_secret' => $agi_secret, 'device_uuid' => qualid_get_device_uuid()],
        ['Authorization: Bearer ' . $token]
    );
}

/**
 * Collect real-time device metrics for the heartbeat payload.
 * Returns an array safe to JSON-encode and send to the cloud.
 */
function qualid_collect_device_metrics() {
    $metrics = [];

    // Version + identity
    $metrics['version']     = trim(@file_get_contents('/etc/qualid/version') ?: '0.0.0');
    $metrics['device_type'] = defined('QUALID_DEVICE_TYPE') ? QUALID_DEVICE_TYPE : 'qualid_pbx_os';
    $metrics['hostname']    = gethostname();

    // Uptime in seconds
    $uptime_raw = @file_get_contents('/proc/uptime');
    $metrics['uptime'] = $uptime_raw ? (int) floatval(explode(' ', $uptime_raw)[0]) : 0;

    // Asterisk running?
    $ast_check = function_exists('shell_exec') ? @shell_exec('/usr/sbin/asterisk -rx "core show version" 2>/dev/null') : null;
    $metrics['asterisk_running'] = !empty($ast_check);

    // Active channel count
    $metrics['active_calls'] = 0;
    if ($metrics['asterisk_running']) {
        $ch_raw = function_exists('shell_exec') ? @shell_exec('/usr/sbin/asterisk -rx "core show channels count" 2>/dev/null') : null;
        if ($ch_raw && preg_match('/(\d+)\s+active channel/', $ch_raw, $m)) {
            $metrics['active_calls'] = (int) $m[1];
        }
    }

    // Extension count from local cache
    $exts = @json_decode(@file_get_contents('/etc/qualid/extensions_cache.json'), true);
    $metrics['extensions_count'] = is_array($exts) ? count($exts) : 0;

    // LAN IP (first non-loopback global IPv4)
    $ip_raw = function_exists('shell_exec') ? @shell_exec("ip -4 addr show scope global 2>/dev/null | grep -oP '(?<=inet )[\d.]+' | head -1") : null;
    $metrics['ip_lan'] = $ip_raw ? trim($ip_raw) : '';

    // Last sync timestamp (mtime of extensions cache)
    $sync_mtime = @filemtime('/etc/qualid/extensions_cache.json');
    $metrics['last_sync_at'] = $sync_mtime ? gmdate('Y-m-d\TH:i:s\Z', $sync_mtime) : null;

    // Activation timestamp
    $activated_file = '/etc/qualid/activated';
    $metrics['activated_at'] = file_exists($activated_file)
        ? trim(@file_get_contents($activated_file))
        : null;

    // Disk usage for key mount points (/, /tmp, /var/spool/asterisk)
    $df_raw = function_exists('shell_exec')
        ? @shell_exec("df -P / /tmp /var/spool/asterisk 2>/dev/null")
        : null;
    $metrics['disk'] = [];
    if ($df_raw) {
        $lines = array_slice(explode("\n", trim($df_raw)), 1); // skip header
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 6) {
                $metrics['disk'][] = [
                    'mount'    => $parts[5],
                    'used_pct' => (int) rtrim($parts[4], '%'),
                    'avail_kb' => (int) $parts[3],
                ];
            }
        }
    }

    return $metrics;
}

/**
 * Send a heartbeat to Quali-D cloud with device metrics.
 * Cloud stores metrics in company_pbx_devices for the fleet view.
 */
function qualid_send_heartbeat($token) {
    $metrics = qualid_collect_device_metrics();
    $metrics['device_uuid']           = qualid_get_device_uuid();
    $metrics['gateway_registrations'] = qualid_collect_gateway_registrations();
    $result  = qualid_curl_post(
        QUALID_MAIN_API . '/ivr/heartbeat',
        $metrics,
        ['Authorization: Bearer ' . $token]
    );

    // If the cloud has a display_name set for this device (via dashboard), save it
    // locally so active.html reflects the cloud-side name without manual re-entry.
    if (isset($result['display_name']) && $result['display_name'] !== '') {
        $local = qualid_get('display_name', '');
        if ($local !== $result['display_name']) {
            qualid_set('display_name', $result['display_name']);
        }
    }

    // If the cloud signals that changes were made via the dashboard (new extension,
    // delete, secret rotation, or manual "Sync Now"), trigger an immediate re-sync
    // in this same PHP run rather than waiting for the next timer tick.
    if (!empty($result['sync_requested'])) {
        qualid_sync_extensions($token);
        qualid_sync_queues($token);
    }

    return $result;
}

/**
 * Push this FreePBX server's own IP/hostname to the QUALI-D cloud so agents
 * know which host to register their SIP extensions against.
 * Uses SERVER_ADDR when available, falls back to gethostbyname(gethostname()).
 * Non-fatal — failure is logged but does not block login.
 */
function qualid_push_pbx_host($token) {
    $host = !empty($_SERVER['SERVER_ADDR'])
        ? $_SERVER['SERVER_ADDR']
        : gethostbyname(gethostname());

    return qualid_curl_post(
        QUALID_MAIN_API . '/company/pbx-host',
        ['pbx_host' => $host, 'pbx_port' => 5060, 'device_uuid' => qualid_get_device_uuid()],
        ['Authorization: Bearer ' . $token]
    );
}

/**
 * Run all IVR connectivity checks:
 *   1. Can we reach the QUALI-D API?
 *   2. Is the AGI script deployed?
 *   3. Is the AGI secret set?
 * Returns a status array consumed by the IVR Status card.
 */
function qualid_test_ivr_connection() {
    $token      = qualid_get('token', '');
    $agi_secret = qualid_get('agi_secret', '');
    $last_sync  = qualid_get('last_sync_at', '');

    $api_ok = false;
    if ($token) {
        $ch = curl_init(QUALID_MAIN_API . '/ivr/connection-status');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $api_ok = ($code >= 200 && $code < 300);
        curl_close($ch);
    }

    return [
        'api_reachable'  => $api_ok,
        'agi_deployed'   => file_exists(QUALID_AGI_BIN) && file_exists(QUALID_IVR_CONF),
        'agi_secret_set' => !empty($agi_secret),
        'last_sync'      => $last_sync,
    ];
}

// ---------------------------------------------------------------------------
// CDR Sync
// ---------------------------------------------------------------------------

/**
 * Fetch CDR records from Asterisk's Master.csv CDR file.
 * No external database required — Asterisk's cdr_csv module writes this file
 * automatically when cdr_csv.so is loaded (default on Debian Asterisk).
 *
 * CSV column order (Asterisk default):
 *   0:accountcode  1:src  2:dst  3:dcontext  4:clid  5:channel  6:dstchannel
 *   7:lastapp  8:lastdata  9:start  10:answer  11:end  12:duration  13:billsec
 *   14:disposition  15:amaflags  16:uniqueid  17:userfield
 *
 * If $since is provided (Y-m-d H:i:s), only records with start > $since are returned.
 * Returns up to 500 rows (incremental — usually far fewer).
 *
 * Falls back to a local MySQL CDR database if cdr_db_dsn is set in config.json.
 * That lets you switch to cdr_mysql.so without changing this file:
 *   qualid_set('cdr_db_dsn',  'mysql:host=127.0.0.1;dbname=asterisk_cdr');
 *   qualid_set('cdr_db_user', 'asterisk');
 *   qualid_set('cdr_db_pass', 'secret');
 */
function qualid_get_cdr_since($since = null) {
    // ── Option A: local MySQL CDR database (cdr_mysql.so) ────────────────────
    $db_dsn  = qualid_get('cdr_db_dsn',  '');
    $db_user = qualid_get('cdr_db_user', '');
    $db_pass = qualid_get('cdr_db_pass', '');
    if (!empty($db_dsn)) {
        try {
            $pdo = new PDO($db_dsn, $db_user, $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($since) {
                $stmt = $pdo->prepare(
                    "SELECT uniqueid, calldate, src, dst, duration, billsec, disposition
                     FROM cdr WHERE calldate > ? ORDER BY calldate ASC LIMIT 500"
                );
                $stmt->execute([$since]);
            } else {
                $stmt = $pdo->query(
                    "SELECT uniqueid, calldate, src, dst, duration, billsec, disposition
                     FROM cdr ORDER BY calldate DESC LIMIT 500"
                );
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Exception $e) {
            // fall through to CSV
        }
    }

    // ── Option B: Asterisk Master.csv (cdr_csv.so — default) ─────────────────
    $cdr_file = '/var/log/asterisk/cdr-csv/Master.csv';
    if (!file_exists($cdr_file)) {
        return [];
    }

    $rows = [];
    $fh   = @fopen($cdr_file, 'r');
    if (!$fh) return [];

    while (($line = fgets($fh)) !== false) {
        $f = str_getcsv(trim($line));
        if (count($f) < 17) continue;
        $calldate = trim($f[9]);    // start time: "2026-11-04 14:23:01"
        if (empty($calldate)) continue;
        if ($since && $calldate <= $since) continue;
        $rows[] = [
            'uniqueid'    => trim($f[16]),
            'calldate'    => $calldate,
            'src'         => trim($f[1]),
            'dst'         => trim($f[2]),
            'duration'    => (int) trim($f[12]),
            'billsec'     => (int) trim($f[13]),
            'disposition' => trim($f[14]),
        ];
        if (count($rows) >= 500) break;
    }
    fclose($fh);

    usort($rows, function ($a, $b) { return strcmp($a['calldate'], $b['calldate']); });
    return $rows;
}

/**
 * Push new CDR records to the QUALI-D cloud API (incremental — since last sync).
 * Stores last_cdr_sync_at in config.json on success.
 */
function qualid_sync_cdr_to_qualid($token) {
    $lastSync = qualid_get('last_cdr_sync_at', null);
    $rows     = qualid_get_cdr_since($lastSync);

    if (empty($rows)) {
        return ['success' => true, 'synced' => 0, 'message' => 'No new records'];
    }

    $result = qualid_curl_post(
        QUALID_MAIN_API . '/cdr/sync',
        ['records' => $rows, 'device_uuid' => qualid_get_device_uuid()],
        ['Authorization: Bearer ' . $token]
    );

    if (isset($result['success']) && $result['success']) {
        qualid_set('last_cdr_sync_at', date('Y-m-d H:i:s'));
    }

    return array_merge(
        is_array($result) ? $result : ['success' => false],
        ['record_count' => count($rows)]
    );
}

/**
 * Write (or overwrite) /etc/qualid/extensions_cache.json atomically.
 *
 * $extensions  array of objects, each with at minimum:
 *   { "extension": "101", "display_name": "Alice", "type": "pjsip" }
 *
 * Returns true on success, false on failure.
 */
function qualid_write_extensions_cache($extensions) {
    if (!is_array($extensions) || empty($extensions)) return false;

    $cache_file = '/etc/qualid/extensions_cache.json';
    $tmp_file   = $cache_file . '.tmp.' . getmypid();

    // Strip transient fields before writing so the cache stays minimal
    $clean = [];
    foreach ($extensions as $e) {
        $entry = [
            'extension'    => isset($e['extension'])    ? (string)$e['extension']    : '',
            'display_name' => isset($e['display_name']) ? (string)$e['display_name'] : '',
            'type'         => isset($e['type'])         ? strtolower($e['type'])      : 'pjsip',
        ];
        if ($entry['extension'] === '') continue;
        // Preserve optional fields the API may send
        foreach (['secret', 'callerid', 'email'] as $opt) {
            if (isset($e[$opt])) $entry[$opt] = $e[$opt];
        }
        $clean[] = $entry;
    }

    if (empty($clean)) return false;

    $ok = @file_put_contents($tmp_file, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($ok === false) return false;

    // Atomic replace
    if (!@rename($tmp_file, $cache_file)) {
        @unlink($tmp_file);
        return false;
    }

    return true;
}

/**
 * Read the local extension list from /etc/qualid/extensions_cache.json
 * (written by qualid_write_extensions_cache()), enrich with live PJSIP
 * registration status, POST to the cloud, and self-update the cache if
 * the API returns a refreshed extension list.
 *
 * Cache format — JSON array of objects:
 *   [{"extension":"101","display_name":"Alice","type":"pjsip"}, ...]
 */
function qualid_sync_extensions($token) {
    // Load extension list from local cache
    $cache_file = '/etc/qualid/extensions_cache.json';
    $rows = [];
    if (file_exists($cache_file)) {
        $cached = json_decode(@file_get_contents($cache_file), true);
        if (is_array($cached)) $rows = $cached;
    }

    // Get live PJSIP registration status via Asterisk CLI.
    // Output format: "  101/sip:101@x.x.x.x   Avail   0.500 ..."
    $contacts_raw = function_exists('shell_exec') ? (@shell_exec('sudo /usr/sbin/asterisk -rx "pjsip show contacts" 2>/dev/null') ?: '') : '';
    $online = [];
    if (!empty($contacts_raw) &&
        preg_match_all('/^\s*(\d+)\/[^\s]+\s+Avail/m', $contacts_raw, $m)) {
        $online = array_flip($m[1]);
    }

    $extensions = [];
    foreach ($rows as $row) {
        $ext  = isset($row['extension'])    ? $row['extension']    : '';
        $tech = isset($row['type'])         ? strtolower($row['type']) : 'pjsip';
        $name = isset($row['display_name']) ? $row['display_name'] : $ext;
        if (!$ext) continue;
        $status = ($tech === 'pjsip')
            ? (isset($online[$ext]) ? 'online' : 'offline')
            : 'unknown';
        $extensions[] = [
            'extension'    => $ext,
            'display_name' => $name,
            'type'         => $tech,
            'status'       => $status,
        ];
    }

    // Always POST to cloud even when cache is empty — the response returns the
    // canonical extension list for this PBX so we can seed the local cache and
    // write PJSIP configs on first activation.
    $result = qualid_curl_post(
        QUALID_MAIN_API . '/extensions/sync',
        ['extensions' => $extensions, 'device_uuid' => qualid_get_device_uuid()],
        ['Authorization: Bearer ' . $token]
    );

    if (!is_array($result)) {
        return ['success' => false, 'error' => 'Invalid API response'];
    }

    // Fetch the canonical extension list from the cloud (source of truth).
    // GET /extensions returns all extensions including SIP secrets so the PBX
    // can write PJSIP endpoint + auth config for local agent registration.
    $list_result = qualid_curl_get(
        QUALID_MAIN_API . '/extensions?device_uuid=' . urlencode(qualid_get_device_uuid()),
        ['Authorization: Bearer ' . $token]
    );

    if (!empty($list_result['extensions']) && is_array($list_result['extensions'])) {
        qualid_write_extensions_cache($list_result['extensions']);
        qualid_write_pjsip_extensions($list_result['extensions']);
        exec('/usr/sbin/asterisk -rx "module reload res_pjsip.so" 2>/dev/null');
    } elseif (!empty($rows)) {
        // Fallback: cloud unreachable — write PJSIP from local cache (e.g. after a reboot).
        qualid_write_pjsip_extensions($rows);
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Queue sync — fetch queue config from QUALI-D API, write to FreePBX DB
// (so queues appear in the native FreePBX UI) and to queues_qualid.conf
// (so Asterisk picks them up immediately without waiting for Apply Config).
// ---------------------------------------------------------------------------

/**
 * Fetch all queues from the QUALI-D API, push them into FreePBX's own queue
 * database tables (queues + queue_details), and write queues_qualid.conf for
 * immediate Asterisk activation.
 */
function qualid_sync_queues($token) {
    $result = qualid_curl_get(
        QUALID_MAIN_API . '/ivr/queues/asterisk-config?device_uuid=' . urlencode(qualid_get_device_uuid()),
        array('Authorization: Bearer ' . $token)
    );

    if (isset($result['_error'])) {
        return array('success' => false, 'error' => 'Network error: ' . $result['_error']);
    }
    if (empty($result['success'])) {
        return array('success' => false, 'error' => 'API error fetching queue config');
    }

    $queues = isset($result['queues']) ? $result['queues'] : array();

    // Write Asterisk conf for immediate effect
    qualid_write_queues($queues);

    // Download any custom hold music files and update musiconhold_qualid.conf
    qualid_sync_moh_files($queues, $token);
    qualid_write_moh_conf($queues);

    // No FreePBX DB on PBX OS — conf file is the only source of truth.

    return array('success' => true, 'queues' => count($queues));
}

/**
 * Fetch gateway (FXO / SIP trunk) credentials from the cloud and write
 * pjsip_qualid_gateways.conf so registered devices can connect to Asterisk.
 */
// Ensures the LAN UDP transport exists in pjsip_qualid.conf.
// No-op if already present. Self-heals devices activated before the UDP transport was added.
function qualid_ensure_udp_transport() {
    $path = '/etc/asterisk/pjsip_qualid.conf';
    if (!file_exists($path)) return;
    if (strpos((string)file_get_contents($path), '[qualid_lan_udp_transport]') !== false) return;
    $stanza = "\n; UDP transport — added by qualid_ensure_udp_transport\n"
            . "[qualid_lan_udp_transport]\ntype=transport\nprotocol=udp\nbind=0.0.0.0:5060\n";
    file_put_contents($path, file_get_contents($path) . $stanza);
    @exec('/usr/sbin/asterisk -rx "module reload res_pjsip.so" 2>/dev/null');
}

function qualid_sync_gateways($token) {
    $result = qualid_curl_get(
        QUALID_MAIN_API . '/ivr/gateways/asterisk-config?device_uuid=' . urlencode(qualid_get_device_uuid()),
        array('Authorization: Bearer ' . $token)
    );

    if (isset($result['_error'])) {
        return array('success' => false, 'error' => 'Network error: ' . $result['_error']);
    }
    if (empty($result['success'])) {
        return array('success' => false, 'error' => 'API error fetching gateway config');
    }

    $gateways = isset($result['gateways']) ? $result['gateways'] : array();
    qualid_ensure_udp_transport();
    qualid_write_gateway_endpoints($gateways);

    return array('success' => true, 'gateways' => count($gateways));
}

/**
 * Write /etc/asterisk/pjsip_qualid_gateways.conf from the gateway list
 * returned by /ivr/gateways/asterisk-config.
 *
 * Hardware gateways (type=gateway_hardware):
 *   Device registers INBOUND to PBX.  Three PJSIP objects:
 *     [{username}]         — endpoint
 *     [{username}_gw_auth] — auth
 *     [{username}]         — aor
 *
 * SIP trunks (type=sip_trunk):
 *   PBX registers OUTBOUND to carrier.  Four PJSIP objects + optional transport:
 *     [{user}_carrier_tls]  — transport (TLS only; reuses UDP transport otherwise)
 *     [{user}_carrier_auth] — auth
 *     [{user}_carrier_reg]  — registration
 *     [{user}_carrier_aor]  — aor
 *     [{user}_carrier]      — endpoint
 *
 * SIP trunks with transport=tls use TLS on the configured port (e.g. 443 or 5061)
 * which bypasses Egyptian ISP blocking of plain SIP on port 5060.
 */
function qualid_write_gateway_endpoints($gateways) {
    $conf = "; =============================================================================\n"
          . "; Quali-D Connect — Gateway Endpoints (FXO / SIP trunks)\n"
          . "; Auto-generated by qualid_sync. DO NOT EDIT manually.\n"
          . "; =============================================================================\n\n";

    // Generate the shared cloud carrier TLS transport once (before the loop).
    // This transport advertises the VPS relay IP so cloud carriers (Telnyx, etc.)
    // send inbound INVITEs to the VPS instead of the PBX's private LAN IP.
    $hasCloudTls = false;
    foreach ($gateways as $gw) {
        if (($gw['type'] ?? '') === 'cloud_sip_trunk' && strtolower($gw['transport'] ?? 'udp') === 'tls') {
            $hasCloudTls = true;
            break;
        }
    }
    if ($hasCloudTls) {
        $vpsIp = qualid_resolve_relay_ip();
        $conf .= "; --- Shared cloud carrier TLS transport (external_signaling_address → VPS) ---\n";
        $conf .= "[qualid_cloud_carrier_transport]\n";
        $conf .= "type=transport\n";
        $conf .= "protocol=tls\n";
        $conf .= "bind=0.0.0.0:5062\n";   // local bind on 5062; Contact advertises 5061
        $conf .= "verify_server=no\n";
        $conf .= "verify_client=no\n";
        $conf .= "method=tlsv1_2\n";
        $conf .= "external_signaling_address={$vpsIp}\n";
        $conf .= "external_signaling_port=5061\n\n";
    }

    foreach ($gateways as $gw) {
        $type      = $gw['type'] ?? 'gateway_hardware';
        $user      = preg_replace('/[^A-Za-z0-9_-]/', '', $gw['username'] ?? '');
        $pass      = $gw['password'] ?? '';
        $context   = preg_replace('/[^A-Za-z0-9_-]/', '', $gw['context'] ?? 'qualid-ivr');
        if (!$user || !$pass) continue;

        if ($type === 'sip_trunk' || $type === 'cloud_sip_trunk') {
            // ── Outbound SIP trunk — PBX registers to carrier ─────────────────
            $host      = trim($gw['host'] ?? '');
            $port      = (int)($gw['port'] ?? 5060);
            $transport = strtolower(trim($gw['transport'] ?? 'udp'));
            $dtmf      = preg_replace('/[^A-Za-z0-9_-]/', '', $gw['dtmf_mode'] ?? 'rfc4733');
            $codecs    = is_array($gw['codecs']) && count($gw['codecs']) ? $gw['codecs'] : array('ulaw', 'alaw');
            $regEnabled = !empty($gw['registration_enabled']);
            $rawPjsip  = trim($gw['raw_pjsip'] ?? '');
            if (!$host) continue;

            $isTls     = ($transport === 'tls');
            $ep        = "{$user}_carrier";
            // Asterisk config treats ';' as inline comments, so we CANNOT use
            // "sip:host:port;transport=tls" — the ;transport=tls would be stripped.
            // For TLS trunks we specify transport= explicitly and omit the URI param.
            // cloud_sip_trunk gets a dedicated transport with external_signaling_address
            // so the carrier sees the VPS public IP in REGISTER Contact (not PBX LAN IP).
            $tlsTransport = ($type === 'cloud_sip_trunk')
                ? 'qualid_cloud_carrier_transport'
                : QUALID_TRUNK_NAME . '_tls_transport';
            $serverUri = "sip:{$host}:{$port}";
            $clientUri = "sip:{$user}@{$host}";

            $codecLines = "disallow=all\n";
            foreach ($codecs as $c) {
                $c = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$c);
                if ($c) $codecLines .= "allow={$c}\n";
            }

            $conf .= "; --- {$user} (SIP Trunk → {$host}:{$port} {$transport}) ---\n";

            // Auth
            $conf .= "[{$ep}_auth]\n";
            $conf .= "type=auth\n";
            $conf .= "auth_type=userpass\n";
            $conf .= "username={$user}\n";
            $conf .= "password={$pass}\n\n";

            // Registration (optional — skip for IP-auth trunks)
            if ($regEnabled) {
                $conf .= "[{$ep}_reg]\n";
                $conf .= "type=registration\n";
                if ($isTls) $conf .= "transport={$tlsTransport}\n";
                if ($type === 'cloud_sip_trunk') {
                    // Send the REGISTER to kamailio's carrier port (5061) on the VPS.
                    // Avoids outbound_proxy entirely — Asterisk strips URI params (';transport=tls')
                    // from outbound_proxy values regardless of quoting or escaping.
                    // kamailio identifies carrier REGISTERs by port 5061 + external To-domain,
                    // then relays to the actual carrier using the To-domain from client_uri.
                    $conf .= "server_uri=sip:" . QUALID_CLOUD_IP . ":5061\n";
                } else {
                    $conf .= "server_uri={$serverUri}\n";
                }
                $conf .= "client_uri={$clientUri}\n";
                $conf .= "outbound_auth={$ep}_auth\n";
                $conf .= "contact_user={$user}\n";
                $conf .= "retry_interval=60\n";
                $conf .= "expiration=3600\n\n";
            }

            // AOR — static contact pointing at carrier
            $conf .= "[{$ep}_aor]\n";
            $conf .= "type=aor\n";
            $conf .= "contact={$serverUri}\n\n";

            // Endpoint — used by dialplan (Dial(PJSIP/num@{ep}))
            $conf .= "[{$ep}]\n";
            $conf .= "type=endpoint\n";
            $conf .= "context={$context}\n";
            $conf .= $codecLines;
            if ($isTls) $conf .= "transport={$tlsTransport}\n";
            $conf .= "outbound_auth={$ep}_auth\n";
            $conf .= "aors={$ep}_aor\n";
            $conf .= "from_domain={$host}\n";
            $conf .= "from_user={$user}\n";
            $conf .= "direct_media=no\n";
            $conf .= "dtmf_mode={$dtmf}\n";
            if ($rawPjsip) $conf .= $rawPjsip . "\n";
            $conf .= "\n";

        } else {
            // ── Hardware gateway — device registers INBOUND to PBX ────────────
            $conf .= "; --- {$user} (Hardware GW) ---\n";
            $conf .= "[{$user}]\n";
            $conf .= "type=endpoint\n";
            $conf .= "context={$context}\n";
            $conf .= "disallow=all\n";
            $conf .= "allow=ulaw,alaw,g722\n";
            $conf .= "auth={$user}_gw_auth\n";
            $conf .= "aors={$user}\n";
            $conf .= "direct_media=no\n";
            $conf .= "trust_id_inbound=yes\n\n";

            $conf .= "[{$user}_gw_auth]\n";
            $conf .= "type=auth\n";
            $conf .= "auth_type=userpass\n";
            $conf .= "username={$user}\n";
            $conf .= "password={$pass}\n\n";

            $conf .= "[{$user}]\n";
            $conf .= "type=aor\n";
            $conf .= "max_contacts=1\n";
            $conf .= "remove_existing=yes\n\n";
        }
    }

    file_put_contents('/etc/asterisk/pjsip_qualid_gateways.conf', $conf);
    qualid_ensure_include('/etc/asterisk/pjsip.conf', 'pjsip_qualid_gateways.conf');

    // Reload all PJSIP modules — "pjsip reload" covers both res_pjsip.so (endpoints)
    // and res_pjsip_outbound_registration.so (outbound registrations).
    @exec('/usr/sbin/asterisk -rx "pjsip reload" 2>/dev/null');
}

/**
 * Collect registration status for all configured gateway endpoints.
 * Returns an associative array: [ 'WE-Landline-FXO' => true/false, ... ]
 * Sent to the cloud via the heartbeat so the dashboard can show live status.
 */
function qualid_collect_gateway_registrations() {
    $gwConf = '/etc/asterisk/pjsip_qualid_gateways.conf';
    if (!file_exists($gwConf)) return array();

    preg_match_all('/^\[([A-Za-z0-9_-]+)\]\s*\ntype=endpoint/m',
                   (string) file_get_contents($gwConf), $m);
    if (empty($m[1])) return array();

    // pjsip show contacts — inbound (hardware) gateways appear here when registered.
    // pjsip show registrations — outbound (SIP trunk) registrations appear here.
    $contactsOut = (string) @shell_exec('/usr/sbin/asterisk -rx "pjsip show contacts" 2>/dev/null');
    $regOut      = (string) @shell_exec('/usr/sbin/asterisk -rx "pjsip show registrations" 2>/dev/null');
    $result = array();
    foreach ($m[1] as $ep) {
        // Hardware GW:  contacts output has "{ep}/sip:..."
        // SIP trunk:    registrations output has "{ep}_reg/sip:..." (reg object has _reg suffix)
        $registered = strpos($contactsOut, $ep . '/') !== false
                   || strpos($regOut,      $ep . '/') !== false
                   || strpos($regOut,      $ep . '_reg/') !== false;
        // SIP trunk endpoints are named "{username}_carrier" in PJSIP but stored as
        // "{username}" in the DB — strip the suffix so the heartbeat key matches.
        $key = preg_replace('/_carrier$/', '', $ep);
        $result[$key] = $registered;
    }
    return $result;
}

/**
 * No-op on Quali-D PBX OS — there is no FreePBX GUI database.
 * Queue configuration lives entirely in /etc/asterisk/queues_qualid.conf.
 */
function qualid_sync_queues_to_freepbx_db($queues) {
    // Intentionally empty on PBX OS.
    return;
    // ── Original FreePBX version below (kept for reference) ──────────────────
    try {
        $pdo = null; // FreePBX::create()->Database;
    } catch (Exception $e) {
        return; // FreePBX PDO unavailable
    }

    try {
        $prev_names = qualid_get('synced_queue_names', array());
        if (!is_array($prev_names)) { $prev_names = array(); }
        $current_names = array();

        foreach ($queues as $q) {
            $name = preg_replace('/[^0-9]/', '', $q['name']);
            if (!$name) continue;
            $current_names[] = $name;

            $display = isset($q['display_name']) ? $q['display_name'] : $name;

            // Upsert the queue row.
            // dest = 'app-blackhole,hangup,1' silences FreePBX's "no failover
            // destination" UI warning. Actual failover routing is handled by
            // the IVR AGI flow, so this value is never reached in practice.
            $stmt = $pdo->prepare(
                "INSERT INTO queues_config (extension, descr, dest)
                 VALUES (?, ?, 'app-blackhole,hangup,1')
                 ON DUPLICATE KEY UPDATE descr = VALUES(descr)"
            );
            $stmt->execute(array($name, $display));

            // Replace queue setting rows only — leave any existing 'member' rows
            // intact so that agents added via the FreePBX UI are not wiped.
            $pdo->prepare("DELETE FROM queues_details WHERE id = ? AND keyword != 'member'")->execute(array($name));

            $details = array(
                'strategy'          => isset($q['strategy'])      ? $q['strategy']      : 'rrmemory',
                'timeout'           => isset($q['timeout'])       ? (int) $q['timeout'] : 30,
                'maxlen'            => isset($q['maxlen'])        ? (int) $q['maxlen']  : 0,
                'wrapuptime'        => isset($q['wrapuptime'])    ? (int) $q['wrapuptime'] : 0,
                'musicclass'        => isset($q['music_on_hold']) ? $q['music_on_hold'] : 'default',
                'announce-holdtime' => !empty($q['announce_holdtime']) ? 'yes' : 'no',
                'announce-position' => !empty($q['announce_position']) ? 'yes' : 'no',
            );

            $ins = $pdo->prepare(
                "INSERT INTO queues_details (id, keyword, data, flags) VALUES (?, ?, ?, 0)"
            );
            foreach ($details as $key => $val) {
                $ins->execute(array($name, $key, (string) $val));
            }

            // Upgrade any pre-existing member rows written with flags=0 to
            // flags=1 so they appear in FreePBX's Static Agents tab.
            // This runs on every sync, so no CLI command is needed.
            $pdo->prepare(
                "UPDATE queues_details SET flags=1 WHERE id=? AND keyword='member' AND flags=0"
            )->execute(array($name));

            // Members — only replace when the API provides at least one member.
            // If the API omits 'members' or sends an empty array (no agents
            // assigned yet), the existing member rows are preserved so that
            // agents added manually via the FreePBX UI are not wiped.
            // flags=1 matches what FreePBX_Helpers::setConfig() writes for
            // user-defined rows — the Static Agents tab filters on this value.
            if (!empty($q['members'])) {
                $pdo->prepare("DELETE FROM queues_details WHERE id = ? AND keyword = 'member'")->execute(array($name));
                $mem_ins = $pdo->prepare(
                    "INSERT INTO queues_details (id, keyword, data, flags) VALUES (?, 'member', ?, 1)"
                );
                foreach ($q['members'] as $ext) {
                    $clean = preg_replace('/[^0-9]/', '', $ext);
                    if ($clean) {
                        $mem_ins->execute(array($name, 'Local/' . $clean . '@qualid-remote-agents/n'));
                    }
                }
            }
        }

        // Remove from FreePBX DB any queues we previously synced that are now gone
        $removed = array_diff($prev_names, $current_names);
        foreach ($removed as $old) {
            $pdo->prepare("DELETE FROM queues_config  WHERE extension = ?")->execute(array($old));
            $pdo->prepare("DELETE FROM queues_details WHERE id        = ?")->execute(array($old));
        }

        qualid_set('synced_queue_names', $current_names);

        // Mark FreePBX as needing Apply Config (updates the banner in the UI)
        if (function_exists('needreload')) { needreload(); }

    } catch (Exception $e) {
        // Non-fatal
    }
}

/**
 * Write /etc/asterisk/queues_qualid.conf from the array returned by
 * GET /ivr/queues/asterisk-config and ensure it is #included from
 * queues_custom.conf (which Asterisk loads automatically).
 */
function qualid_write_queues($queues) {
    $conf = "; =============================================================================\n"
          . "; Quali-D Connect — Queue Configuration\n"
          . "; Auto-generated by the qualid_remote FreePBX module. DO NOT EDIT manually.\n"
          . "; To manage queues, use the QUALI-D dashboard.\n"
          . "; =============================================================================\n\n";

    foreach ($queues as $q) {
        $name = preg_replace('/[^0-9]/', '', $q['name']);
        if (!$name) continue;

        $conf .= "[{$name}]\n";
        $conf .= 'strategy='          . (isset($q['strategy'])      ? $q['strategy']      : 'rrmemory') . "\n";
        $conf .= 'timeout='           . ((int) (isset($q['timeout'])    ? $q['timeout']    : 30))        . "\n";
        $conf .= 'maxlen='            . ((int) (isset($q['maxlen'])     ? $q['maxlen']     : 0))         . "\n";
        $conf .= 'wrapuptime='        . ((int) (isset($q['wrapuptime']) ? $q['wrapuptime'] : 0))         . "\n";
        // Do not ring an agent who is already on a call or in a ringing state.
        // Without this, Asterisk (especially with Local channel members) may send
        // a second INVITE before the first has been fully torn down.
        $conf .= 'ringinuse=no'                                                                          . "\n";
        // Wait 30 s between ring attempts instead of the 5 s default.
        // The default causes an immediate second ring after a timeout, which
        // is jarring for agents (and masks whether the caller already hung up).
        $conf .= 'retry=30'                                                                              . "\n";
        $mohClass = (isset($q['music_on_hold']) && $q['music_on_hold'] !== '' && $q['music_on_hold'] !== 'default')
                        ? $q['music_on_hold'] : 'qualid-default';
        $conf .= 'musicclass=' . $mohClass . "\n";
        $conf .= 'announce-holdtime=' . (!empty($q['announce_holdtime']) ? 'yes' : 'no') . "\n";
        $conf .= 'announce-position=' . (!empty($q['announce_position']) ? 'yes' : 'no') . "\n";
        $members = isset($q['members']) ? $q['members'] : array();
        foreach ($members as $member) {
            // Support both legacy string format and new {extension, routing_type} object format
            if (is_array($member)) {
                $clean       = preg_replace('/[^0-9]/', '', $member['extension'] ?? '');
                $routingType = $member['routing_type'] ?? 'remote';
            } else {
                $clean       = preg_replace('/[^0-9]/', '', $member);
                $routingType = 'remote';
            }
            if (!$clean) continue;
            if ($routingType === 'local') {
                // Agent registers directly to this PBX — dial the local PJSIP endpoint
                $conf .= 'member => PJSIP/' . $clean . "\n";
            } else {
                // Agent connects via the Quali-D cloud VPS (remote/WebRTC)
                $conf .= 'member => Local/' . $clean . "@qualid-remote-agents/n\n";
            }
        }
        $conf .= "\n";
    }

    file_put_contents('/etc/asterisk/queues_qualid.conf', $conf);
    // FreePBX uses queues_custom.conf; vanilla Asterisk uses queues.conf directly.
    $q_parent = file_exists('/etc/asterisk/queues_custom.conf')
        ? '/etc/asterisk/queues_custom.conf'
        : '/etc/asterisk/queues.conf';
    qualid_ensure_include($q_parent, 'queues_qualid.conf');

    // Reload just the queue module — no full Asterisk restart needed.
    // This takes effect immediately without any manual Apply Config click.
    @exec('/usr/sbin/asterisk -rx "module reload app_queue.so" 2>/dev/null');
}

/**
 * Download custom hold music MP3 files for queues that have a moh_url.
 * Files are saved to /var/lib/asterisk/moh/qualid/{queue_id}.mp3.
 */
function qualid_sync_moh_files($queues, $token) {
    $moh_dir = '/var/lib/asterisk/moh/qualid';
    if (!is_dir($moh_dir)) {
        @mkdir($moh_dir, 0755, true);
        @chown($moh_dir, 'asterisk');
    }

    foreach ($queues as $q) {
        $moh_url   = isset($q['moh_url'])       ? $q['moh_url']       : null;
        $moh_class = isset($q['music_on_hold'])  ? $q['music_on_hold'] : '';

        if (!$moh_url || !$moh_class || $moh_class === 'qualid-default') continue;

        $queue_id = preg_replace('/^qualid-/', '', $moh_class);
        if (!ctype_digit($queue_id)) continue;

        $dest = $moh_dir . '/' . $queue_id . '.mp3';

        $ch = curl_init($moh_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $token));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data && $code === 200) {
            file_put_contents($dest, $data);
            @chown($dest, 'asterisk');
            @chmod($dest, 0644);
        }
    }
}

/**
 * Write /etc/asterisk/musiconhold_qualid.conf with:
 *   [qualid-default]  — always present, plays default.mp3
 *   [qualid-{id}]     — one per queue with a custom MP3 on disk
 * Then reloads Asterisk MoH so changes take effect immediately.
 */
function qualid_write_moh_conf($queues) {
    $moh_dir = '/var/lib/asterisk/moh/qualid';
    $conf    = "; Quali-D Music on Hold classes — auto-generated by qualid sync. DO NOT EDIT manually.\n\n";

    // qualid-default — always present
    $conf .= "[qualid-default]\n";
    $conf .= "mode=custom\n";
    $conf .= "application=/usr/bin/mpg123 -q -s -r 8000 --mono {$moh_dir}/default.mp3\n";
    $conf .= "format=slin\n\n";

    // Per-queue custom classes
    foreach ($queues as $q) {
        $moh_class = isset($q['music_on_hold']) ? $q['music_on_hold'] : '';
        if (!$moh_class || $moh_class === 'qualid-default') continue;

        $queue_id = preg_replace('/^qualid-/', '', $moh_class);
        if (!ctype_digit($queue_id)) continue;

        $file = $moh_dir . '/' . $queue_id . '.mp3';
        if (!file_exists($file)) continue;

        $conf .= "[{$moh_class}]\n";
        $conf .= "mode=custom\n";
        $conf .= "application=/usr/bin/mpg123 -q -s -r 8000 --mono {$file}\n";
        $conf .= "format=slin\n\n";
    }

    file_put_contents('/etc/asterisk/musiconhold_qualid.conf', $conf);
    qualid_ensure_include('/etc/asterisk/musiconhold.conf', 'musiconhold_qualid.conf');
    @exec('/usr/sbin/asterisk -rx "moh reload" 2>/dev/null');
}

/**
 * Remove the queue conf file, its #include line, and all queue records from
 * FreePBX's own database.  Called on uninstall and disconnect.
 */
function qualid_remove_queues() {
    // Remove Asterisk custom conf
    $f = '/etc/asterisk/queues_qualid.conf';
    if (file_exists($f)) unlink($f);

    $custom = '/etc/asterisk/queues_custom.conf';
    if (file_exists($custom)) {
        $lines = file($custom, FILE_IGNORE_NEW_LINES);
        $lines = array_filter($lines, function($line) {
            return strpos($line, 'queues_qualid') === false;
        });
        file_put_contents($custom, implode("\n", $lines) . "\n");
    }

    // No FreePBX DB on PBX OS — only the conf file needs cleaning.
    qualid_set('synced_queue_names', array());
}

// ---------------------------------------------------------------------------
// FreePBX reload hook
// ---------------------------------------------------------------------------

/**
 * Called automatically by FreePBX on every Apply Config / fwconsole reload.
 *
 * FreePBX's DialplanHooks processor scans all active modules for a function
 * named {rawname}_get_config() and calls it at priority 100. This is the
 * flat-PHP hook that reliably fires during config generation — no BMO class,
 * no cron, no manual steps required.
 *
 * We return null (no dialplan contribution) and use this as a side-channel
 * to push the latest extension list, CDR, PBX host, and heartbeat to the
 * QUALI-D cloud.
 */
function qualid_remote_get_config() {
    $token = qualid_get('token', '');
    if (empty($token)) return null;
    qualid_set('last_apply_config_at', date('Y-m-d H:i:s'));

    // Always regenerate Asterisk config files on Apply Config so that
    // dialplan changes in module updates take effect without requiring
    // a manual reconnect from the admin page.
    $cfg = qualid_load_config();
    if (!empty($cfg['trunk_user']) && !empty($cfg['trunk_pass'])) {
        qualid_write_pjsip($cfg);
        qualid_write_dialplan($cfg);

        // Write RTP config now (best-effort — FreePBX may regenerate
        // rtp_additional.conf after this hook, wiping our TURN block).
        // The per-minute cron watchdog re-applies it within 60 seconds.
        qualid_write_rtp_conf();
    }

    qualid_push_pbx_host($token);
    qualid_sync_extensions($token);
    qualid_sync_queues($token);
    qualid_sync_cdr_to_qualid($token);
    qualid_send_heartbeat($token);
    qualid_sync_trunks($token);

    // Write a cron job so syncing continues automatically every minute
    // without requiring a page visit.  This hook runs as root so it has
    // permission to write to /etc/cron.d/.
    qualid_write_cron();

    return null;   // no dialplan entries to contribute
}

/**
 * Write /etc/cron.d/qualid_remote with two jobs:
 *
 * 1. Every 5 min — full sync (extensions, CDR, TURN credential refresh)
 * 2. Every 1 min — lightweight RTP watchdog that ensures the TURN config
 *    and socat tunnel are present.  This covers the case where FreePBX's
 *    Apply Config regenerates rtp_additional.conf AFTER our hook writes
 *    to it, wiping the TURN block.  The watchdog re-applies within 60s.
 *
 * Safe to call multiple times — idempotent.
 */
function qualid_write_cron() {
    $module_dir  = dirname(__FILE__);
    $sync_script = $module_dir . '/sync.php';
    $rtp_script  = $module_dir . '/deferred_rtp.php';
    $php_bin     = PHP_BINARY ?: '/usr/bin/php';
    $cloud_ip    = QUALID_CLOUD_IP;

    // ── Asterisk user crontab (sync + RTP watchdog) ──────────────────────────
    // The asterisk crontab is proven to fire on FreePBX/Debian systems.
    // /etc/cron.d/ entries silently fail on many Debian installs when the file
    // mixes regular schedules with @reboot — the whole file gets dropped.
    $existing = function_exists('shell_exec') ? (@shell_exec('crontab -u asterisk -l 2>/dev/null') ?: '') : '';
    $kept = array_filter(explode("\n", $existing), function ($line) {
        return strpos($line, 'qualid_remote') === false;
    });
    $kept   = array_values(array_filter($kept, 'strlen'));
    // Prepend PATH so that '/usr/sbin/asterisk -rx' resolves /usr/sbin/asterisk in the
    // minimal cron environment (default cron PATH omits /usr/sbin).
    array_unshift($kept, 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
    $kept[] = "* * * * * {$php_bin} {$sync_script} > /dev/null 2>&1 # qualid_remote";
    $kept[] = "* * * * * {$php_bin} {$rtp_script} > /dev/null 2>&1 # qualid_remote";
    $new_tab = implode("\n", $kept) . "\n";
    $tmpfile = tempnam('/tmp', 'qualid_cron_');
    file_put_contents($tmpfile, $new_tab);
    @exec('crontab -u asterisk ' . escapeshellarg($tmpfile));
    @unlink($tmpfile);

    // ── /etc/cron.d/ — @reboot only (socat tunnel, needs root) ──────────────
    $cron_file = '/etc/cron.d/qualid_remote';
    $content   = "# Quali-D Connect - startup tunnel (root required)\n"
               . "SHELL=/bin/sh\n"
               . "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n"
               . "@reboot root /usr/bin/socat UDP4-LISTEN:3479,reuseaddr,fork TCP4:{$cloud_ip}:80,nodelay > /dev/null 2>&1 &\n";
    @file_put_contents($cron_file, $content);
    @chmod($cron_file, 0644);
    @exec('systemctl reload cron 2>/dev/null || service cron reload 2>/dev/null || true');
}

/**
 * Remove the cron jobs written by qualid_write_cron().
 * Called on disconnect and uninstall.
 */
function qualid_remove_cron() {
    // Remove from asterisk crontab
    $existing = function_exists('shell_exec') ? (@shell_exec('crontab -u asterisk -l 2>/dev/null') ?: '') : '';
    $kept = array_filter(explode("\n", $existing), function ($line) {
        return strpos($line, 'qualid_remote') === false;
    });
    $kept    = array_values(array_filter($kept, 'strlen'));
    $new_tab = $kept ? implode("\n", $kept) . "\n" : '';
    $tmpfile = tempnam('/tmp', 'qualid_cron_');
    file_put_contents($tmpfile, $new_tab);
    @exec('crontab -u asterisk ' . escapeshellarg($tmpfile));
    @unlink($tmpfile);

    // Remove the cron.d file
    $cron_file = '/etc/cron.d/qualid_remote';
    if (file_exists($cron_file)) {
        @unlink($cron_file);
    }
}

// ---------------------------------------------------------------------------
// Diagnostics
// ---------------------------------------------------------------------------

/**
 * Run all system self-checks and return a structured result for the admin UI.
 * No side-effects — read-only.  Safe to call on every page load.
 *
 * Each check item:
 *   id      — machine identifier
 *   label   — human label
 *   ok      — bool
 *   detail  — short status string (may contain safe HTML)
 *   fix     — fix key passed to run_fix AJAX handler (null if no auto-fix)
 *   action  — HTML recommendation string shown when ok=false
 */
function qualid_run_diagnostics() {
    $checks = [];

    // 1. socat binary present
    $socat_bin       = '/usr/bin/socat';
    $socat_installed = file_exists($socat_bin) && is_executable($socat_bin);
    $checks[] = [
        'id'     => 'socat_installed',
        'label'  => 'Network relay binary installed',
        'ok'     => $socat_installed,
        'detail' => $socat_installed ? 'Available' : 'Not installed',
        'fix'    => null,
        'action' => $socat_installed ? null
            : 'A required system binary is missing. Contact your system administrator to install it.',
    ];

    // 2. TURN UDP-over-TCP tunnel running
    exec('pgrep -f "socat.*UDP4-LISTEN:3479" 2>/dev/null', $pids);
    $tunnel_ok = !empty($pids);
    $checks[] = [
        'id'     => 'tunnel_running',
        'label'  => 'SIP media relay active',
        'ok'     => $tunnel_ok,
        'detail' => $tunnel_ok ? 'Active' : 'Not running',
        'fix'    => $socat_installed ? 'tunnel' : null,
        'action' => $tunnel_ok ? null
            : ($socat_installed
                ? 'The SIP media relay is not running. Click <b>Fix</b> to restart it, or wait up to 60&nbsp;s for the automatic watchdog.'
                : 'Install the network relay binary first (see above).'),
    ];

    // 3. TURN credentials stored in config
    $turn_domain = qualid_get('turn_domain', '');
    $turn_secret = qualid_get('turn_secret', '');
    $creds_ok    = !empty($turn_domain) && !empty($turn_secret);
    $checks[] = [
        'id'     => 'turn_creds',
        'label'  => 'TURN credentials provisioned',
        'ok'     => $creds_ok,
        'detail' => $creds_ok ? 'Provisioned' : 'Credentials missing from config',
        'fix'    => null,
        'action' => $creds_ok ? null
            : 'TURN credentials are missing. Click <b>Disconnect</b> then log in again to re-provision.',
    ];

    // 4. rtp_custom.conf writable
    // We write TURN settings here because FreePBX never regenerates this file,
    // whereas rtp_additional.conf is wiped on every Apply Config.
    $rtp_path     = '/etc/asterisk/rtp_custom.conf';
    $rtp_dir_ok   = is_writable('/etc/asterisk');
    $rtp_exists   = file_exists($rtp_path);
    // Writable = file exists and writable, OR file absent but directory writable (we'll create it)
    $rtp_writable = ($rtp_exists && is_writable($rtp_path)) || (!$rtp_exists && $rtp_dir_ok);
    $checks[] = [
        'id'     => 'rtp_writable',
        'label'  => 'RTP configuration writable',
        'ok'     => $rtp_writable,
        'detail' => $rtp_writable
                  ? 'OK'
                  : ($rtp_exists ? 'Exists but not writable by the web server user' : 'Configuration directory not writable'),
        'fix'    => null,
        'action' => $rtp_writable ? null
            : 'The RTP configuration file is not writable. Contact your system administrator to fix file permissions on the Asterisk configuration directory.',
    ];

    // 5. TURN block present in rtp_custom.conf
    $rtp_content   = $rtp_exists ? @file_get_contents($rtp_path) : false;
    $turn_block_ok = ($rtp_content !== false && strpos($rtp_content, 'qualid-turn-start') !== false);

    $turn_detail = 'Not found &mdash; media relay config is missing';
    if ($turn_block_ok) {
        if (preg_match('/turnusername=(\d+):/', $rtp_content, $m)) {
            $expires_in = (int)$m[1] - time();
            if ($expires_in > 3600) {
                $h = floor($expires_in / 3600);
                $turn_detail = "Active &mdash; credentials expire in {$h}h";
            } elseif ($expires_in > 0) {
                $min = ceil($expires_in / 60);
                $turn_detail = "Active &mdash; credentials expire in {$min}&nbsp;min (refreshing soon)";
            } else {
                $turn_detail = 'Active &mdash; credentials <b>expired</b>, will refresh on next cron run';
            }
        } else {
            $turn_detail = 'Active';
        }
    }

    $checks[] = [
        'id'     => 'turn_block',
        'label'  => 'Media relay configuration applied',
        'ok'     => $turn_block_ok,
        'detail' => $turn_detail,
        'fix'    => ($creds_ok && $rtp_writable) ? 'rtp_conf' : null,
        'action' => $turn_block_ok ? null
            : 'Media relay configuration is missing. Click <b>Fix</b> to apply it now, or wait up to 60&nbsp;s for the automatic watchdog.',
    ];

    // 6. Stale STUN/TURN overrides in rtp.conf
    $rtp_main_content = @file_get_contents('/etc/asterisk/rtp.conf');
    $stale_main = false;
    if ($rtp_main_content !== false) {
        $stale_main = (bool) preg_match('/\[general\]\(\+\)[^\[]*?(stunaddr|turnaddr)=/s', $rtp_main_content);
        if (!$stale_main) {
            $stale_main = (bool) preg_match('/^stunaddr=/m', $rtp_main_content);
        }
    }
    $checks[] = [
        'id'     => 'stale_rtp_main',
        'label'  => 'No conflicting RTP overrides',
        'ok'     => !$stale_main,
        'detail' => $stale_main ? 'Conflicting settings found &mdash; may cause call audio delays' : 'Clean',
        'fix'    => $stale_main ? 'overrides' : null,
        'action' => $stale_main
            ? 'Conflicting RTP settings are overriding the Quali-D configuration and may cause 10&ndash;20&nbsp;s call delays. Click <b>Fix</b> to remove them.'
            : null,
    ];

    // 7. No foreign STUN/TURN lines in rtp_custom.conf outside our sentinel block
    // (We own rtp_custom.conf for our [general](+) TURN block — we only check
    //  for foreign settings that someone may have added manually outside our block.)
    $rtp_custom_content = @file_get_contents('/etc/asterisk/rtp_custom.conf');
    // Strip our own sentinel block before scanning for foreign settings
    $custom_outside_block = ($rtp_custom_content !== false)
        ? preg_replace('/; -- qualid-turn-start --.*?; -- qualid-turn-end --\n?/s', '', $rtp_custom_content)
        : '';
    $stale_custom = !empty($custom_outside_block)
        && (bool) preg_match('/^(stunaddr|turnaddr|turnusername|turnpassword)=/m', $custom_outside_block);
    $checks[] = [
        'id'     => 'stale_rtp_custom',
        'label'  => 'No conflicting custom RTP settings',
        'ok'     => !$stale_custom,
        'detail' => $stale_custom
            ? 'Conflicting settings found outside Quali-D block &mdash; may override correct config'
            : ($rtp_custom_content === false ? 'Not present (OK)' : 'Clean'),
        'fix'    => $stale_custom ? 'overrides' : null,
        'action' => $stale_custom
            ? 'Conflicting RTP settings are overriding the Quali-D configuration. Click <b>Fix</b> to remove them.'
            : null,
    ];

    // 8. Cron watchdog configured
    // sync.php and deferred_rtp.php run from the asterisk user's crontab.
    // /etc/cron.d/qualid_remote is used only for the @reboot socat tunnel.
    $ast_crontab       = function_exists('shell_exec') ? (@shell_exec('crontab -u asterisk -l 2>/dev/null') ?: '') : '';
    $cron_has_sync     = strpos($ast_crontab, 'sync.php') !== false
                         && strpos($ast_crontab, 'qualid_remote') !== false;
    $cron_has_watchdog = strpos($ast_crontab, 'deferred_rtp.php') !== false
                         && strpos($ast_crontab, 'qualid_remote') !== false;
    $cron_file         = '/etc/cron.d/qualid_remote';
    $cron_content      = @file_get_contents($cron_file);
    $cron_has_reboot   = ($cron_content !== false) && strpos($cron_content, '@reboot') !== false;
    $cron_ok           = $cron_has_sync && $cron_has_watchdog;

    if (!$cron_has_sync && !$cron_has_watchdog) {
        $cron_detail = 'Not configured';
    } elseif (!$cron_ok) {
        $cron_detail = 'Outdated &mdash; automatic recovery entry missing';
    } else {
        $cron_detail = $cron_has_reboot ? 'Active (includes startup recovery)' : 'Active';
    }

    $checks[] = [
        'id'     => 'cron_watchdog',
        'label'  => 'Automatic recovery configured',
        'ok'     => $cron_ok,
        'detail' => $cron_detail,
        'fix'    => 'cron',
        'action' => $cron_ok ? null
            : (!$cron_present
                ? 'Automatic recovery is not configured. Click <b>Fix</b> to enable it.'
                : 'Automatic recovery configuration is outdated. Click <b>Fix</b> to update it.'),
    ];

    // 9. PJSIP trunk configured
    // Our module writes /etc/asterisk/pjsip_qualid.conf and includes it via
    // pjsip_custom_post.conf.  Check that primary file first, then fall back
    // to the other standard PJSIP config files.
    $pjsip_files  = [
        '/etc/asterisk/pjsip_qualid.conf',
        '/etc/asterisk/pjsip_additional.conf',
        '/etc/asterisk/pjsip_custom_post.conf',
        '/etc/asterisk/pjsip.conf',
        '/etc/asterisk/pjsip_custom.conf',
    ];
    $pjsip_ok     = false;
    $pjsip_source = '';
    foreach ($pjsip_files as $pf) {
        $pfc = @file_get_contents($pf);
        if ($pfc !== false && strpos($pfc, 'QualidRemote') !== false) {
            $pjsip_ok     = true;
            $pjsip_source = basename($pf);
            if (strpos($pfc, 'webrtc=yes') !== false) {
                $pjsip_source .= ' (WebRTC&nbsp;enabled)';
            }
            break;
        }
    }
    // Also check whether pjsip_qualid.conf is included from pjsip_custom_post.conf
    $pjsip_included = false;
    $post_conf = @file_get_contents('/etc/asterisk/pjsip_custom_post.conf');
    if ($post_conf !== false && strpos($post_conf, 'pjsip_qualid.conf') !== false) {
        $pjsip_included = true;
    }
    $pjsip_has_creds = !empty(qualid_get('trunk_user')) && !empty(qualid_get('trunk_pass'));
    // Build a clean detail string without internal file names
    if ($pjsip_ok) {
        $pjsip_webrtc = (strpos($pjsip_source, 'WebRTC') !== false);
        $pjsip_detail = $pjsip_webrtc ? 'Configured (WebRTC enabled)' : 'Configured';
    } else {
        $pjsip_detail = 'Not configured';
    }

    $checks[] = [
        'id'     => 'pjsip_trunk',
        'label'  => 'SIP trunk configured',
        'ok'     => $pjsip_ok,
        'detail' => $pjsip_detail,
        'fix'    => $pjsip_has_creds ? 'pjsip' : null,
        'action' => $pjsip_ok ? null
            : ($pjsip_has_creds
                ? 'SIP trunk configuration is missing. Click <b>Fix</b> to restore it.'
                : 'Trunk credentials are missing &mdash; Disconnect and log in again to re-provision.'),
    ];

    $failures = array_filter($checks, function ($c) { return !$c['ok']; });

    return [
        'success'    => true,
        'checks'     => $checks,
        'all_ok'     => empty($failures),
        'fail_count' => count($failures),
    ];
}

// ---------------------------------------------------------------------------
// Self-update from GitHub releases
// ---------------------------------------------------------------------------

/**
 * Check GitHub for a newer release than the currently installed version.
 */
function qualid_check_github_update() {
    $xml = @simplexml_load_file(QUALID_MODULE_DIR . '/module.xml');
    $currentVersion = $xml ? trim((string) $xml->version) : '0.0.0';

    $ch = curl_init('https://api.github.com/repos/' . QUALID_GITHUB_REPO . '/releases/latest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: qualid-freepbx-module'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || ($httpCode !== 200 && $httpCode !== 404)) {
        return ['success' => false, 'error' => 'Could not reach GitHub (HTTP ' . $httpCode . ')'];
    }
    if ($httpCode === 404) {
        return ['success' => true, 'update_available' => false, 'current_version' => $currentVersion,
                'latest_version' => $currentVersion, 'message' => 'No releases published yet'];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['tag_name'])) {
        return ['success' => false, 'error' => 'Invalid response from GitHub'];
    }

    $latestVersion  = ltrim($data['tag_name'], 'v');
    $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

    return [
        'success'          => true,
        'current_version'  => $currentVersion,
        'latest_version'   => $latestVersion,
        'update_available' => $updateAvailable,
    ];
}

/**
 * Download the latest release tar.gz from GitHub and extract it in place.
 */
function qualid_do_github_update() {
    $downloadUrl = 'https://github.com/' . QUALID_GITHUB_REPO . '/releases/latest/download/qualid_remote.tar.gz';
    $modulesDir  = dirname(QUALID_MODULE_DIR); // /var/www/html/admin/modules
    $tmp         = sys_get_temp_dir() . '/qualid_update_' . time() . '.tar.gz';

    // Download
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: qualid-freepbx-module'],
    ]);
    $data     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$data || $httpCode !== 200) {
        return ['success' => false, 'error' => 'Download failed (HTTP ' . $httpCode . ')'];
    }
    if (file_put_contents($tmp, $data) === false) {
        return ['success' => false, 'error' => 'Cannot write temp file'];
    }

    // Extract — try shell tar first, fall back to PharData
    $extracted = false;
    $out = function_exists('shell_exec') ? @shell_exec('tar -xzf ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg($modulesDir) . ' 2>&1') : null;
    if (file_exists(QUALID_MODULE_DIR . '/module.xml')) {
        $extracted = true;
    }

    if (!$extracted) {
        try {
            $phar = new PharData($tmp);
            $phar->extractTo($modulesDir, null, true);
            $extracted = file_exists(QUALID_MODULE_DIR . '/module.xml');
        } catch (Exception $e) {
            @unlink($tmp);
            return ['success' => false, 'error' => 'Extraction failed: ' . $e->getMessage()];
        }
    }

    @unlink($tmp);

    if (!$extracted) {
        return ['success' => false, 'error' => 'Extraction produced no files'];
    }

    // -- Step 3: run the new install.php so configs are regenerated -----------
    // FREEPBX_IS_AUTH is already defined (we're in the FreePBX web context).
    // Wrap in output buffering so any out() calls don't corrupt the JSON response.
    $install_php = QUALID_MODULE_DIR . '/install.php';
    if (file_exists($install_php)) {
        ob_start();
        @include $install_php;
        ob_end_clean();
    }

    // -- Step 4: update FreePBX's module registry -----------------------------
    // Without this, Module Admin keeps showing "upgrade available" even though
    // the files are already updated, forcing the user to click Upgrade → Process.
    $new_xml = @simplexml_load_file(QUALID_MODULE_DIR . '/module.xml');
    $new_ver = $new_xml ? trim((string) $new_xml->version) : '';
    if ($new_ver) {
        // No FreePBX modules DB on PBX OS — version is tracked in /etc/qualid/version.
        @file_put_contents('/etc/qualid/version', $new_ver . "\n");
    }

    // -- Step 5: reload Asterisk dialplan in the background -------------------
    @exec('/usr/sbin/asterisk -rx "dialplan reload" > /dev/null 2>&1 &');
    @exec('/usr/sbin/asterisk -rx "pjsip reload"    > /dev/null 2>&1 &');

    if (function_exists('needreload')) { needreload(); }
    return ['success' => true, 'new_version' => $new_ver];
}

// =============================================================================
// Outbound Dialer Sync
// =============================================================================

/**
 * Poll the API for pending outbound calls and write Asterisk call files.
 *
 * Called from sync.php every 5 minutes (same cron as everything else).
 *
 * API: GET /outbound/pending  (Bearer token auth)
 * Returns: { success: true, calls: [ { contact_id, campaign_id, phone_number,
 *                                       trunk, caller_id, wait_time }, ... ] }
 *
 * For each call a .call file is written to /var/spool/asterisk/outgoing/
 * using the atomic write-to-tmp-then-rename pattern so Asterisk never reads
 * a partially-written file.
 *
 * Retry logic lives entirely in the API (OutboundController::pendingCalls).
 * MaxRetries in the call file is always 0 — if a call fails to connect,
 * the contact goes back to 'pending' at the next poll cycle.
 *
 * @param string $token  Bearer token stored in config.json
 * @return array{ success: bool, dispatched: int }
 */
function qualid_sync_outbound($token) {
    $result = qualid_curl_get(
        QUALID_MAIN_API . '/outbound/pending',
        ['Authorization: Bearer ' . $token]
    );

    if (!isset($result['success']) || !$result['success']) {
        return ['success' => false, 'dispatched' => 0, '_error' => json_encode($result)];
    }

    $calls      = $result['calls'] ?? [];
    $dispatched = 0;
    $spool_dir  = '/var/spool/asterisk/outgoing';
    $tmp_dir    = '/var/spool/asterisk/tmp';

    // Make sure the tmp dir exists (it normally does on FreePBX)
    if (!is_dir($tmp_dir)) {
        @mkdir($tmp_dir, 0770, true);
    }

    foreach ($calls as $call) {
        $contact_id  = (int)  ($call['contact_id']   ?? 0);
        $campaign_id = (int)  ($call['campaign_id']   ?? 0);
        $phone       = trim((string) ($call['phone_number'] ?? ''));
        $trunk       = trim((string) ($call['trunk']        ?? 'WE-Landline-FXO'));
        $caller_id   = trim((string) ($call['caller_id']    ?? ''));
        $wait_time   = (int)  ($call['wait_time']     ?? 30);

        if (!$contact_id || !$campaign_id || !$phone) continue;

        // Channel: PJSIP/<destination>@<trunk_endpoint>
        $channel = 'PJSIP/' . $phone . '@' . $trunk;

        // Caller ID line (optional — leave blank to use trunk default)
        $callerid_line = $caller_id
            ? 'Callerid: Outbound <' . $caller_id . '>'
            : '';

        $call_file_content = implode("\n", array_filter([
            'Channel: '     . $channel,
            'MaxRetries: 0',
            'RetryTime: 60',
            'WaitTime: '    . $wait_time,
            'Context: qualid-outbound-ivr',
            'Extension: s',
            'Priority: 1',
            $callerid_line,
            'Setvar: CONTACT_ID='  . $contact_id,
            'Setvar: CAMPAIGN_ID=' . $campaign_id,
            'Setvar: CALLED_NUM='  . $phone,
            '',   // trailing newline
        ]));

        // Write atomically: tmp file → rename into outgoing/
        $tmp_file  = $tmp_dir  . '/qualid_ob_' . $contact_id . '_' . time() . '.call';
        $dest_file = $spool_dir . '/qualid_ob_' . $contact_id . '_' . time() . '.call';

        if (file_put_contents($tmp_file, $call_file_content) === false) continue;
        // sync.php runs as root — chown to asterisk so Asterisk can open the file.
        @chown($tmp_file, 'asterisk');
        @chgrp($tmp_file, 'asterisk');
        @chmod($tmp_file, 0640);

        if (!rename($tmp_file, $dest_file)) {
            @unlink($tmp_file);
            continue;
        }

        $dispatched++;
    }

    return ['success' => true, 'dispatched' => $dispatched];
}

/**
 * Read all configured trunks from FreePBX and push them to the QUALI-D API
 * so the outbound campaign form can offer a dropdown instead of a free-text field.
 *
 * Tries four strategies in order, stopping at the first non-empty result:
 *   1. Parse /etc/asterisk/pjsip_additional.conf  — most reliable; FreePBX
 *      regenerates it on every Apply Config. Filters out extension sections
 *      (context=from-internal) and purely numeric section names.
 *   2. asterisk -rx "pjsip show endpoints"         — live CLI; catches endpoints
 *      that exist in Asterisk but are not in the DB yet.
 *   3. FreePBX pjsip DB table with context filter  — modern PJSIP-only installs.
 *   4. FreePBX trunks DB table                     — legacy SIP / DAHDI / any
 *      trunk configured through the FreePBX Trunks UI.
 *
 * @param string $token  Bearer token from config.json
 * @return array
 */
function qualid_sync_trunks($token) {
    $trunks = [];
    $tried  = [];

    // ── Strategy 1: parse /etc/asterisk/pjsip_additional.conf ────────────────
    // FreePBX regenerates this file on every "Apply Config".
    // Trunk endpoint sections have context != from-internal (extensions do).
    $tried[] = 'pjsip_additional.conf';
    $pjsip_conf = '/etc/asterisk/pjsip_additional.conf';
    if (file_exists($pjsip_conf)) {
        $raw = @file_get_contents($pjsip_conf);
        if ($raw !== false) {
            // Split into INI sections — each starts with [section-name]
            $sections = preg_split('/^(?=\[)/m', $raw, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($sections as $block) {
                if (!preg_match('/^\[([^\]]+)\]/', $block, $nm)) continue;
                $name = trim($nm[1]);
                // Must be a type=endpoint section
                if (!preg_match('/^\s*type\s*=\s*endpoint\s*$/m', $block)) continue;
                // Skip extension sections (context=from-internal)
                if (preg_match('/^\s*context\s*=\s*from-internal\s*$/m', $block)) continue;
                // Skip purely numeric names (extension numbers)
                if (preg_match('/^\d+$/', $name)) continue;
                // Skip registration/auth helper sections
                if (preg_match('/-reg(istration)?$|-auth$/i', $name)) continue;
                // Skip PJSIP template sections (start with '<')
                if ($name[0] === '<') continue;
                // Skip this module's own relay endpoint
                if (stripos($name, 'qualid') !== false) continue;
                $trunks[] = ['name' => $name, 'display_name' => $name, 'tech' => 'pjsip'];
            }
        }
    }

    // ── Strategy 2: asterisk -rx "pjsip show endpoints" ──────────────────────
    if (empty($trunks)) {
        $tried[] = 'asterisk_cli';
        $lines = [];
        @exec('/usr/sbin/asterisk -rx "pjsip show endpoints" 2>/dev/null', $lines);
        foreach ($lines as $line) {
            // Output format: "  Endpoint:  <name>/<number>  <status>"
            if (!preg_match('/^\s+Endpoint:\s+(\S+?)(?:\/\S+)?\s/', $line, $m)) continue;
            $ep = $m[1];
            if (preg_match('/^\d+$/', $ep)) continue;
            if (preg_match('/-reg(istration)?$|-auth$/i', $ep)) continue;
            if ($ep[0] === '<') continue;
            if (stripos($ep, 'qualid') !== false) continue;
            $trunks[] = ['name' => $ep, 'display_name' => $ep, 'tech' => 'pjsip'];
        }
    }

    // ── Strategy 3: FreePBX pjsip DB table — not available on PBX OS ─────────
    // (No FreePBX database; conf file and CLI are the only sources.)

    // ── Strategy 4: FreePBX trunks DB table — not available on PBX OS ────────
    // (Strategies 1 and 2 — conf file and CLI — are sufficient.)

    if (empty($trunks)) {
        return ['success' => false, 'tried' => $tried, 'error' => 'No trunks found via any strategy'];
    }

    $result = qualid_curl_post(
        QUALID_MAIN_API . '/outbound/trunks/sync',
        ['trunks' => $trunks, 'device_uuid' => qualid_get_device_uuid()],
        ['Authorization: Bearer ' . $token]
    );

    if (!is_array($result)) {
        return ['success' => false, 'tried' => $tried, 'error' => 'API call failed'];
    }
    return array_merge($result, ['tried' => $tried, 'found' => count($trunks)]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Call Recording Upload
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Find completed call recordings in /tmp/ and upload them to the QUALI-D API.
 *
 * The AGI scripts (qualid_ivr.php / qualid_outbound_ivr.php) write two files
 * per call:
 *   /tmp/qualid_rec_{uniqueid}.wav   — recorded audio (MixMonitor)
 *   /tmp/qualid_rec_{uniqueid}.json  — metadata: { session_id, call_type }
 *
 * We wait until the WAV file has been untouched for 60 seconds to ensure the
 * call has fully ended and MixMonitor has flushed its final buffer.
 *
 * On successful upload both files are deleted.  On failure they are left in
 * place and retried on the next 5-minute sync cycle.
 */
function qualid_sync_recordings($token) {
    $json_files = glob('/tmp/qualid_rec_*.json') ?: [];

    foreach ($json_files as $json_file) {
        $wav_file = preg_replace('/\.json$/', '.wav', $json_file);

        // WAV file not present yet — MixMonitor may still be initialising
        if (!file_exists($wav_file)) continue;

        // File still being written (call in progress) — skip for now
        if ((time() - filemtime($wav_file)) < 60) continue;

        $file_size = filesize($wav_file);

        // Trivially small file = silence / failed recording — discard both files
        if ($file_size < 1000) {
            @unlink($wav_file);
            @unlink($json_file);
            continue;
        }

        $meta = json_decode(@file_get_contents($json_file), true);
        if (empty($meta['session_id'])) {
            @unlink($json_file);
            continue;
        }

        $agi_secret = qualid_get('agi_secret', '');
        if (!$agi_secret) continue; // Not configured yet

        $ch = curl_init(QUALID_MAIN_API . '/ivr/agi/recording-upload');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'session_id' => (string) $meta['session_id'],
                'call_type'  => isset($meta['call_type']) ? $meta['call_type'] : 'inbound',
                'recording'  => new CURLFile($wav_file, 'audio/wav', 'recording.wav'),
            ],
            CURLOPT_HTTPHEADER     => ['X-AGI-Secret: ' . $agi_secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $code !== 200) continue; // Retry next cycle

        $resp = json_decode($raw ?: '{}', true);
        if (!empty($resp['success'])) {
            @unlink($wav_file);
            @unlink($json_file);
        }
    }
}

// =============================================================================
// AMI monitor — write manager_qualid.conf for the ami_bridge daemon
// =============================================================================

function qualid_ensure_ami_secret() {
    $secret = qualid_get('ami_monitor_secret', '');
    if (!empty($secret)) return $secret;

    $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $secret = '';
    for ($i = 0; $i < 24; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }
    qualid_set('ami_monitor_secret', $secret);
    return $secret;
}

function qualid_write_manager_conf() {
    $secret = qualid_ensure_ami_secret();
    $conf_path = '/etc/asterisk/manager_qualid.conf';

    $conf  = "; Quali-D AMI monitor — auto-generated by qualid sync. DO NOT EDIT manually.\n";
    $conf .= "; Included by /etc/asterisk/manager.conf via #include.\n\n";
    $conf .= "[qualid-monitor]\n";
    $conf .= "secret = {$secret}\n";
    $conf .= "deny = 0.0.0.0/0.0.0.0\n";
    $conf .= "permit = 127.0.0.1/255.255.255.255\n";
    $conf .= "read = system,call,agent,user\n";
    $conf .= "write = system,call\n";

    file_put_contents($conf_path, $conf);
    chmod($conf_path, 0664);

    @shell_exec('/usr/sbin/asterisk -rx "manager reload" 2>/dev/null');
}
