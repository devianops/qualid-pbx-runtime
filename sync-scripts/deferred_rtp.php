<?php
/**
 * Lightweight RTP watchdog — runs every minute via cron.
 *
 * Checks whether the TURN config block exists in rtp_custom.conf and
 * whether the socat tunnel is running.  Only does work when something is
 * missing, so the per-minute schedule is essentially free on idle systems.
 */
define('FREEPBX_IS_AUTH', 1);
require __DIR__ . '/functions.inc.php';

$token = qualid_get('token');
if (empty($token)) {
    exit(0); // Not connected
}

// Quick check: is the qualid TURN block present in rtp_custom.conf?
$rtp = @file_get_contents('/etc/asterisk/rtp_custom.conf');
$needs_rtp = ($rtp === false || strpos($rtp, 'qualid-turn-start') === false);

// Quick check: is socat listening on port 3479?
exec('pgrep -f "socat.*UDP4-LISTEN:3479" 2>/dev/null', $pids);
$needs_tunnel = empty($pids);

if ($needs_rtp || $needs_tunnel) {
    qualid_write_rtp_conf();
}
