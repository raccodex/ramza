<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtimeConfig = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS' . DIRECTORY_SEPARATOR . 'runtime_config';
$runtimeLogDir = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS' . DIRECTORY_SEPARATOR . 'runtime_logs';

if (!is_dir($runtimeLogDir)) {
    mkdir($runtimeLogDir, 0775, true);
}

set_include_path($runtimeConfig . PATH_SEPARATOR . get_include_path());
ini_set('log_errors', '1');
ini_set('error_log', $runtimeLogDir . DIRECTORY_SEPARATOR . 'php82-web-sapi-error.log');
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    $root = dirname(__DIR__, 2);
    $logFile = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS' . DIRECTORY_SEPARATOR . 'runtime_logs' . DIRECTORY_SEPARATOR . 'php82-web-sapi-captured.log';
    $entry = json_encode([
        'time' => date('c'),
        'severity' => $severity,
        'message' => $message,
        'file' => str_replace('\\', '/', $file),
        'line' => $line,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'cli',
    ], JSON_UNESCAPED_SLASHES);
    if ($entry !== false) {
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }
    return false;
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $root = dirname(__DIR__, 2);
    $logFile = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS' . DIRECTORY_SEPARATOR . 'runtime_logs' . DIRECTORY_SEPARATOR . 'php82-web-sapi-fatal.log';
    $entry = json_encode([
        'time' => date('c'),
        'type' => $error['type'],
        'message' => $error['message'],
        'file' => str_replace('\\', '/', $error['file']),
        'line' => $error['line'],
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'cli',
    ], JSON_UNESCAPED_SLASHES);
    if ($entry !== false) {
        file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }
});
