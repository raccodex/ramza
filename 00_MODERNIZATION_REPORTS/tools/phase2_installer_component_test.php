<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 4) {
    fwrite(STDERR, "Usage: php phase2_installer_component_test.php <clone-root> <credential-config> <database-name>\n");
    exit(2);
}

$cloneRoot = realpath($argv[1]);
$credentialFile = realpath($argv[2]);
$databaseName = $argv[3];
if (!is_string($cloneRoot) || !is_string($credentialFile) || !preg_match('/^racsocial_php82_installer_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $databaseName)) {
    fwrite(STDERR, "Invalid isolated-test arguments.\n");
    exit(2);
}
require $cloneRoot . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$credentials = (static function (string $path): array {
    include $path;
    return [
        'host' => isset($sql_db_host) ? (string) $sql_db_host : '',
        'user' => isset($sql_db_user) ? (string) $sql_db_user : '',
        'pass' => isset($sql_db_pass) ? (string) $sql_db_pass : '',
    ];
})($credentialFile);

$results = [];
$testPurchaseCode = 'TEST-' . bin2hex(random_bytes(16));
$authHandoff = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racsocial-phase2-auth-' . bin2hex(random_bytes(8)) . '.json';
$testSucceeded = false;
$record = static function (string $gate, bool $pass, string $evidence) use (&$results): void {
    $results[] = ['gate' => $gate, 'status' => $pass ? 'PASS' : 'FAIL', 'evidence' => $evidence];
    if (!$pass) {
        throw new RuntimeException($gate . ' failed: ' . $evidence);
    }
};
$connectTarget = static function (array $config, ?string $database = null): mysqli {
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
    $connection = new mysqli($host, $config['user'], $config['pass'], $database, $port, $socket);
    $connection->set_charset('utf8mb4');
    return $connection;
};

final class RACPhase2ControlledLicenseClient implements RACInstallerHttpClient
{
    public array $lastRequest = [];

    public function post(string $url, array $fields, int $connectTimeout, int $timeout): array
    {
        $this->lastRequest = ['url' => $url, 'field_names' => array_keys($fields), 'connect_timeout' => $connectTimeout, 'timeout' => $timeout];
        return ['status' => 200, 'errno' => 0, 'error' => '', 'body' => '{"ok":true}'];
    }
}

$server = null;
$database = null;
try {
    $server = $connectTarget($credentials);
    $quotedName = '`' . str_replace('`', '``', $databaseName) . '`';
    $exists = $server->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $server->real_escape_string($databaseName) . "'")->num_rows > 0;
    $record('fresh-database-name', !$exists, 'generated database name did not already exist');
    $server->query("CREATE DATABASE {$quotedName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->close();
    $server = null;

    $paths = new RACInstallerPaths();
    $logger = new RACInstallerLogger($paths);
    $validator = new RACInstallerDatabaseValidator($logger);
    $databaseConfig = $validator->validate([
        'host' => $credentials['host'],
        'user' => $credentials['user'],
        'pass' => $credentials['pass'],
        'name' => $databaseName,
    ]);
    $record('empty-database-guard', true, 'validator accepted newly created zero-table database');

    $missingContract = (new RACInstallerLicenseVerifier($logger))->verify($testPurchaseCode, 'https://installer.test.local');
    $database = $connectTarget($credentials, $databaseName);
    $preLicenseTables = (int) ($database->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetch_assoc()['c'] ?? -1);
    $record('production-license-fail-closed', !$missingContract['ok'] && $missingContract['type'] === 'contract-missing' && $preLicenseTables === 0, 'missing contract stopped before database import; table count remained 0');

    $controlledClient = new RACPhase2ControlledLicenseClient();
    $controlledVerifier = new RACInstallerLicenseVerifier($logger, $controlledClient, 'https://license.test.invalid/verify');
    $controlledResult = $controlledVerifier->verify($testPurchaseCode, 'https://installer.test.local');
    $record('controlled-license-adapter', $controlledResult['ok'] && !str_contains($controlledClient->lastRequest['url'], $testPurchaseCode) && in_array('purchase_code', $controlledClient->lastRequest['field_names'], true), 'controlled HTTPS adapter passed code in POST body field, not URL');

    $requirements = (new RACInstallerRequirements($paths))->evaluate();
    $record('fresh-clone-preflight', $requirements['required_pass'], 'all required checks passed in isolated clone');

    $postInstall = new RACInstallerPostInstall($logger);
    $site = $postInstall->validateSite([
        'url' => 'http://127.0.0.1:8084',
        'name' => 'RACSocial Test',
        'title' => 'PHP 8.2 Installer Verification',
        'email' => 'site-admin@example.test',
    ]);
    $adminPassword = 'RAC-' . bin2hex(random_bytes(16)) . '-9';
    $admin = $postInstall->validateAdmin([
        'username' => 'phase2admin',
        'email' => 'phase2-admin@example.test',
        'password' => $adminPassword,
    ]);
    $configWriter = new RACInstallerConfigWriter($paths);
    $nodeWriter = new RACInstallerNodeConfigWriter($paths);
    $configWriter->prepare($databaseConfig, $site, $testPurchaseCode);
    $nodeWriter->prepare($databaseConfig, $site, $testPurchaseCode);

    $import = (new RACInstallerSqlImporter($logger))->import($database, $paths->sqlDump);
    $record('sql-import', $import['tables'] >= 100 && $import['statements'] > $import['tables'], $import['tables'] . ' tables created from wowonder.sql');
    $adminUserId = $postInstall->configure($database, $site, $admin);
    $record('administrator-bootstrap', $adminUserId > 0, 'administrator and companion user-fields row created');
    $htaccessCreated = (new RACInstallerHtaccessWriter($paths))->installIfMissing();
    $nodeWriter->commit();
    $configWriter->commit();
    $lock = new RACInstallerInstallLock($paths);
    $lock->create($site['url'], $import['schema_fingerprint']);
    $record('filesystem-publish', $htaccessCreated && is_file($paths->configFile) && is_file($paths->nodeConfigFile) && $lock->exists(), 'rewrite, PHP config, Node config, and install lock published only in isolated clone');

    $adminStatement = $database->prepare('SELECT password, admin, active FROM Wo_Users WHERE user_id = ?');
    $adminStatement->bind_param('i', $adminUserId);
    $adminStatement->execute();
    $adminRow = $adminStatement->get_result()->fetch_assoc();
    $adminStatement->close();
    $fieldStatement = $database->prepare('SELECT COUNT(*) AS c FROM Wo_UserFields WHERE user_id = ?');
    $fieldStatement->bind_param('i', $adminUserId);
    $fieldStatement->execute();
    $fieldCount = (int) ($fieldStatement->get_result()->fetch_assoc()['c'] ?? 0);
    $fieldStatement->close();
    $record('password-hash-auth-compatibility', is_array($adminRow) && password_verify($adminPassword, (string) $adminRow['password']) && !preg_match('/^[a-f0-9]{40}$/i', (string) $adminRow['password']) && (string) $adminRow['admin'] === '1' && (string) $adminRow['active'] === '1' && $fieldCount === 1, 'password_hash/password_verify passed; admin active; companion row present');

    $siteName = $database->query("SELECT value FROM Wo_Config WHERE name = 'siteName'")->fetch_assoc()['value'] ?? '';
    $theme = $database->query("SELECT value FROM Wo_Config WHERE name = 'theme'")->fetch_assoc()['value'] ?? '';
    $record('site-configuration', $siteName === $site['name'] && $theme === 'wowonder' && is_file($cloneRoot . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'wowonder' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'welcome' . DIRECTORY_SEPARATOR . 'content.phtml'), 'site settings and an installed default theme were selected with prepared statements');

    $nodeData = json_decode((string) file_get_contents($paths->nodeConfigFile), true);
    $configContent = (string) file_get_contents($paths->configFile);
    $lockContent = (string) file_get_contents($paths->lockFile);
    $record('generated-config-shape', is_array($nodeData) && isset($nodeData['sql_db_host'], $nodeData['sql_db_name'], $nodeData['site_url'], $nodeData['purchase_code']) && str_contains($configContent, '$siteEncryptKey') && !str_contains($lockContent, $testPurchaseCode), 'generated configs contain required keys; lock contains no purchase code');

    try {
        $validator->validate($databaseConfig);
        $nonEmptyRefused = false;
    } catch (RACInstallerUserException) {
        $nonEmptyRefused = true;
    }
    $record('rerun-protection', $nonEmptyRefused && $lock->exists() && !RACInstallerConfigWriter::canWriteFreshConfig($paths->configFile) && !RACInstallerNodeConfigWriter::canWriteFreshNodeConfig($paths->nodeConfigFile), 'non-empty database, populated configs, and install lock prevent rerun');

    $logContent = is_file($logger->path()) ? (string) file_get_contents($logger->path()) : '';
    $record('secret-redaction', !str_contains($logContent, $testPurchaseCode) && !str_contains($logContent, $adminPassword) && ($credentials['pass'] === '' || !str_contains($logContent, $credentials['pass'])), 'protected installer log contains none of the test secrets');

    $authPayload = json_encode(['username' => $admin['username'], 'password' => $adminPassword], JSON_THROW_ON_ERROR);
    if (file_put_contents($authHandoff, $authPayload, LOCK_EX) !== strlen($authPayload)) {
        throw new RuntimeException('Unable to create protected authentication-test handoff.');
    }
    @chmod($authHandoff, 0600);
    $testSucceeded = true;

    echo json_encode([
        'status' => 'PASS',
        'php' => PHP_VERSION,
        'database' => $databaseName,
        'clone_root' => $cloneRoot,
        'table_count' => $import['tables'],
        'statement_count' => $import['statements'],
        'auth_handoff' => $authHandoff,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    echo json_encode([
        'status' => 'FAIL',
        'database' => $databaseName,
        'error_type' => get_class($error),
        'error' => preg_replace('/([A-Za-z]:)?[\\\\\/][^\s]+/', '[path]', $error->getMessage()),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
} finally {
    if (!$testSucceeded && is_file($authHandoff)) {
        @unlink($authHandoff);
    }
    if ($database instanceof mysqli) {
        $database->close();
    }
    if ($server instanceof mysqli) {
        $server->close();
    }
}
