<?php
declare(strict_types=1);

use Ramza\MobileApi\ApiException;
use Ramza\MobileApi\Request;
use Ramza\MobileApi\Security;

require_once __DIR__ . '/../api/v1/src/ApiException.php';
require_once __DIR__ . '/../api/v1/src/RequestContext.php';
require_once __DIR__ . '/../api/v1/src/Request.php';
require_once __DIR__ . '/../api/v1/src/Security.php';

function ramza_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$security = new Security(random_bytes(32));
$access = $security->opaqueToken('rma_');
$refresh = $security->opaqueToken('rmr_');
ramza_test(str_starts_with($access, 'rma_'), 'Access-token prefix is missing.');
ramza_test(str_starts_with($refresh, 'rmr_'), 'Refresh-token prefix is missing.');
ramza_test($access !== $security->opaqueToken('rma_'), 'Opaque tokens must be unique.');
ramza_test(strlen($security->hash($access)) === 64, 'Token hashes must be SHA-256 hex values.');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/auth/login?ignored=yes';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;
$_SERVER['HTTP_X_RAMZA_CLIENT'] = 'rmp_test_client';
$request = new Request();
ramza_test($request->method() === 'POST', 'HTTP method parsing failed.');
ramza_test($request->path() === '/auth/login', 'Versioned API path parsing failed.');
ramza_test(hash_equals($access, $request->bearerToken()), 'Bearer-token parsing failed.');
ramza_test($request->publicClientId() === 'rmp_test_client', 'Public client identifier parsing failed.');

$siteEncryptKey = bin2hex(random_bytes(32));
require_once __DIR__ . '/../assets/includes/mobile_security.php';
$plain = 'mobile-addon-code-' . bin2hex(random_bytes(8));
$encrypted = Ramza_MobileEncrypt($plain);
ramza_test(str_starts_with($encrypted, 'rms1:'), 'Encrypted setting version prefix is missing.');
ramza_test(hash_equals($plain, Ramza_MobileDecrypt($encrypted)), 'Encrypted setting did not round trip.');
$encodedPayload = substr($encrypted, 5);
$normalizedPayload = strtr($encodedPayload, '-_', '+/');
$normalizedPayload .= str_repeat('=', (4 - strlen($normalizedPayload) % 4) % 4);
$tamperedBytes = base64_decode($normalizedPayload, true);
ramza_test(is_string($tamperedBytes) && strlen($tamperedBytes) > 25, 'Encrypted test value could not be decoded.');
$tamperedBytes[24] = chr(ord($tamperedBytes[24]) ^ 1);
$tampered = 'rms1:' . rtrim(strtr(base64_encode($tamperedBytes), '+/', '-_'), '=');
$tamperRejected = false;
try {
    Ramza_MobileDecrypt($tampered);
} catch (Throwable) {
    $tamperRejected = true;
}
ramza_test($tamperRejected, 'Authenticated encryption accepted a tampered value.');

$keyPair = sodium_crypto_sign_keypair();
$ramza_mobile_license_public_key = rtrim(strtr(
    base64_encode(sodium_crypto_sign_publickey($keyPair)),
    '+/',
    '-_'
), '=');
$payload = [
    'allowed_hosts' => ['customer.example'],
    'api_base_url' => 'https://customer.example/api/v1',
    'expires_at' => time() + 3600,
    'issued_at' => time(),
    'package_name' => 'com.example.ramza',
];
$signature = rtrim(strtr(base64_encode(sodium_crypto_sign_detached(
    Ramza_MobileCanonicalJson($payload),
    sodium_crypto_sign_secretkey($keyPair)
)), '+/', '-_'), '=');
ramza_test(Ramza_MobileVerifyLicensePayload($payload, $signature), 'A valid Ed25519 signature was rejected.');
$payload['package_name'] = 'com.example.tampered';
ramza_test(!Ramza_MobileVerifyLicensePayload($payload, $signature), 'A modified signed payload was accepted.');

$apiSources = '';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    __DIR__ . '/../api/v1',
    FilesystemIterator::SKIP_DOTS
));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $apiSources .= (string) file_get_contents($file->getPathname());
    }
}
ramza_test(
    preg_match('/\$_(?:GET|REQUEST)\s*\[\s*[\'\"](?:access_token|refresh_token|server_key)[\'\"]\s*\]/', $apiSources) !== 1,
    'A sensitive mobile credential is accepted from a URL parameter.'
);

echo "PASS mobile API security tests\n";
