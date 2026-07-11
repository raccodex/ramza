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

    public function verify(string $purchaseCode, string $siteUrl): array
    {
        if (trim($purchaseCode) === '') {
            return ['ok' => false, 'type' => 'invalid', 'message' => 'A purchase code is required before installation can continue.'];
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
                'site_url' => $siteUrl,
                'installer_version' => RACSOCIAL_INSTALLER_VERSION,
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
        if (($decoded['status'] ?? '') === 'SUCCESS' || ($decoded['ok'] ?? false) === true) {
            return ['ok' => true, 'type' => 'verified', 'message' => 'License verified.'];
        }
        if (($decoded['error'] ?? '') === 'domain_mismatch') {
            return ['ok' => false, 'type' => 'domain-mismatch', 'message' => 'The purchase code is already associated with a different domain.'];
        }

        return ['ok' => false, 'type' => 'invalid', 'message' => 'The purchase code could not be verified.'];
    }
}
