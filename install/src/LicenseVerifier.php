<?php
declare(strict_types=1);

interface RACInstallerHttpClient
{
    public function post(string $url, array $fields, int $connectTimeout, int $timeout): array;
}

final class RACInstallerCurlHttpClient implements RACInstallerHttpClient
{
    public function post(string $url, array $fields, int $connectTimeout, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RACInstallerSystemException('Unable to initialize HTTPS request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT => 'RACSocialInstaller/' . RACSOCIAL_INSTALLER_VERSION,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'errno' => $errno,
            'error' => $error,
            'body' => is_string($body) ? $body : '',
        ];
    }
}

final class RACInstallerLicenseVerifier
{
    public function __construct(
        private readonly RACInstallerLogger $logger,
        private readonly RACInstallerHttpClient $httpClient = new RACInstallerCurlHttpClient(),
        private readonly string $endpoint = ''
    ) {
    }

    public function verify(string $purchaseCode, string $siteUrl, string $envatoUsername, string $installationType = 'fresh'): array
    {
        $purchaseCode = trim($purchaseCode);
        $envatoUsername = trim($envatoUsername);

        if ($purchaseCode === '') {
            return ['ok' => false, 'type' => 'invalid', 'message' => 'A purchase code is required before installation can continue.'];
        }
        if (!preg_match('/^[A-Fa-f0-9-]{24,80}$/', $purchaseCode)) {
            return ['ok' => false, 'type' => 'invalid', 'message' => 'Enter the full purchase code from your CodeCanyon downloads page.'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/', $envatoUsername)) {
            return ['ok' => false, 'type' => 'invalid-username', 'message' => 'Enter the CodeCanyon username that purchased this item.'];
        }

        $siteParts = parse_url($siteUrl);
        $siteHost = is_array($siteParts) ? strtolower((string) ($siteParts['host'] ?? '')) : '';
        if ($siteHost === '') {
            return ['ok' => false, 'type' => 'invalid-site', 'message' => 'Enter a complete site URL before license verification.'];
        }

        if ($this->endpoint === '') {
            $this->logger->info('license-verification', ['result' => 'contract-missing']);
            return [
                'ok' => false,
                'type' => 'contract-missing',
                'message' => 'This package does not contain a legitimate production license verification contract. Installation is stopped before database import.',
            ];
        }

        $endpointParts = parse_url($this->endpoint);
        if (!is_array($endpointParts) || strtolower((string) ($endpointParts['scheme'] ?? '')) !== 'https' || empty($endpointParts['host']) || isset($endpointParts['user']) || isset($endpointParts['pass'])) {
            $this->logger->info('license-verification', ['result' => 'unsafe-contract-endpoint']);
            return ['ok' => false, 'type' => 'contract-invalid', 'message' => 'The configured license service is not a safe HTTPS endpoint. Installation was stopped.'];
        }

        try {
            $response = $this->httpClient->post($this->endpoint, [
                'purchase_code' => $purchaseCode,
                'envato_username' => $envatoUsername,
                'site_url' => $siteUrl,
                'site_host' => $siteHost,
                'site_host_hash' => hash('sha256', $siteHost),
                'item_id' => defined('RACSOCIAL_ENVATO_ITEM_ID') ? (string) constant('RACSOCIAL_ENVATO_ITEM_ID') : '',
                'installer_version' => RACSOCIAL_INSTALLER_VERSION,
                'installation_type' => $installationType === 'migrate' ? 'wowonder_migration' : 'fresh',
            ], 5, 15);
        } catch (Throwable $error) {
            $this->logger->error('license-verification', $error);
            return ['ok' => false, 'type' => 'network', 'message' => 'License verification could not reach the remote service. No database changes were made.'];
        }

        if (($response['errno'] ?? 0) !== 0) {
            $this->logger->info('license-verification', ['curl_errno' => $response['errno']]);
            return ['ok' => false, 'type' => 'network', 'message' => 'License verification failed because the remote service was unreachable.'];
        }
        if (($response['status'] ?? 0) >= 500) {
            return ['ok' => false, 'type' => 'remote-error', 'message' => 'The license verification service returned a temporary server error.'];
        }
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return ['ok' => false, 'type' => 'invalid', 'message' => 'The purchase code could not be verified.'];
        }

        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'type' => 'malformed', 'message' => 'The license verification service returned an unreadable response.'];
        }
        $remoteBuyer = strtolower((string) ($decoded['buyer'] ?? $decoded['buyer_username'] ?? $decoded['envato_username'] ?? ''));
        if ($remoteBuyer !== '' && !hash_equals(strtolower($envatoUsername), $remoteBuyer)) {
            $this->logger->info('license-verification', ['result' => 'buyer-mismatch']);
            return ['ok' => false, 'type' => 'buyer-mismatch', 'message' => 'The purchase code does not belong to that CodeCanyon username.'];
        }

        $expectedItemId = defined('RACSOCIAL_ENVATO_ITEM_ID') ? (string) constant('RACSOCIAL_ENVATO_ITEM_ID') : '';
        $remoteItemId = (string) ($decoded['item_id'] ?? $decoded['item']['id'] ?? '');
        if ($expectedItemId !== '' && ($remoteItemId === '' || !hash_equals($expectedItemId, $remoteItemId))) {
            $this->logger->info('license-verification', ['result' => 'item-mismatch']);
            return ['ok' => false, 'type' => 'item-mismatch', 'message' => 'This purchase code belongs to a different item.'];
        }

        if (($decoded['status'] ?? '') === 'SUCCESS' || ($decoded['ok'] ?? false) === true) {
            $certificate = $decoded['certificate'] ?? null;
            if (!is_array($certificate) || empty($certificate['license_id']) || empty($certificate['signature'])) {
                return ['ok' => false, 'type' => 'malformed', 'message' => 'The license verification service did not return a signed certificate.'];
            }
            if (!hash_equals(hash('sha256', $siteHost), (string) ($certificate['site_host_hash'] ?? ''))) {
                return ['ok' => false, 'type' => 'domain-mismatch', 'message' => 'The license certificate was issued for a different domain.'];
            }
            $certificateBuyer = strtolower((string) ($certificate['buyer_username'] ?? ''));
            if ($certificateBuyer !== '' && !hash_equals(strtolower($envatoUsername), $certificateBuyer)) {
                return ['ok' => false, 'type' => 'buyer-mismatch', 'message' => 'The license certificate belongs to a different CodeCanyon username.'];
            }
            $certificateItemId = (string) ($certificate['item_id'] ?? '');
            if ($expectedItemId !== '' && ($certificateItemId === '' || !hash_equals($expectedItemId, $certificateItemId))) {
                return ['ok' => false, 'type' => 'item-mismatch', 'message' => 'The license certificate belongs to a different item.'];
            }
            return [
                'ok' => true,
                'type' => 'verified',
                'message' => 'License verified.',
                'reference' => (string) ($decoded['reference'] ?? $decoded['license_id'] ?? ''),
                'certificate' => $certificate,
            ];
        }
        if (($decoded['error'] ?? '') === 'domain_mismatch') {
            return ['ok' => false, 'type' => 'domain-mismatch', 'message' => 'The purchase code is already associated with a different domain.'];
        }

        return ['ok' => false, 'type' => 'invalid', 'message' => 'The purchase code could not be verified.'];
    }
}
