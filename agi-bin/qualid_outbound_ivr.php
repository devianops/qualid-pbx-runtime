#!/usr/bin/env php
<?php
/**
 * qualid_outbound_ivr.php — Outbound IVR AGI Script
 *
 * Deployed to: /var/lib/asterisk/agi-bin/qualid_outbound_ivr.php
 * (same directory as qualid_ivr.php — installed by qualid_write_agi_files())
 *
 * Called by Asterisk via the dialplan context [qualid-outbound-ivr]
 * when an outbound campaign call is answered by the destination.
 *
 * Dialplan call:
 *   AGI(qualid_outbound_ivr.php,${CONTACT_ID},${CAMPAIGN_ID})
 *
 * Call file must set:
 *   Setvar: CONTACT_ID=<id>
 *   Setvar: CAMPAIGN_ID=<id>
 *   Setvar: CALLED_NUM=<phone>   (fallback if agi_dnid is unreliable)
 *
 * Flow:
 *   1. POST /outbound/agi/start  → session_id (ivr mirror) + ob_session_id + first instruction
 *   2. Execute instruction (play audio, collect digits, etc.)
 *   3. POST /ivr/agi/step        → next instruction  (reuses INBOUND engine — zero changes there)
 *   4. Repeat steps 2-3 until hangup/transfer/queue/done
 *   5. POST /outbound/agi/end    → close sessions, mark contact done
 *
 * Config: /etc/asterisk/qualid_ivr.conf  (same file as inbound — written by qualid_write_agi_files)
 *   api_base   = https://api.quali-d.com
 *   agi_secret = <secret>
 */

declare(strict_types=1);

// ── 1. Read AGI environment from stdin ──────────────────────────────────────
$agi = [];
while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line);
    if ($line === '') break;
    $pos = strpos($line, ':');
    if ($pos !== false) {
        $agi[substr($line, 0, $pos)] = trim(substr($line, $pos + 1));
    }
}

// ── 2. AGI command helpers ───────────────────────────────────────────────────

function agi_cmd(string $cmd): string
{
    fwrite(STDOUT, $cmd . "\n");
    fflush(STDOUT);
    return rtrim((string) fgets(STDIN));
}

function agi_verbose(string $msg): void
{
    agi_cmd('VERBOSE "' . str_replace('"', '\\"', $msg) . '" 1');
}

function agi_get_var(string $name): string
{
    $resp = agi_cmd("GET VARIABLE $name");
    // "200 result=1 (value)"  or  "200 result=0"
    if (preg_match('/\(([^)]*)\)/', $resp, $m)) {
        return $m[1];
    }
    return '';
}

function agi_hangup(): void
{
    agi_cmd('HANGUP');
}

// ── 3. Load config ───────────────────────────────────────────────────────────

$conf_file = '/etc/asterisk/qualid_ivr.conf';
$conf      = @parse_ini_file($conf_file, false, INI_SCANNER_TYPED);
if (!is_array($conf)) {
    agi_verbose('[outbound] FATAL: cannot read ' . $conf_file);
    agi_hangup();
    exit(1);
}

// Config key is 'api_base' (written by qualid_write_agi_files in functions.inc.php)
$api_base   = rtrim((string) ($conf['api_base']    ?? ''), '/');
$agi_secret = (string) ($conf['agi_secret']        ?? '');

if (!$api_base || !$agi_secret) {
    agi_verbose('[outbound] FATAL: api_base or agi_secret missing in qualid_ivr.conf');
    agi_hangup();
    exit(1);
}

// ── 4. Extract call parameters ───────────────────────────────────────────────

// agi_arg_1 / agi_arg_2 come from: AGI(qualid_outbound_ivr.php,${CONTACT_ID},${CAMPAIGN_ID})
$contact_id  = (int) ($agi['agi_arg_1'] ?? 0);
$campaign_id = (int) ($agi['agi_arg_2'] ?? 0);

