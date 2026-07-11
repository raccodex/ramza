<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 5) {
    fwrite(STDERR, "Usage: php phase2_installer_web_flow_test.php <base-url> <credential-config> <database-name> <clone-root>\n");
    exit(2);
}
$baseUrl = rtrim($argv[1], '/');
$credentialFile = realpath($argv[2]);
$databaseName = $argv[3];
$cloneRoot = realpath($argv[4]);
if (!is_string($credentialFile) || !is_string($cloneRoot) || !preg_match('/^racsocial_php82_installer_web_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $databaseName)) {
    fwrite(STDERR, "Invalid isolated-test arguments.\n");
    exit(2);
}
$credentials = (static function (string $path): array {
    include $path;
    return ['host' => (string) $sql_db_host, 'user' => (string) $sql_db_user, 'pass' => (string) $sql_db_pass];
})($credentialFile);

$connect = static function (array $config, ?string $database = null): mysqli {
    $host = $config['host'];
    $port = 3306;
    $socket = null;
    if (preg_match('/^(.+):(\d+)$/', $host, $match)) {
        $host = $match[1];
        $port = (int) $match[2];
    } elseif (preg_match('/^(localhost|127\.0\.0\.1):(.+)$/', $host, $match)) {
        $host = $match[1];
        $port = 0;
        $socket = $match[2];
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli($host, $config['user'], $config['pass'], $database, $port, $socket);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
};
$server = $connect($credentials);
$exists = $server->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $server->real_escape_string($databaseName) . "'")->num_rows > 0;
if ($exists) {
    throw new RuntimeException('Generated disposable database name already exists.');
}
$server->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server->close();

$cookieJar = tempnam(sys_get_temp_dir(), 'rac_phase2_installer_cookie_');
if ($cookieJar === false) {
    throw new RuntimeException('Unable to create temporary cookie jar.');
}
$request = static function (string $step, string $method = 'GET', array $fields = [], ?string $jar = null) use ($baseUrl, $cookieJar): array {
    $activeJar = $jar ?? $cookieJar;
    $curl = curl_init($baseUrl . '/install/index.php?step=' . rawurlencode($step));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $activeJar,
        CURLOPT_COOKIEFILE => $activeJar,
    ]);
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($response)) {
        throw new RuntimeException('Installer HTTP request failed: ' . $error);
    }
    return ['status' => $status, 'body' => substr($response, $headerSize)];
};
$csrf = static function (array $response): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $response['body'], $match)) {
        throw new RuntimeException('Installer form did not contain a CSRF token.');
    }
    return $match[1];
};
$results = [];
$testAdminPassword = 'RAC-' . bin2hex(random_bytes(16)) . '-9';
$testPurchaseCode = 'TEST-' . bin2hex(random_bytes(16));
$record = static function (string $gate, bool $pass, string $evidence) use (&$results): void {
    $results[] = ['gate' => $gate, 'status' => $pass ? 'PASS' : 'FAIL', 'evidence' => $evidence];
};

try {
    $skipJar = tempnam(sys_get_temp_dir(), 'rac_phase2_skip_cookie_');
    $skip = $request('install', 'GET', [], $skipJar);
    $record('step-skip-guard', $skip['status'] === 200 && str_contains($skip['body'], 'Welcome to RACSocial'), 'direct install-step request returned the welcome gate');
    @unlink($skipJar);

    $badCsrf = $request('welcome', 'POST', ['csrf' => str_repeat('0', 64), 'action' => 'accept', 'agree' => 'yes']);
    $record('csrf-rejection', $badCsrf['status'] === 200 && str_contains($badCsrf['body'], 'form token was invalid'), 'invalid form token was rejected without advancing');

    $welcome = $request('welcome');
    $accept = $request('welcome', 'POST', ['csrf' => $csrf($welcome), 'action' => 'accept', 'agree' => 'yes']);
    $requirements = $request('requirements');
    $advance = $request('requirements', 'POST', ['csrf' => $csrf($requirements), 'action' => 'requirements']);
    $databaseForm = $request('database');
    $databasePost = $request('database', 'POST', [
        'csrf' => $csrf($databaseForm),
        'action' => 'database',
        'db_host' => $credentials['host'],
        'db_name' => $databaseName,
        'db_user' => $credentials['user'],
        'db_pass' => $credentials['pass'],
    ]);
    $record('guided-preflight-and-database', $accept['status'] === 303 && $advance['status'] === 303 && $databasePost['status'] === 303, 'welcome, required checks, and empty-database validation advanced with 303 redirects');

    $siteForm = $request('site');
    $licensePost = $request('site', 'POST', [
        'csrf' => $csrf($siteForm),
        'action' => 'site',
        'site_url' => $baseUrl,
        'site_name' => 'RACSocial Web Test',
        'site_title' => 'Installer Web Flow',
        'site_email' => 'site@example.test',
        'admin_username' => 'webflowadmin',
        'admin_email' => 'admin@example.test',
        'admin_password' => $testAdminPassword,
        'purchase_code' => $testPurchaseCode,
    ]);
    $database = $connect($credentials, $databaseName);
    $tableCount = (int) ($database->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetch_assoc()['c'] ?? -1);
    $database->close();
    $record('production-web-license-fail-closed', $licensePost['status'] === 200 && str_contains($licensePost['body'], 'does not contain a legitimate production license verification contract') && $tableCount === 0, 'actual web flow stopped at license gate; disposable database remained at 0 tables');
    $record('no-premature-files', !is_file($cloneRoot . DIRECTORY_SEPARATOR . 'config.php') && !is_file($cloneRoot . DIRECTORY_SEPARATOR . 'nodejs' . DIRECTORY_SEPARATOR . 'config.json') && !is_file($cloneRoot . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'install.lock'), 'test server clone published no config or lock before license verification');

    $passed = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'PASS'));
    echo json_encode(['status' => $passed === count($results) ? 'PASS' : 'FAIL', 'database' => $databaseName, 'table_count' => $tableCount, 'passed' => $passed, 'total' => count($results), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($passed === count($results) ? 0 : 1);
} finally {
    @unlink($cookieJar);
}
