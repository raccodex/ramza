<?php
declare(strict_types=1);

define('RAMZA_MOBILE_LICENSE_LIBRARY_ONLY', true);

function ramza_mobile_server_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ramza-mobile-license-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('Unable to create the mobile license test directory.');
}
$claimsFile = $temporaryDirectory . DIRECTORY_SEPARATOR . 'claims.json';
$configFile = $temporaryDirectory . DIRECTORY_SEPARATOR . 'config.php';
$pair = sodium_crypto_sign_keypair();
$secret = rtrim(strtr(base64_encode(sodium_crypto_sign_secretkey($pair)), '+/', '-_'), '=');
$hmac = bin2hex(random_bytes(32));
$configuration = [
    'RAMZA_LICENSE_HMAC_SECRET' => $hmac,
    'RAMZA_LICENSE_CLAIMS_FILE' => $claimsFile,
    'RAMZA_MOBILE_ED25519_SECRET_KEY' => $secret,
    'RAMZA_MOBILE_ACTIVATION_LIMIT' => '2',
];
$configPhp = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($configuration, true) . ";\n";
file_put_contents($configFile, $configPhp, LOCK_EX);
putenv('RAMZA_LICENSE_CONFIG_FILE=' . $configFile);

require_once __DIR__ . '/../developer-tools/mobile-license-server/index.php';

$mainCode = '11111111-2222-3333-4444-555555555555';
$addonCode = 'RMA-ABCDEF-123456-7890AB-CDEF12';
$siteHost = 'customer.example';
$claimKey = ramza_mobile_claim_key($mainCode);
$claims = [
    $claimKey => [
        'license_id' => $claimKey,
        'buyer' => 'buyer_name',
        'site_url' => 'https://' . $siteHost,
        'site_host' => $siteHost,
        'site_host_hash' => hash('sha256', $siteHost),
        'status' => 'active',
        'feature_code_hashes' => [
            'mobile_app' => ramza_mobile_code_hash($addonCode),
        ],
    ],
];
file_put_contents($claimsFile, json_encode($claims, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$body = [
    'site_url' => 'https://' . $siteHost,
    'main_purchase_code' => $mainCode,
    'mobile_addon_purchase_code' => $addonCode,
    'codecanyon_username' => 'buyer_name',
    'android_package_name' => 'com.customer.ramza',
    'ios_bundle_id' => 'com.customer.ramza.ios',
    'installation_id' => 'installation_test_00000001',
    'public_client_id' => 'rmp_public_client_00000001',
];
$activation = ramza_mobile_activate($body, 'nonce_activation_00000001', '11111111-1111-1111-1111-111111111111');
ramza_mobile_server_test(($activation['success'] ?? false) === true, 'Mobile activation failed.');
$signed = $activation['signed_payload'] ?? [];
$signature = (string) ($activation['signature'] ?? '');
ramza_mobile_server_test(is_array($signed) && ramza_mobile_verify_signature($signed, $signature), 'The activation signature is invalid.');
ramza_mobile_server_test(($signed['api_base_url'] ?? '') === 'https://customer.example/api/v1', 'The signed API URL is incorrect.');
ramza_mobile_server_test(($signed['package_name'] ?? '') === 'com.customer.ramza', 'Android package binding failed.');
ramza_mobile_server_test(($signed['ios_bundle_id'] ?? '') === 'com.customer.ramza.ios', 'iOS bundle binding failed.');

$sameActivation = ramza_mobile_activate($body, 'nonce_activation_00000001', '11111111-1111-1111-1111-111111111111');
ramza_mobile_server_test(
    ($sameActivation['signed_payload'] ?? null) === ($activation['signed_payload'] ?? null)
    && hash_equals((string) ($activation['signature'] ?? ''), (string) ($sameActivation['signature'] ?? '')),
    'Activation idempotency did not replay the signed response.'
);
ramza_mobile_server_test(
    !isset($sameActivation['activation_token']),
    'Activation idempotency replay exposed the one-time installation credential.'
);

$sessionBody = [
    'license_id' => (string) $signed['license_id'],
    'installation_id' => (string) $signed['installation_id'],
    'signed_payload' => $signed,
    'signature' => $signature,
    'activation_token' => (string) ($activation['activation_token'] ?? ''),
];
$refresh = ramza_mobile_existing($sessionBody, 'nonce_refresh_00000000001', '22222222-2222-2222-2222-222222222222');
ramza_mobile_server_test(($refresh['success'] ?? false) === true, 'Mobile license refresh failed.');
ramza_mobile_server_test(($refresh['signed_payload']['nonce'] ?? '') === 'nonce_refresh_00000000001', 'Refresh nonce was not signed.');
ramza_mobile_server_test(ramza_mobile_verify_signature($refresh['signed_payload'], $refresh['signature']), 'The refresh signature is invalid.');

$deactivated = ramza_mobile_existing($sessionBody, 'nonce_deactivate_0000001', '33333333-3333-3333-3333-333333333333', true);
ramza_mobile_server_test(($deactivated['status'] ?? '') === 'deactivated', 'Mobile installation deactivation failed.');

$saved = json_decode((string) file_get_contents($claimsFile), true);
ramza_mobile_server_test(
    empty($saved[$claimKey]['mobile']['installations']['installation_test_00000001']),
    'The deactivated installation remains active.'
);
ramza_mobile_server_test(
    !isset($saved[$claimKey]['feature_code_hashes']['mobile_app']),
    'The one-time mobile activation code was not consumed.'
);

@unlink($configFile);
@unlink($claimsFile);
@rmdir($temporaryDirectory);
putenv('RAMZA_LICENSE_CONFIG_FILE');

echo "PASS mobile license server activation, signature, idempotency, refresh, and deactivation tests\n";