// The number we dialled — agi_dnid is the destination for call-file calls
$called_num  = $agi['agi_dnid'] ?? '';
if (!$called_num || $called_num === 'unknown') {
    $called_num = agi_get_var('CALLED_NUM');   // Setvar fallback from call file
}

if (!$contact_id || !$campaign_id) {
    agi_verbose("[outbound] FATAL: contact_id=$contact_id campaign_id=$campaign_id — missing args");
    agi_hangup();
    exit(1);
}

agi_verbose("[outbound] Starting — contact=$contact_id campaign=$campaign_id number=$called_num");

// ── 5. HTTP helper ───────────────────────────────────────────────────────────

$base_headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'X-AGI-Secret: ' . $agi_secret,
];

/**
 * POST JSON to $url; returns decoded response or ['success' => false].
 * @param array<string> $headers
 * @return array<string, mixed>
 */
function ob_post(string $url, array $payload, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$raw) {
        return ['success' => false, '_curl_error' => $err];
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : ['success' => false, '_raw' => substr((string)$raw, 0, 200)];
}

/**
 * Download an audio file from $url to a temp MP3 file.
 * Returns the full path (with .mp3 extension) on success, or null on failure.
 */
function ob_download_audio(string $url, array $headers): ?string
{
    $tmp  = tempnam(sys_get_temp_dir(), 'qualid_ob_') . '.mp3';
    $fh   = fopen($tmp, 'wb');
    if (!$fh) return null;

    // Strip Content-Type header — this is a GET download request
    $dl_headers = array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'Content-Type:')));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_HTTPHEADER     => $dl_headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($err || $code < 200 || $code >= 300 || filesize($tmp) < 100) {
        @unlink($tmp);
        return null;
    }
    return $tmp;
}

/**
 * Convert an MP3 file to 8kHz mono WAV for Asterisk STREAM FILE.
 * Returns base path WITHOUT extension (e.g. /tmp/qualid_ob_abc) on success,
 * or null on failure.
 * Uses mpg123 (primary — always on Asterisk boxes) or sox (fallback).
 */
function ob_convert_to_wav(string $mp3_file): ?string
{
    if (!$mp3_file || !file_exists($mp3_file)) return null;
    $base     = preg_replace('/\.mp3$/', '', $mp3_file);
    $wav_file = $base . '.wav';

    // Primary: mpg123 (guaranteed present — app_mp3 depends on it)
    exec(sprintf('mpg123 -q -m -r 8000 -w %s %s 2>/dev/null',
        escapeshellarg($wav_file), escapeshellarg($mp3_file)), $out, $ret);
    if ($ret === 0 && file_exists($wav_file) && filesize($wav_file) > 0) {
        return $base;
    }

    // Fallback: sox
    exec(sprintf('sox %s -r 8000 -c 1 -e signed-integer -b 16 %s 2>/dev/null',
        escapeshellarg($mp3_file), escapeshellarg($wav_file)), $out, $ret);
    if ($ret === 0 && file_exists($wav_file) && filesize($wav_file) > 0) {
        return $base;
    }

    @unlink($wav_file);
    return null;
}

/**
 * Download audio from URL, convert to WAV, play via STREAM FILE, clean up.
 * Returns '' = played OK, 'hangup' = customer hung up, 'skip' = no audio URL.
 */
function ob_play_url(string $url, array $headers): string
{
    if (!$url) return 'skip';

    $mp3 = ob_download_audio($url, $headers);
    if (!$mp3) {
        agi_verbose("[outbound] Warning: could not download audio: $url");
        return 'skip';
    }

    $wav_base = ob_convert_to_wav($mp3);
    @unlink($mp3);

    if (!$wav_base) {
        agi_verbose("[outbound] Warning: could not convert audio to WAV");
        return 'skip';
    }

    // STREAM FILE returns digit ASCII on barge-in, 0 on completion, -1 on hangup
    $resp = agi_cmd('STREAM FILE "' . addslashes($wav_base) . '" ""');
    @unlink($wav_base . '.wav');

    return (strpos($resp, 'result=-1') !== false) ? 'hangup' : '';
}

