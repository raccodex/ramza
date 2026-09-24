<?php
declare(strict_types=1);

use Ramza\MobileApi\ApiException;
use Ramza\MobileApi\AuditLogger;
use Ramza\MobileApi\AuthChallengeService;
use Ramza\MobileApi\Database;
use Ramza\MobileApi\RateLimiter;
use Ramza\MobileApi\RequestContext;
use Ramza\MobileApi\Security;
use Ramza\MobileApi\TokenService;

foreach ([
    'ApiException.php',
    'RequestContext.php',
    'Database.php',
    'Security.php',
    'AuditLogger.php',
    'RateLimiter.php',
    'TokenService.php',
    'AuthChallengeService.php',
] as $source) {
    require_once __DIR__ . '/../api/v1/src/' . $source;
}

function ramza_db_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$databaseName = trim((string) getenv('RAMZA_TEST_DB_NAME'));
if (preg_match('/^ramza_mobile_phase2_disposable_[0-9]{14}$/', $databaseName) !== 1) {
    throw new RuntimeException('Refusing to run outside a clearly named disposable database.');
}

$mysqli = new mysqli('127.0.0.1', 'root', '', $databaseName);
$mysqli->set_charset('utf8mb4');
$selected = $mysqli->query('SELECT DATABASE() AS selected_database')->fetch_assoc();
ramza_db_test(($selected['selected_database'] ?? '') === $databaseName, 'Disposable database selection failed.');

$requiredTables = [
    'Wo_Config',
    'Wo_Users',
    'Ramza_MobileApiClients',
    'Ramza_MobileSessions',
    'Ramza_MobileSettings',
    'Ramza_MobileLicenseCache',
    'Ramza_MobileRateLimits',
    'Ramza_MobileAuditLog',
    'Ramza_MobileAuthChallenges',
    'Ramza_MobileSocialIdentities',
];
foreach ($requiredTables as $table) {
    $safe = $mysqli->real_escape_string($table);
    $result = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    ramza_db_test($result->num_rows === 1, "Required table {$table} is missing.");
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Ramza disposable runtime test';
$_SERVER['HTTP_X_REQUEST_ID'] = 'mobile-db-test-' . bin2hex(random_bytes(8));
RequestContext::initialize();

$database = new Database($mysqli);
$security = new Security(random_bytes(32));
$audit = new AuditLogger($database, $security);
$rateLimiter = new RateLimiter($database, $security);
$tokens = new TokenService($database, $security, $audit);
$challenges = new AuthChallengeService($database, $security);
$now = time();
$publicClientId = 'rmp_test_' . bin2hex(random_bytes(8));
$database->execute(
    'INSERT INTO `Ramza_MobileApiClients`
     (`public_client_id`,`display_name`,`android_package`,`ios_bundle_id`,`enabled`,`created_at`,`updated_at`)
     VALUES (?,?,?,?,1,?,?)',
    'ssssii',
    [$publicClientId, 'Disposable test', 'com.ramza.test', 'com.ramza.test', $now, $now]
);
$client = $database->one(
    'SELECT `id` FROM `Ramza_MobileApiClients` WHERE `public_client_id` = ? LIMIT 1',
    's',
    [$publicClientId]
);
ramza_db_test($client !== null, 'Test mobile client was not inserted.');

$challenge = $challenges->issue(
    'activation',
    900000001,
    (int) $client['id'],
    'installation_test_00000001'
);
$wrongCodeRejected = false;
try {
    $challenges->verifyCode(
        $challenge['challenge_id'],
        'activation',
        (int) $client['id'],
        'installation_test_00000001',
        '000000'
    );
} catch (ApiException $error) {
    $wrongCodeRejected = $error->errorCode === 'VERIFICATION_CODE_INVALID';
}
ramza_db_test($wrongCodeRejected, 'A wrong authentication challenge code was accepted.');
$verifiedChallenge = $challenges->verifyCode(
    $challenge['challenge_id'],
    'activation',
    (int) $client['id'],
    'installation_test_00000001',
    $challenge['code']
);
ramza_db_test((int) $verifiedChallenge['user_id'] === 900000001, 'Authentication challenge verification failed.');
$challengeReuseRejected = false;
try {
    $challenges->verifyCode(
        $challenge['challenge_id'],
        'activation',
        (int) $client['id'],
        'installation_test_00000001',
        $challenge['code']
    );
} catch (ApiException $error) {
    $challengeReuseRejected = $error->errorCode === 'CHALLENGE_INVALID';
}
ramza_db_test($challengeReuseRejected, 'A consumed authentication challenge was reused.');

$issued = $tokens->issue(900000001, (int) $client['id'], 'installation_test_00000001', 'android');
ramza_db_test(isset($issued['access_token'], $issued['refresh_token']), 'Token issuance failed.');
$authenticated = $tokens->authenticate($issued['access_token'], $publicClientId);
ramza_db_test((int) $authenticated['user_id'] === 900000001, 'Access-token authentication failed.');
$rotated = $tokens->rotate($issued['refresh_token'], $publicClientId, 'installation_test_00000001');
ramza_db_test($rotated['refresh_token'] !== $issued['refresh_token'], 'Refresh-token rotation failed.');

$reuseRejected = false;
try {
    $tokens->rotate($issued['refresh_token'], $publicClientId, 'installation_test_00000001');
} catch (ApiException $error) {
    $reuseRejected = $error->errorCode === 'REFRESH_TOKEN_REUSE';
}
ramza_db_test($reuseRejected, 'Refresh-token reuse did not revoke the token family.');

$limited = false;
try {
    $rateLimiter->enforce('disposable_test', 'one-bucket', 2, 60);
    $rateLimiter->enforce('disposable_test', 'one-bucket', 2, 60);
    $rateLimiter->enforce('disposable_test', 'one-bucket', 2, 60);
} catch (ApiException $error) {
    $limited = $error->errorCode === 'RATE_LIMITED';
}
ramza_db_test($limited, 'Database-backed rate limiting did not enforce its limit.');

$auditCount = $database->one(
    'SELECT COUNT(*) AS total FROM `Ramza_MobileAuditLog` WHERE `client_id` = ?',
    'i',
    [(int) $client['id']]
);
ramza_db_test((int) ($auditCount['total'] ?? 0) >= 3, 'Expected mobile audit events were not recorded.');

echo json_encode([
    'result' => 'PASS',
    'database' => $databaseName,
    'tables_checked' => count($requiredTables),
    'refresh_reuse_rejected' => true,
    'rate_limit_enforced' => true,
    'audit_events' => (int) $auditCount['total'],
    'challenge_reuse_rejected' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$mysqli->close();
