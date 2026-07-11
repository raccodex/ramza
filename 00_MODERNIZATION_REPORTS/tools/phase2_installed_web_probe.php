<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php phase2_installed_web_probe.php <base-url> <auth-handoff>\n");
    exit(2);
}
$baseUrl = rtrim($argv[1], '/');
$authFile = realpath($argv[2]);
if (!is_string($authFile) || !str_starts_with($authFile, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Authentication handoff must be a system-temporary file.\n");
    exit(2);
}
$auth = json_decode((string) file_get_contents($authFile), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($auth) || !is_string($auth['username'] ?? null) || !is_string($auth['password'] ?? null)) {
    fwrite(STDERR, "Authentication handoff is invalid.\n");
    exit(2);
}
$cookieJar = tempnam(sys_get_temp_dir(), 'rac_phase2_cookie_');
if ($cookieJar === false) {
    throw new RuntimeException('Unable to create temporary cookie jar.');
}

$request = static function (string $path, string $method = 'GET', array $fields = []) use ($baseUrl, $cookieJar): array {
    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
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
        throw new RuntimeException('HTTP probe failed: ' . $error);
    }
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $locationMatch);
    return ['status' => $status, 'body' => $body, 'location' => trim((string) ($locationMatch[1] ?? ''))];
};

try {
    $installer = $request('/install/index.php');
    $welcome = $request('/index.php?link1=welcome');
    $login = $request('/requests.php?f=login', 'POST', ['username' => $auth['username'], 'password' => $auth['password']]);
    $admin = $request('/admincp.php');
    $loginJson = json_decode($login['body'], true);
    $results = [
        ['gate' => 'installer-lock-web', 'status' => $installer['status'] === 200 && str_contains($installer['body'], 'Setup is safely locked') ? 'PASS' : 'FAIL', 'evidence' => 'status ' . $installer['status'] . '; locked page rendered'],
        ['gate' => 'fresh-home-web', 'status' => $welcome['status'] === 200 && strlen($welcome['body']) > 1000 ? 'PASS' : 'FAIL', 'evidence' => 'status ' . $welcome['status'] . '; response bytes ' . strlen($welcome['body'])],
        ['gate' => 'fresh-admin-login-web', 'status' => is_array($loginJson) && (int) ($loginJson['status'] ?? 0) === 200 ? 'PASS' : 'FAIL', 'evidence' => 'login endpoint status ' . $login['status'] . '; application accepted password_hash credentials'],
        ['gate' => 'fresh-admin-gate-web', 'status' => $admin['status'] === 200 || ($admin['status'] >= 300 && $admin['status'] < 400 && !str_contains(strtolower($admin['location']), 'welcome')) ? 'PASS' : 'FAIL', 'evidence' => 'admin status ' . $admin['status'] . '; redirect class recorded without URL details'],
    ];
    $passed = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'PASS'));
    echo json_encode(['status' => $passed === count($results) ? 'PASS' : 'FAIL', 'passed' => $passed, 'total' => count($results), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($passed === count($results) ? 0 : 1);
} finally {
    @unlink($cookieJar);
    @unlink($authFile);
}
