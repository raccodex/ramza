<?php
/**
 * Privacy-conscious runtime diagnostics for Ramza.
 * The collector is local by default. Sending requires an explicit admin setting.
 */

function Ramza_DiagnosticsRedact($value)
{
    $value = str_replace("\0", '', (string) $value);
    $patterns = array(
        '/(authorization\s*:\s*(?:bearer|basic)\s+)[^\s]+/i',
        '/((?:api[_ -]?key|token|secret|password|passphrase|purchase[_ -]?code)\s*[=:]\s*)[^\s,;]+/i',
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i'
    );
    $replacements = array('$1[redacted]', '$1[redacted]', '[email-redacted]');
    $value = preg_replace($patterns, $replacements, $value);
    return preg_replace('/[^\P{C}\t\r\n]/u', '', $value);
}

function Ramza_DiagnosticsLogExcerpt($path, $limit = 32768)
{
    $root = realpath(dirname(__DIR__, 2));
    $real = is_file($path) ? realpath($path) : false;
    if ($root === false || $real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    $size = filesize($real);
    $handle = @fopen($real, 'rb');
    if (!$handle) {
        return '';
    }
    if ($size > $limit) {
        fseek($handle, -$limit, SEEK_END);
    }
    $contents = stream_get_contents($handle);
    fclose($handle);
    return Ramza_DiagnosticsRedact($contents ?: '');
}

function Ramza_DiagnosticsSnapshot($includeLogs = null)
{
    global $wo, $sqlConnect;
    if ($includeLogs === null) {
        $includeLogs = !empty($wo['config']['diagnostic_include_logs']);
    }
    $root = dirname(__DIR__, 2);
    $ffmpeg = function_exists('Ramza_FfmpegStatus') ? Ramza_FfmpegStatus() : array('available' => false, 'resolved' => '', 'message' => 'Not checked');
    $turn = function_exists('Ramza_WebRtcTurnStatus') ? Ramza_WebRtcTurnStatus() : array('mode' => 'disabled', 'host' => '', 'configured' => false);
    $dbVersion = '';
    if ($sqlConnect instanceof mysqli) {
        $dbVersion = (string) $sqlConnect->server_info;
    }
    $writable = array();
    foreach (array('cache', 'upload', 'upload/photos', 'upload/videos') as $directory) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        $writable[$directory] = is_dir($path) && is_writable($path);
    }
    $snapshot = array(
        'schema' => 1,
        'generated_at' => gmdate('c'),
        'installation' => array(
            'site_host' => parse_url((string) ($wo['config']['site_url'] ?? ''), PHP_URL_HOST),
            'version' => (string) ($wo['config']['version'] ?? '1.0'),
            'theme' => (string) ($wo['config']['theme'] ?? ''),
        ),
        'runtime' => array(
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'database' => $dbVersion,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'extensions' => array(
                'curl' => extension_loaded('curl'),
                'fileinfo' => extension_loaded('fileinfo'),
                'gd' => extension_loaded('gd'),
                'mbstring' => extension_loaded('mbstring'),
                'mysqli' => extension_loaded('mysqli'),
                'openssl' => extension_loaded('openssl'),
            ),
            'writable' => $writable,
        ),
        'media' => array(
            'ffmpeg_available' => !empty($ffmpeg['available']),
            'ffmpeg_binary' => !empty($ffmpeg['resolved']) ? basename((string) $ffmpeg['resolved']) : '',
            'ffmpeg_message' => (string) ($ffmpeg['message'] ?? ''),
            'remote_storage' => function_exists('Wo_IsRemoteStorageEnabled') ? Wo_IsRemoteStorageEnabled() : false,
        ),
        'realtime' => array(
            'call_provider' => function_exists('Ramza_CallProvider') ? Ramza_CallProvider() : 'disabled',
            'live_provider' => function_exists('Ramza_LiveProvider') ? Ramza_LiveProvider() : 'disabled',
            'turn_mode' => (string) ($turn['mode'] ?? 'disabled'),
            'turn_host' => (string) ($turn['host'] ?? ''),
            'turn_configured' => !empty($turn['configured']),
        ),
    );
    if ($includeLogs) {
        $logs = array();
        foreach (array('error_log', 'cache/error_log', 'admin-panel/error_log', 'upload/error_log') as $relative) {
            $excerpt = Ramza_DiagnosticsLogExcerpt($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if ($excerpt !== '') {
                $logs[$relative] = $excerpt;
            }
        }
        $snapshot['logs'] = $logs;
    }
    return $snapshot;
}

function Ramza_DiagnosticsSend($force = false, $feedback = array())
{
    global $wo;
    $enabled = !empty($wo['config']['diagnostic_reporting_system']);
    if (!$enabled && !$force) {
        return array('ok' => false, 'message' => 'Diagnostic reporting is disabled.');
    }
    $url = trim((string) ($wo['config']['developer_portal_url'] ?? ''));
    $token = trim((string) ($wo['config']['developer_portal_token'] ?? ''));
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $local = in_array($host, array('localhost', '127.0.0.1', '::1'), true);
    if ($url === '' || $token === '' || ($scheme !== 'https' && !($local && $scheme === 'http'))) {
        return array('ok' => false, 'message' => 'Set an HTTPS developer portal URL and token.');
    }
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'message' => 'The cURL extension is required.');
    }
    $snapshot = Ramza_DiagnosticsSnapshot();
    if (is_array($feedback) && (!empty($feedback['subject']) || !empty($feedback['message']))) {
        $snapshot['feedback'] = array(
            'subject' => Ramza_DiagnosticsRedact(substr(trim((string) ($feedback['subject'] ?? '')), 0, 120)),
            'message' => Ramza_DiagnosticsRedact(substr(trim((string) ($feedback['message'] ?? '')), 0, 4000)),
        );
    }
    $payload = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $token);
    $curl = curl_init($url);
    curl_setopt_array($curl, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'X-Ramza-Timestamp: ' . $timestamp,
            'X-Ramza-Signature: sha256=' . $signature,
        ),
    ));
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $ok = $status >= 200 && $status < 300;
    if (function_exists('Wo_SaveConfig')) {
        Wo_SaveConfig('diagnostic_last_sent_at', $ok ? time() : (int) ($wo['config']['diagnostic_last_sent_at'] ?? 0));
        Wo_SaveConfig('diagnostic_last_status', $ok ? 'Delivered' : ('HTTP ' . $status . ($error ? ': ' . $error : '')));
    }
    return array('ok' => $ok, 'status' => $status, 'message' => $ok ? 'Report delivered.' : ($error ?: ('Portal returned HTTP ' . $status . '.')), 'response' => Ramza_DiagnosticsRedact(substr((string) $response, 0, 500)));
}

function Ramza_DiagnosticsMaybeSend()
{
    global $wo;
    if (empty($wo['config']['diagnostic_reporting_system']) || empty($wo['config']['diagnostic_auto_send'])) {
        return false;
    }
    $last = (int) ($wo['config']['diagnostic_last_sent_at'] ?? 0);
    if ($last > (time() - 43200)) {
        return false;
    }
    return Ramza_DiagnosticsSend(false);
}
