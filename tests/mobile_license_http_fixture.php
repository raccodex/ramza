<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3 || $argv[1] !== 'setup') {
    fwrite(STDERR, "Usage: php mobile_license_http_fixture.php setup <temporary-directory>\n");
    exit(2);
}

$directory = $argv[2];
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create fixture directory.');
}
$directory = realpath($directory);
if (!is_string($directory) || !str_starts_with(basename($directory), 'ramza-mobile-http-')) {
    throw new RuntimeException('Unsafe fixture directory.');
}

$hmac = bin2hex(random_bytes(32));
$pair = sodium_crypto_sign_keypair();
$secret = rtrim(strtr(base64_encode(sodium_crypto_sign_secretkey($pair)), '+/', '-_'), '=');
$mainCode = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$addonCode = 'RMA-ABCDEF-123456-7890AB-CDEF12';
$claimKey = hash_hmac('sha256', strtolower($mainCode), $hmac);
$siteHost = 'localhost';
$claimsFile = $directory . DIRECTORY_SEPARATOR . 'claims.json';
$configFile = $directory . DIRECTORY_SEPARATOR . 'config.php';

$configuration = [
    'RAMZA_LICENSE_HMAC_SECRET' => $hmac,
    'RAMZA_LICENSE_CLAIMS_FILE' => $claimsFile,
    'RAMZA_MOBILE_ED25519_SECRET_KEY' => $secret,
    'RAMZA_MOBILE_ACTIVATION_LIMIT' => '2',
];
file_put_contents(
    $configFile,
    "<?php\ndeclare(strict_types=1);\nreturn " . var_export($configuration, true) . ";\n",
    LOCK_EX
);
file_put_contents($claimsFile, json_encode([
    $claimKey => [
        'license_id' => $claimKey,
        'buyer' => 'http_buyer',
        'site_url' => 'http://localhost:8997',
        'site_host' => $siteHost,
        'site_host_hash' => hash('sha256', $siteHost),
        'status' => 'active',
        'feature_code_hashes' => [
            'mobile_app' => hash_hmac('sha256', strtoupper($addonCode), $hmac),
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode([
    'config_file' => $configFile,
    'main_code' => $mainCode,
    'addon_code' => $addonCode,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
