<?php
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8082', '/');
$root = dirname(__DIR__, 2);
$outDir = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS' . DIRECTORY_SEPARATOR . 'runtime_logs';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}
$cookieJar = $outDir . DIRECTORY_SEPARATOR . 'phase1_probe_cookies.txt';
$userCookieJar = $outDir . DIRECTORY_SEPARATOR . 'phase1_probe_user_cookies.txt';
$adminCookieJar = $outDir . DIRECTORY_SEPARATOR . 'phase1_probe_admin_cookies.txt';
$resultsFile = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS' . DIRECTORY_SEPARATOR . '10_WEB_SAPI_PROBE_RESULTS.csv';
$uploadFixture = $outDir . DIRECTORY_SEPARATOR . 'phase1_upload_fixture.png';

foreach ([
    $cookieJar,
    $userCookieJar,
    $adminCookieJar,
    $outDir . DIRECTORY_SEPARATOR . 'php82-web-sapi-captured.log',
    $outDir . DIRECTORY_SEPARATOR . 'php82-web-sapi-fatal.log',
    $outDir . DIRECTORY_SEPARATOR . 'php82-web-sapi-error.log',
] as $path) {
    if (file_exists($path)) {
        unlink($path);
    }
}

$image = imagecreatetruecolor(1, 1);
if ($image !== false) {
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, 0, 0, $white);
    imagepng($image, $uploadFixture);
    imagedestroy($image);
}

$tests = [
    ['web_index', 'GET', '/index.php', [], $cookieJar, [], '', 'readonly'],
    ['web_home_route', 'GET', '/', [], $cookieJar, [], '', 'readonly'],
    ['admin_login_gate', 'GET', '/admincp.php', [], $cookieJar, [], '', 'readonly'],
    ['api_missing_type', 'GET', '/api.php', [], $cookieJar, [], '/"api_status"\s*:\s*"failed"/', 'readonly'],
    ['api_invalid_type', 'GET', '/api.php?type=invalid', [], $cookieJar, [], '/"api_status"\s*:\s*"failed"/', 'readonly'],
    ['api_user_data_seeded', 'GET', '/api.php?type=user_data&user=phase1user', [], $cookieJar, [], '/"api_status"\s*:\s*"success"/', 'readonly'],
    ['api_posts_data_seeded', 'GET', '/api.php?type=posts_data&user=phase1user', [], $cookieJar, [], '', 'readonly'],
    ['ajax_missing_xhr_restricted', 'GET', '/requests.php?f=login', [], $cookieJar, [], '', 'readonly'],
    ['ajax_login_missing_fields', 'POST', '/requests.php?f=login', ['username' => '', 'password' => ''], $cookieJar, [], '/"errors"\s*:/', 'readonly'],
    ['ajax_resend_two_factor_missing_hash', 'GET', '/requests.php?f=resend_two_factor', [], $cookieJar, [], '/"status"\s*:\s*400/', 'readonly'],
    ['api_v2_missing_route', 'GET', '/api/v2/', [], $cookieJar, [], '', 'readonly'],
    ['auth_user_login', 'POST', '/requests.php?f=login', ['username' => 'phase1user', 'password' => 'Phase1Test!2026'], $userCookieJar, [], '/"status"\s*:\s*200/', 'disposable-db-session'],
    ['auth_user_home', 'GET', '/index.php', [], $userCookieJar, [], '', 'readonly-with-session'],
    ['ajax_session_status_logged_in', 'GET', '/requests.php?f=session_status', [], $userCookieJar, [], '', 'readonly-with-session'],
    ['upload_blog_image_png', 'POST', '/requests.php?f=upload-blog-image', [], $userCookieJar, ['image' => $uploadFixture], '/"location"\s*:/', 'disposable-db-and-filesystem'],
    ['auth_admin_login', 'POST', '/requests.php?f=login', ['username' => 'phase1admin', 'password' => 'Phase1Test!2026'], $adminCookieJar, [], '/"status"\s*:\s*200/', 'disposable-db-session'],
    ['admin_dashboard_authenticated', 'GET', '/admincp.php', [], $adminCookieJar, [], '', 'readonly-with-session'],
    ['cron_direct_disposable_db', 'GET', '/cron-job.php', [], $cookieJar, [], '/"status"\s*:\s*200/', 'disposable-db-mutation'],
];

$rows = [];
foreach ($tests as $test) {
    [$name, $method, $path, $fields, $jar, $files, $expectedRegex, $scope] = $test;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: RACSocial-Phase1-PHP82-Probe',
        ],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($files)) {
            foreach ($files as $fieldName => $filePath) {
                $fields[$fieldName] = new CURLFile($filePath, 'image/png', basename($filePath));
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $body = is_string($raw) ? substr($raw, $headerSize) : '';
    $bodyHash = hash('sha256', $body);
    $bodyPreview = preg_replace('/\s+/', ' ', strip_tags(substr($body, 0, 240))) ?? '';
    $hasPhpFatal = preg_match('/<b>\s*(Fatal error|Parse error|Deprecated|Warning|Notice)\s*<\/b>|(Fatal error|Parse error):\s+Uncaught|Uncaught\s+(TypeError|Error)|Stack trace:/i', $body) ? 'yes' : 'no';
    $expectedMatch = $expectedRegex === '' ? 'not-required' : (preg_match($expectedRegex, $body) ? 'yes' : 'no');
    $result = ($errno === 0 && $status >= 200 && $status < 500 && $hasPhpFatal === 'no' && $expectedMatch !== 'no') ? 'pass' : 'fail';
    if ($scope === 'disposable-db-mutation' && $result === 'pass') {
        $result = 'pass-disposable-db-mutation';
    } elseif ($scope === 'disposable-db-and-filesystem' && $result === 'pass') {
        $result = 'pass-disposable-db-and-filesystem';
    } elseif ($scope === 'disposable-db-session' && $result === 'pass') {
        $result = 'pass-disposable-session';
    }

    $rows[] = [
        $name,
        $method,
        $path,
        (string) $status,
        (string) $errno,
        $error,
        $hasPhpFatal,
        $expectedMatch,
        $scope,
        $result,
        $bodyHash,
        $bodyPreview,
    ];
}

$handle = fopen($resultsFile, 'wb');
if ($handle === false) {
    fwrite(STDERR, "Unable to write $resultsFile\n");
    exit(1);
}
fputcsv($handle, ['name', 'method', 'path', 'http_status', 'curl_errno', 'curl_error', 'body_php_error_marker', 'expected_match', 'scope', 'result', 'body_sha256', 'body_preview']);
foreach ($rows as $row) {
    fputcsv($handle, $row);
}
fclose($handle);

$summary = ['pass' => 0, 'fail' => 0, 'other' => 0];
foreach ($rows as $row) {
    if (strpos($row[9], 'pass') === 0) {
        $summary['pass']++;
    } elseif ($row[9] === 'fail') {
        $summary['fail']++;
    } else {
        $summary['other']++;
    }
}

echo json_encode([
    'results' => str_replace('\\', '/', substr($resultsFile, strlen($root) + 1)),
    'summary' => $summary,
], JSON_PRETTY_PRINT) . PHP_EOL;
