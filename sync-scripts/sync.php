<?php
/**
 * Quali-D PBX OS — Background Sync Script
 * Triggered every 5 minutes by qualid-sync.timer (systemd).
 * Syncs extensions, queues, CDR, and trunks with the QUALI-D cloud.
 *
 * Runs as the asterisk user, which has read/write access to
 * /etc/asterisk and /etc/qualid.
 */

define('FREEPBX_IS_AUTH', 1);

// Load sync functions — no FreePBX dependency on PBX OS
require_once(dirname(__FILE__) . '/functions.inc.php');

$token = qualid_get('token');
if (empty($token)) {
    exit(0); // Not connected — nothing to do
}

qualid_write_rtp_conf();   // Rotate TURN credentials (24h TTL, refreshed every 5 min)

// Regenerate pjsip_qualid.conf on every sync so module updates that change the
// PJSIP config (new endpoints, transports, identify blocks, etc.) take effect
// automatically without requiring a manual reconnect or reactivation.
// Only reloads PJSIP when the file actually changed to avoid disrupting live calls.
$_pjsip_cfg = qualid_load_config();
if (!empty($_pjsip_cfg['trunk_user']) && !empty($_pjsip_cfg['trunk_pass'])) {
    $_pjsip_before = @file_get_contents('/etc/asterisk/pjsip_qualid.conf');
    qualid_write_pjsip($_pjsip_cfg);
    $_pjsip_after = @file_get_contents('/etc/asterisk/pjsip_qualid.conf');
    if ($_pjsip_before !== $_pjsip_after) {
        @exec('/usr/sbin/asterisk -rx "pjsip reload" > /dev/null 2>&1');
    }
}
unset($_pjsip_cfg, $_pjsip_before, $_pjsip_after);

qualid_push_pbx_host($token);
qualid_sync_extensions($token);
qualid_sync_queues($token);
qualid_sync_gateways($token);
qualid_sync_cdr_to_qualid($token);
qualid_send_heartbeat($token);
qualid_sync_outbound($token);    // Dispatch pending outbound campaign calls
qualid_sync_trunks($token);      // Push trunk list for campaign dropdown
qualid_sync_recordings($token);  // Upload completed call recordings to B2
