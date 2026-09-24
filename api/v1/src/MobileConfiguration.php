<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class MobileConfiguration
{
    private const PUBLIC_KEYS = [
        'app_name',
        'primary_color',
        'secondary_color',
        'minimum_android_version',
        'minimum_ios_version',
        'latest_android_version',
        'latest_ios_version',
        'force_update',
        'maintenance_mode',
        'maintenance_message',
        'android_store_url',
        'ios_store_url',
        'terms_url',
        'privacy_url',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function client(string $publicClientId): array
    {
        $publicClientId = trim($publicClientId);
        if ($publicClientId === '') {
            $configured = $this->database->one(
                'SELECT `setting_value` FROM `Ramza_MobileSettings` WHERE `setting_key` = ? LIMIT 1',
                's',
                ['public_client_id']
            );
            $publicClientId = trim((string) ($configured['setting_value'] ?? ''));
        }

        $client = null;
        if ($publicClientId !== '') {
            $client = $this->database->one(
                'SELECT `id`,`public_client_id`,`display_name`,`android_package`,`ios_bundle_id`,`enabled`
                 FROM `Ramza_MobileApiClients` WHERE `public_client_id` = ? LIMIT 1',
                's',
                [$publicClientId]
            );
        }

        // Upgrade compatibility: installations created before the configured
        // client setting was introduced can still use their first enabled
        // mobile client. The resolved id remains bound to every token/session.
        if ($client === null && $publicClientId === '') {
            $client = $this->database->one(
                'SELECT `id`,`public_client_id`,`display_name`,`android_package`,`ios_bundle_id`,`enabled`
                 FROM `Ramza_MobileApiClients` WHERE `enabled` = 1 ORDER BY `id` ASC LIMIT 1'
            );
        }

        if ($client === null || (int) $client['enabled'] !== 1) {
            throw new ApiException(403, 'CLIENT_DISABLED', 'This mobile application is not enabled.');
        }
        return $client;
    }

    public function assertMobileAvailable(): array
    {
        $enabled = $this->database->one(
            'SELECT `setting_value` FROM `Ramza_MobileSettings` WHERE `setting_key` = ? LIMIT 1',
            's',
            ['mobile_enabled']
        );
        if ($enabled === null || $enabled['setting_value'] !== '1') {
            throw new ApiException(503, 'MOBILE_APP_DISABLED', 'Mobile application access is disabled.');
        }

        return ['status' => 'enabled'];
    }

    public function publicPayload(array $client): array
    {
        global $wo;

        $this->assertMobileAvailable();
        $placeholders = implode(',', array_fill(0, count(self::PUBLIC_KEYS), '?'));
        $rows = $this->database->all(
            "SELECT `setting_key`,`setting_value` FROM `Ramza_MobileSettings`
             WHERE `is_encrypted` = 0 AND `setting_key` IN ($placeholders)",
            str_repeat('s', count(self::PUBLIC_KEYS)),
            self::PUBLIC_KEYS
        );
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $config = is_array($wo['config'] ?? null) ? $wo['config'] : [];
        $currencyCode = SystemCurrency::code();
        $currencySymbol = SystemCurrency::symbol($currencyCode);
        $settings['currency'] = $currencyCode;
        $settings['currency_symbol'] = $currencySymbol;

        return [
            'client' => [
                'id' => $client['public_client_id'],
                'android_package' => $client['android_package'],
                'ios_bundle_id' => $client['ios_bundle_id'],
            ],
            'application' => $settings,
            // Admin-driven capabilities so the app mirrors the web configuration
            // (friend vs follow, registration flow, feature availability).
            'capabilities' => [
                // 0 = follow system, 1 = friend system.
                'connectivity_system' => (int) ($config['connectivitySystem'] ?? 0),
                'registration_enabled' => (int) ($config['user_registration'] ?? 0) === 1,
                'auto_username' => (int) ($config['auto_username'] ?? 0) === 1,
                'requires_activation' => (int) ($config['emailValidation'] ?? 0) === 1,
                'verification_channel' => (string) ($config['sms_or_email'] ?? 'mail') === 'sms' ? 'sms' : 'email',
                'website_mode' => (string) ($config['website_mode'] ?? 'default'),
                'points_system' => (int) ($config['points_system'] ?? 0) === 1,
                // Xamarin FundingActivity: all | verified | disabled.
                'funding_request' => (string) ($config['funding_request'] ?? 'all'),
                'games_enabled' => (int) ($config['games'] ?? 0) === 1
                    && (!isset($config['can_use_games']) || (int) $config['can_use_games'] === 1),
                'genders' => $this->genderOptions(),
            ],
        ];
    }

    /** @return array<int, array{key:string,label:string}> */
    private function genderOptions(): array
    {
        global $wo;

        $genders = is_array($wo['genders'] ?? null) ? $wo['genders'] : ['male' => 'Male', 'female' => 'Female'];
        $options = [];
        foreach ($genders as $key => $label) {
            $options[] = ['key' => (string) $key, 'label' => (string) $label];
        }
        return $options ?: [['key' => 'male', 'label' => 'Male'], ['key' => 'female', 'label' => 'Female']];
    }

    private function isLocalDevelopment(): bool
    {
        global $ramza_mobile_local_development, $site_url;

        if (($ramza_mobile_local_development ?? false) !== true) {
            return false;
        }
        $configuredHost = strtolower((string) parse_url((string) ($site_url ?? ''), PHP_URL_HOST));
        if (!in_array($configuredHost, ['localhost', '127.0.0.1'], true)) {
            return false;
        }
        return in_array(RequestContext::clientIp(), ['127.0.0.1', '::1'], true);
    }

    private function localSignedPayload(array $client, array $license): array
    {
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $hostName = strtolower((string) parse_url('http://' . $requestHost, PHP_URL_HOST));
        if (!in_array($hostName, ['localhost', '127.0.0.1', '10.0.2.2'], true)) {
            $requestHost = 'localhost';
            $hostName = 'localhost';
        }
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH);
        $marker = strpos($requestPath, '/api/v1');
        $basePath = $marker === false ? '' : substr($requestPath, 0, $marker);

        return [
            'allowed_hosts' => [$hostName],
            'api_base_url' => 'http://' . $requestHost . rtrim($basePath, '/') . '/api/v1',
            'app_id' => (string) $client['public_client_id'],
            'expires_at' => (int) $license['expires_at'],
            'features' => ['mobile_app', 'local_development'],
            'grace_until' => (int) $license['grace_until'],
            'installation_id' => 'local-development',
            'ios_bundle_id' => (string) $client['ios_bundle_id'],
            'issued_at' => (int) $license['verified_at'],
            'license_id' => 'local-development',
            'nonce' => bin2hex(random_bytes(16)),
            'package_name' => (string) $client['android_package'],
            'status' => 'active',
        ];
    }

    private function validateLiveLicense(array $license, int $now): void
    {
        global $wo;

        $payload = json_decode((string) ($license['signed_payload'] ?? ''), true);
        $signature = (string) ($license['signature'] ?? '');
        if (!is_array($payload) || $signature === ''
            || !function_exists('Ramza_MobileVerifyLicensePayload')
            || !\Ramza_MobileVerifyLicensePayload($payload, $signature)) {
            throw new ApiException(403, 'SIGNATURE_INVALID', 'The mobile license signature is invalid.');
        }

        $requiredStrings = [
            'audience', 'api_base_url', 'app_id', 'installation_id',
            'ios_bundle_id', 'issuer', 'license_id', 'package_name', 'status',
        ];
        foreach ($requiredStrings as $key) {
            if (!isset($payload[$key]) || !is_string($payload[$key]) || trim($payload[$key]) === '') {
                throw new ApiException(403, 'LICENSE_BINDING_INVALID', 'The mobile license binding is incomplete.');
            }
        }
        if ((int) ($payload['schema_version'] ?? 0) !== 2
            || !hash_equals('ramza-mobile-config', (string) $payload['audience'])
            || !hash_equals('license.expeazzy.com', strtolower((string) $payload['issuer']))
            || !hash_equals('active', (string) $payload['status'])
            || !in_array('mobile_app', is_array($payload['features'] ?? null) ? $payload['features'] : [], true)) {
            throw new ApiException(403, 'LICENSE_BINDING_INVALID', 'The mobile license purpose is invalid.');
        }

        $issuedAt = (int) ($payload['issued_at'] ?? 0);
        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        $graceUntil = (int) ($payload['grace_until'] ?? 0);
        if ($issuedAt <= 0 || $issuedAt > $now + 300 || $expiresAt <= $issuedAt
            || $graceUntil < $expiresAt || $now > $graceUntil
            || (int) $license['expires_at'] !== $expiresAt
            || (int) $license['grace_until'] !== $graceUntil
            || !hash_equals((string) $license['license_id'], (string) $payload['license_id'])
            || !hash_equals((string) $license['installation_id'], (string) $payload['installation_id'])) {
            throw new ApiException(403, 'LICENSE_EXPIRED', 'The mobile license time window is invalid.');
        }

        $settings = $this->database->all(
            'SELECT `setting_key`,`setting_value` FROM `Ramza_MobileSettings`
             WHERE `setting_key` IN (?,?,?,?)',
            'ssss',
            ['public_client_id', 'license_installation_id', 'android_package', 'ios_bundle_id']
        );
        $expected = [];
        foreach ($settings as $row) {
            $expected[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        if (!hash_equals((string) ($expected['public_client_id'] ?? ''), (string) $payload['app_id'])
            || !hash_equals((string) ($expected['license_installation_id'] ?? ''), (string) $payload['installation_id'])
            || !hash_equals((string) ($expected['android_package'] ?? ''), (string) $payload['package_name'])
            || !hash_equals((string) ($expected['ios_bundle_id'] ?? ''), (string) $payload['ios_bundle_id'])) {
            throw new ApiException(403, 'LICENSE_BINDING_INVALID', 'The mobile license does not match this application.');
        }

        $siteUrl = rtrim((string) ($wo['config']['site_url'] ?? ''), '/');
        $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $allowedHosts = is_array($payload['allowed_hosts'] ?? null) ? $payload['allowed_hosts'] : [];
        $allowedHosts = array_map(static fn (mixed $host): string => strtolower(trim((string) $host)), $allowedHosts);
        if ($siteHost === '' || !in_array($siteHost, $allowedHosts, true)
            || !hash_equals($siteUrl . '/api/v1', rtrim((string) $payload['api_base_url'], '/'))) {
            throw new ApiException(403, 'LICENSE_BINDING_INVALID', 'The mobile license does not match this server.');
        }
    }
}
