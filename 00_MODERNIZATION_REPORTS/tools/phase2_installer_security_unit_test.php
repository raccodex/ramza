<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racsocial-phase2-unit-' . bin2hex(random_bytes(6));
mkdir($temporary . DIRECTORY_SEPARATOR . 'nodejs', 0755, true);
mkdir($temporary . DIRECTORY_SEPARATOR . 'install', 0755, true);
mkdir($temporary . DIRECTORY_SEPARATOR . 'logs', 0750, true);
mkdir($temporary . DIRECTORY_SEPARATOR . 'upload', 0755, true);
mkdir($temporary . DIRECTORY_SEPARATOR . 'cache', 0755, true);

$paths = new RACInstallerPaths();
$paths->rootDir = $temporary;
$paths->installDir = $temporary . DIRECTORY_SEPARATOR . 'install';
$paths->configFile = $temporary . DIRECTORY_SEPARATOR . 'config.php';
$paths->nodeConfigFile = $temporary . DIRECTORY_SEPARATOR . 'nodejs' . DIRECTORY_SEPARATOR . 'config.json';
$paths->htaccessFile = $temporary . DIRECTORY_SEPARATOR . '.htaccess';
$paths->htaccessSource = $temporary . DIRECTORY_SEPARATOR . 'htaccess.txt';
$paths->sqlDump = $temporary . DIRECTORY_SEPARATOR . 'missing.sql';
$paths->lockFile = $paths->installDir . DIRECTORY_SEPARATOR . 'install.lock';
$paths->logDir = $temporary . DIRECTORY_SEPARATOR . 'logs';
$logger = new RACInstallerLogger($paths);
$postInstall = new RACInstallerPostInstall($logger);
$results = [];
$record = static function (string $gate, bool $pass, string $evidence) use (&$results): void {
    $results[] = ['gate' => $gate, 'status' => $pass ? 'PASS' : 'FAIL', 'evidence' => $evidence];
};
$rejects = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (RACInstallerUserException) {
        return true;
    }
};

final class RACPhase2ResponseClient implements RACInstallerHttpClient
{
    public function __construct(private readonly array $response)
    {
    }

    public function post(string $url, array $fields, int $connectTimeout, int $timeout): array
    {
        return $this->response;
    }
}