// ── 6. POST /outbound/agi/start ──────────────────────────────────────────────

$start_resp = ob_post($api_base . '/outbound/agi/start', [
    'contact_id'    => $contact_id,
    'campaign_id'   => $campaign_id,
    'called_number' => $called_num,
], $base_headers);

if (empty($start_resp['success'])) {
    agi_verbose('[outbound] agiStart failed: ' . json_encode($start_resp));
    agi_hangup();
    exit(1);
}

/** @var int $session_id     — ivr_call_sessions.id, used for /ivr/agi/step */
$session_id    = (int) $start_resp['session_id'];
/** @var int $ob_session_id  — outbound_call_sessions.id, used for /outbound/agi/end */
$ob_session_id = (int) $start_resp['ob_session_id'];
/** @var array<string,mixed> $instruction */
$instruction   = $start_resp['instruction'] ?? ['action' => 'hangup', 'reason' => 'no_instruction'];

agi_verbose("[outbound] Sessions: ivr=$session_id ob=$ob_session_id");

// ── 7. Instruction execution loop ────────────────────────────────────────────

$max_steps    = 50;
$step_num     = 0;
$hangup_cause = 'completed';
$event        = 'done';
$event_data   = null;

while ($step_num++ <= $max_steps) {
    $action = $instruction['action'] ?? 'hangup';
    agi_verbose("[outbound] step=$step_num action=$action");

    // ── hangup ──────────────────────────────────────────────────────────────
    if ($action === 'hangup') {
        $hangup_cause = 'completed';
        break;
    }

    // ── play ─────────────────────────────────────────────────────────────────
    if ($action === 'play') {
        $result = ob_play_url($instruction['url'] ?? '', $base_headers);
        if ($result === 'hangup') { $hangup_cause = 'customer_hungup'; break; }
        $event      = 'done';
        $event_data = null;

    // ── get_digits (single digit menu) ───────────────────────────────────────
    } elseif ($action === 'get_digits') {
        $url     = $instruction['url']     ?? '';
        $timeout = (int) ($instruction['timeout'] ?? 5);
        $retries = (int) ($instruction['retries'] ?? 3);

        $digits = '';
        $hungup = false;
        for ($try = 0; $try < $retries; $try++) {
            $mp3      = $url ? ob_download_audio($url, $base_headers) : null;
            $wav_base = $mp3 ? ob_convert_to_wav($mp3) : null;
            if ($mp3) @unlink($mp3);

            // GET DATA plays the prompt and waits for a single digit
            $resp = agi_cmd(
                'GET DATA "' . ($wav_base ?: 'silence/1') . '" '
                . ($timeout * 1000) . ' 1'
            );
            if ($wav_base) @unlink($wav_base . '.wav');

            if (strpos($resp, 'result=-1') !== false) { $hungup = true; break; }
            // result=<ASCII code>  e.g. result=49 for '1'
            if (preg_match('/result=(\d+)/', $resp, $m) && (int)$m[1] > 0) {
                $digits = chr((int)$m[1]);
                break;
            }
            // result=0 = timeout — retry
        }

        if ($hungup) { $hangup_cause = 'customer_hungup'; break; }
        $event      = 'dtmf';
        $event_data = $digits;

    // ── collect_input (multi-digit entry, e.g. phone number) ─────────────────
    } elseif ($action === 'collect_input') {
        $url        = $instruction['url']        ?? '';
        $num_digits = (int) ($instruction['num_digits'] ?? 11);
        $timeout    = (int) ($instruction['timeout']    ?? 10);
        $retries    = (int) ($instruction['retries']    ?? 2);

        $digits = '';
        $hungup = false;
        for ($try = 0; $try < $retries; $try++) {
            $mp3      = $url ? ob_download_audio($url, $base_headers) : null;
            $wav_base = $mp3 ? ob_convert_to_wav($mp3) : null;
            if ($mp3) @unlink($mp3);

            $resp = agi_cmd(
                'GET DATA "' . ($wav_base ?: 'silence/1') . '" '
                . ($timeout * 1000) . ' ' . $num_digits
            );
            if ($wav_base) @unlink($wav_base . '.wav');

            if (strpos($resp, 'result=-1') !== false) { $hungup = true; break; }
            if (preg_match('/result=(\d+)/', $resp, $m) && (int)$m[1] > 0) {
                $digits = $m[1];
                break;
            }
        }

        if ($hungup) { $hangup_cause = 'customer_hungup'; break; }
        $event      = 'input';
        $event_data = $digits;

    // ── wait (silence pause) ─────────────────────────────────────────────────
    } elseif ($action === 'wait') {
        $duration = max(1, (int) ($instruction['duration'] ?? 2));
        agi_cmd("EXEC Wait $duration");
        $event      = 'done';
        $event_data = null;

    // ── transfer (blind transfer to extension) ────────────────────────────────
    } elseif ($action === 'transfer') {
        $ext     = $instruction['extension'] ?? '';
        $context = $instruction['context']   ?? 'from-internal';
        if ($ext) {
            agi_cmd("SET CONTEXT $context");
            agi_cmd("SET EXTENSION $ext");
            agi_cmd("SET PRIORITY 1");
        }
        $hangup_cause = 'transferred';
        break;

    // ── queue (park in Asterisk queue) ────────────────────────────────────────
    } elseif ($action === 'queue') {
        $queue   = $instruction['queue_name'] ?? '';
        $timeout = (int) ($instruction['timeout'] ?? 60);
        if ($queue) {
            agi_cmd('EXEC Queue "' . addslashes($queue) . ',,,,' . $timeout . '"');
        }
        $hangup_cause = 'queue_answered';
        break;

    // ── record_speech / record_voicemail ─────────────────────────────────────
    } elseif (in_array($action, ['record_speech', 'record_voicemail'], true)) {
        $max_dur = (int) ($instruction['max_duration'] ?? 30);
        $tmp     = tempnam(sys_get_temp_dir(), 'qualid_ob_rec_');
        // Record wav; '#' stops; 3s silence timeout
        agi_cmd('RECORD FILE "' . $tmp . '" wav "#" ' . ($max_dur * 1000) . ' BEEP s=3');
        @unlink($tmp);
        @unlink($tmp . '.wav');
        $event      = 'done';
        $event_data = null;

    // ── unknown ───────────────────────────────────────────────────────────────
    } else {
        agi_verbose("[outbound] Unknown action '$action' — treating as done");
        $event      = 'done';
        $event_data = null;
    }

    // ── Fetch next instruction from inbound engine (/ivr/agi/step) ───────────
    $step_resp = ob_post($api_base . '/ivr/agi/step', [
        'session_id' => $session_id,
        'event'      => $event,
        'event_data' => $event_data,
    ], $base_headers);

    if (empty($step_resp['success'])) {
        agi_verbose('[outbound] /ivr/agi/step failed: ' . json_encode($step_resp));
        $hangup_cause = 'step_error';
        break;
    }

    $instruction = $step_resp['instruction'] ?? ['action' => 'hangup', 'reason' => 'no_instruction'];
}

agi_verbose("[outbound] Loop ended — hangup_cause=$hangup_cause");

// ── 8. POST /outbound/agi/end ────────────────────────────────────────────────
if ($ob_session_id) {
    ob_post($api_base . '/outbound/agi/end', [
        'ob_session_id' => $ob_session_id,
        'session_id'    => $session_id,
        'hangup_cause'  => $hangup_cause,
    ], $base_headers);
}

agi_hangup();
exit(0);
