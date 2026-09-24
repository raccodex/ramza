<?php
declare(strict_types=1);

require_once __DIR__ . '/../assets/includes/ramza_updater.php';

$url = 'https://license.expeazzy.com/api/ramza/verify/';
$options = Ramza_UpdateCurlNetworkOptions($url);
if (empty($options[CURLOPT_RESOLVE]) || !is_array($options[CURLOPT_RESOLVE])) {
    throw new RuntimeException('The updater did not pre-resolve the license host.');
}
$safeError = Ramza_UpdateNetworkError(6, 'getaddrinfo() thread failed to start', 'Network failure.');
if (stripos($safeError, 'getaddrinfo') !== false || stripos($safeError, 'resolved') === false) {
    throw new RuntimeException('The updater did not normalize the DNS resolver failure.');
}

$curl = curl_init($url);
if ($curl === false) {
    throw new RuntimeException('cURL could not be initialized.');
}
curl_setopt_array($curl, $options + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
]);
$body = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$errorNumber = curl_errno($curl);
$error = curl_error($curl);
curl_close($curl);

if (!is_string($body) || $body === '' || $status < 200 || $status >= 500) {
    throw new RuntimeException(Ramza_UpdateNetworkError($errorNumber, $error, 'The license host did not respond.'));
}

echo "PASS updater pre-resolved HTTPS transport (HTTP {$status})\n";