try {
    $record('php-floor-helper', !RACInstallerRequirements::supportsPhp('8.1.99') && RACInstallerRequirements::supportsPhp('8.2.0'), 'testable version helper rejects 8.1 and accepts 8.2');

    $session = new RACInstallerSession();
    $session->start();
    $csrf = new RACInstallerCsrf($session);
    $token = $csrf->token();
    $csrfRejected = $rejects(static fn () => $csrf->validate(str_repeat('0', 64)));
    $csrf->validate($token);
    $record('csrf-unit', $csrfRejected, 'invalid token rejected and valid token accepted with constant-time comparison path');
    $session->destroy();

    $badUrls = [
        'https://example.test/path?query=1',
        'https://example.test/path#fragment',
        'https://user:pass@example.test',
        "https://example.test/\r\nInjected: yes",
        'javascript:alert(1)',
    ];
    $badUrlRejected = true;
    foreach ($badUrls as $url) {
        $badUrlRejected = $badUrlRejected && $rejects(static fn () => $postInstall->validateSite(['url' => $url, 'name' => 'Site', 'title' => 'Title', 'email' => 'owner@example.test']));
    }
    $unicodeSite = $postInstall->validateSite(['url' => 'https://community.example.test:8443/subfolder/', 'name' => 'RAC “Friends” 🌍', 'title' => 'Connect — safely', 'email' => 'owner@example.test']);
    $record('site-input-validation', $badUrlRejected && $unicodeSite['url'] === 'https://community.example.test:8443/subfolder', 'unsafe URL forms rejected; Unicode/emoji and alternate-port subfolder accepted safely');

    $adminRejected = $rejects(static fn () => $postInstall->validateAdmin(['username' => 'bad name', 'email' => 'invalid', 'password' => 'short']))
        && $rejects(static fn () => $postInstall->validateAdmin(['username' => 'valid_admin', 'email' => 'admin@example.test', 'password' => 'onlyletterslong']))
        && $rejects(static fn () => $postInstall->validateAdmin(['username' => 'valid_admin', 'email' => 'invalid', 'password' => 'ValidPassword123']));
    $record('administrator-input-validation', $adminRejected, 'invalid username, email, and weak password cases rejected server-side');

    $databaseValidator = new RACInstallerDatabaseValidator($logger);
    $arrayRejected = $rejects(static fn () => $databaseValidator->validate(['host' => ['localhost'], 'name' => 'db', 'user' => 'user', 'pass' => '']));
    $record('scalar-shape-validation', $arrayRejected, 'array-shaped database input rejected before any connection attempt');

    $licenseCases = [
        'invalid' => ['status' => 403, 'errno' => 0, 'error' => '', 'body' => '{}'],
        'domain-mismatch' => ['status' => 200, 'errno' => 0, 'error' => '', 'body' => '{"error":"domain_mismatch"}'],
        'network-timeout' => ['status' => 0, 'errno' => 28, 'error' => 'timeout', 'body' => ''],
        'remote-500' => ['status' => 500, 'errno' => 0, 'error' => '', 'body' => ''],
        'malformed' => ['status' => 200, 'errno' => 0, 'error' => '', 'body' => 'not-json'],
    ];
    $licenseFailuresSafe = true;
    foreach ($licenseCases as $case => $response) {
        $result = (new RACInstallerLicenseVerifier($logger, new RACPhase2ResponseClient($response), 'https://license.test.invalid/verify'))->verify('TEST-UNIT-CODE-NEVER-LOG', 'https://community.example.test');
        $licenseFailuresSafe = $licenseFailuresSafe && !$result['ok'];
    }
    $unsafeEndpoint = (new RACInstallerLicenseVerifier($logger, new RACPhase2ResponseClient($licenseCases['invalid']), 'http://license.test.invalid/verify'))->verify('TEST-UNIT-CODE-NEVER-LOG', 'https://community.example.test');
    $record('license-failure-adapter', $licenseFailuresSafe && !$unsafeEndpoint['ok'] && $unsafeEndpoint['type'] === 'contract-invalid', 'invalid, domain mismatch, timeout/TLS-class, HTTP 500, malformed, and unsafe endpoint paths all failed closed');

    $site = ['url' => 'https://community.example.test/path', 'name' => 'RAC “Unit” 🌍', 'title' => "Title with 'quotes'", 'email' => 'owner@example.test'];
    $database = ['host' => 'localhost', 'user' => "user'<?php", 'pass' => "p@ss'\"\\json", 'name' => 'database_name'];
    $config = new RACInstallerConfigWriter($paths);
    $node = new RACInstallerNodeConfigWriter($paths);
    $config->prepare($database, $site, 'TEST-UNIT-CODE-NEVER-LOG');
    $node->prepare($database, $site, 'TEST-UNIT-CODE-NEVER-LOG');
    $node->commit();
    $config->commit();
    $nodeDecoded = json_decode((string) file_get_contents($paths->nodeConfigFile), true, 512, JSON_THROW_ON_ERROR);
    token_get_all((string) file_get_contents($paths->configFile), TOKEN_PARSE);
    $record('configuration-serialization', is_array($nodeDecoded) && $nodeDecoded['sql_db_pass'] === $database['pass'] && str_contains((string) file_get_contents($paths->configFile), 'siteEncryptKey'), 'PHP/JSON metacharacters serialized safely; generated PHP parsed and JSON decoded');
    $record('active-config-refusal', !RACInstallerConfigWriter::canWriteFreshConfig($paths->configFile) && !RACInstallerNodeConfigWriter::canWriteFreshNodeConfig($paths->nodeConfigFile), 'populated generated configurations are not replaceable');

    $node->rollbackPublished();
    $config->rollbackPublished();
    $node->finalize();
    $config->finalize();
    $record('configuration-rollback', !is_file($paths->configFile) && !is_file($paths->nodeConfigFile), 'newly published files were removed by failure rollback');

    file_put_contents($paths->htaccessSource, "RewriteEngine On\n");
    file_put_contents($paths->htaccessFile, "ExistingRule On\n");
    $htaccessChanged = (new RACInstallerHtaccessWriter($paths))->installIfMissing();
    $record('existing-htaccess-preserved', !$htaccessChanged && file_get_contents($paths->htaccessFile) === "ExistingRule On\n", 'existing rewrite file remained byte-for-byte unchanged');

    $requirements = (new RACInstallerRequirements($paths))->evaluate();
    $sqlRow = array_values(array_filter($requirements['required'], static fn (array $row): bool => $row['name'] === 'SQL dump'))[0] ?? [];
    $record('missing-sql-requirement', ($sqlRow['status'] ?? '') === 'fail', 'missing SQL dump is a blocking required-check result');

    $log = (string) file_get_contents($logger->path());
    $record('unit-secret-redaction', !str_contains($log, 'TEST-UNIT-CODE-NEVER-LOG') && !str_contains($log, $database['pass']), 'controlled test secrets absent from protected logger output');

    $passed = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'PASS'));
    echo json_encode(['status' => $passed === count($results) ? 'PASS' : 'FAIL', 'passed' => $passed, 'total' => count($results), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($passed === count($results) ? 0 : 1);
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $remove($path . DIRECTORY_SEPARATOR . $item);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    };
    $remove($temporary);
}
