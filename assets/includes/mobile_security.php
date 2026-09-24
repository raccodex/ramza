<?php
declare(strict_types=1);

if (!function_exists('Ramza_MobileRequireSodium')) {
    function Ramza_MobileRequireSodium(): void
    {
        $requiredFunctions = [
            'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
            'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
            'sodium_crypto_generichash',
            'sodium_crypto_sign_verify_detached',
        ];
        $requiredConstants = [
            'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES',
            'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES',
            'SODIUM_CRYPTO_SIGN_BYTES',
        ];

        foreach ($requiredFunctions as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('The Sodium PHP extension is required for mobile security.');
            }
        }
        foreach ($requiredConstants as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException('The Sodium PHP extension is required for mobile security.');
            }
        }
    }
}

if (!function_exists('Ramza_MobileSecretKey')) {
    function Ramza_MobileSecretKey(): string
    {
        global $siteEncryptKey;

        Ramza_MobileRequireSodium();
        $configured = trim((string) ($siteEncryptKey ?? ''));
        if (strlen($configured) < 32) {
            throw new RuntimeException('Authenticated mobile-setting encryption is not configured.');
        }
        $material = ctype_xdigit($configured) && strlen($configured) % 2 === 0
            ? hex2bin($configured)
            : $configured;
        if (!is_string($material) || strlen($material) < 16) {
            throw new RuntimeException('The mobile-setting encryption key is invalid.');
        }
        return sodium_crypto_generichash('ramza-mobile-settings-v1', $material, 32);
    }
}

if (!function_exists('Ramza_MobileEncrypt')) {
    function Ramza_MobileEncrypt(string $plaintext): string
    {
        Ramza_MobileRequireSodium();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            'ramza-mobile-setting-v1',
            $nonce,
            Ramza_MobileSecretKey()
        );
        return 'rms1:' . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }
}

if (!function_exists('Ramza_MobileDecrypt')) {
    function Ramza_MobileDecrypt(string $encoded): string
    {
        Ramza_MobileRequireSodium();
        if (!str_starts_with($encoded, 'rms1:')) {
            throw new RuntimeException('The saved mobile setting has an unsupported format.');
        }
        $value = substr($encoded, 5);
        $normalized = strtr($value, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (!is_string($decoded) || strlen($decoded) <= $nonceLength) {
            throw new RuntimeException('The saved mobile setting is invalid.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($decoded, $nonceLength),
            'ramza-mobile-setting-v1',
            substr($decoded, 0, $nonceLength),
            Ramza_MobileSecretKey()
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('The saved mobile setting could not be authenticated.');
        }
        return $plaintext;
    }
}

if (!function_exists('Ramza_MobileMaskCode')) {
    function Ramza_MobileMaskCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Not saved';
        }
        if (strlen($value) <= 10) {
            return substr($value, 0, 2) . '-****-' . substr($value, -2);
        }
        return substr($value, 0, 4) . '-****-****-' . substr($value, -4);
    }
}

if (!function_exists('Ramza_MobileCanonicalValue')) {
    function Ramza_MobileCanonicalValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map('Ramza_MobileCanonicalValue', $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = Ramza_MobileCanonicalValue($item);
            }
        }
        return $value;
    }
}

if (!function_exists('Ramza_MobileCanonicalJson')) {
    function Ramza_MobileCanonicalJson(array $payload): string
    {
        return json_encode(
            Ramza_MobileCanonicalValue($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}

if (!function_exists('Ramza_MobileDecodeBase64Url')) {
    function Ramza_MobileDecodeBase64Url(string $value): string
    {
        $normalized = strtr(trim($value), '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        if (!is_string($decoded)) {
            throw new RuntimeException('Invalid encoded signing value.');
        }
        return $decoded;
    }
}

if (!function_exists('Ramza_MobileLicensePublicKey')) {
    function Ramza_MobileLicensePublicKey(): string
    {
        global $ramza_mobile_license_public_key;

        Ramza_MobileRequireSodium();
        $environmentKey = getenv('RAMZA_LICENSE_ED25519_PUBLIC_KEY');
        $configuredKey = trim((string) ($ramza_mobile_license_public_key ?? ''));
        $encoded = $configuredKey !== ''
            ? $configuredKey
            : trim(is_string($environmentKey) ? $environmentKey : '');
        if ($encoded === '' && function_exists('Ramza_MobileSetting')) {
            global $sqlConnect;
            if ($sqlConnect instanceof mysqli) {
                $encoded = trim(Ramza_MobileSetting('license_public_key'));
            }
        }
        if ($encoded === '' && function_exists('Ramza_MobileDiscoverLicensePublicKey')) {
            $encoded = Ramza_MobileDiscoverLicensePublicKey();
        }
        if ($encoded === '') {
            throw new RuntimeException('The mobile license verification public key is not configured.');
        }
        $decoded = ctype_xdigit($encoded) && strlen($encoded) === 64
            ? hex2bin($encoded)
            : Ramza_MobileDecodeBase64Url($encoded);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('The mobile license verification public key is invalid.');
        }
        return $decoded;
    }
}

if (!function_exists('Ramza_MobileVerifyLicensePayload')) {
    function Ramza_MobileVerifyLicensePayload(array $payload, string $signature): bool
    {
        try {
            $signatureBytes = Ramza_MobileDecodeBase64Url($signature);
            if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }
            return sodium_crypto_sign_verify_detached(
                $signatureBytes,
                Ramza_MobileCanonicalJson($payload),
                Ramza_MobileLicensePublicKey()
            );
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('Ramza_MobileSetting')) {
    function Ramza_MobileSetting(string $key, string $default = '', bool $decrypt = false): string
    {
        global $sqlConnect;

        $statement = mysqli_prepare(
            $sqlConnect,
            'SELECT `setting_value`,`is_encrypted` FROM `Ramza_MobileSettings` WHERE `setting_key` = ? LIMIT 1'
        );
        if (!$statement) {
            return $default;
        }
        mysqli_stmt_bind_param($statement, 's', $key);
        mysqli_stmt_execute($statement);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        if (!is_array($row)) {
            return $default;
        }
        $value = (string) $row['setting_value'];
        if ($decrypt && (int) $row['is_encrypted'] === 1 && $value !== '') {
            return Ramza_MobileDecrypt($value);
        }
        return $value;
    }
}

if (!function_exists('Ramza_SaveMobileSetting')) {
    function Ramza_SaveMobileSetting(string $key, string $value, bool $encrypt = false): bool
    {
        global $sqlConnect;

        $stored = $encrypt && $value !== '' ? Ramza_MobileEncrypt($value) : $value;
        $encrypted = $encrypt ? 1 : 0;
        $updatedAt = time();
        $statement = mysqli_prepare(
            $sqlConnect,
            'INSERT INTO `Ramza_MobileSettings` (`setting_key`,`setting_value`,`is_encrypted`,`updated_at`)
             VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`),
             `is_encrypted` = VALUES(`is_encrypted`), `updated_at` = VALUES(`updated_at`)'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'ssii', $key, $stored, $encrypted, $updatedAt);
        $saved = mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        return $saved;
    }
}

if (!function_exists('Ramza_MobileTablesReady')) {
    function Ramza_MobileTablesReady(): bool
    {
        global $sqlConnect;

        $result = mysqli_query(
            $sqlConnect,
            "SELECT COUNT(*) AS table_count FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN
             ('Ramza_MobileApiClients','Ramza_MobileSessions','Ramza_MobileSettings','Ramza_MobileLicenseCache')"
        );
        $row = $result ? mysqli_fetch_assoc($result) : null;
        return is_array($row) && (int) $row['table_count'] === 4;
    }
}

if (!function_exists('Ramza_MobileActivateLicense')) {
    function Ramza_MobileLicenseEndpoint(): string
    {
        $configured = getenv('RAMZA_MOBILE_LICENSE_ENDPOINT');
        $endpoint = is_string($configured) && trim($configured) !== ''
            ? rtrim(trim($configured), '/')
            : 'https://license.expeazzy.com/api/v1/licenses';
        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $local = in_array($host, array('localhost', '127.0.0.1', '::1'), true);
        if (!is_array($parts) || $host === '' || ($scheme !== 'https' && !($local && $scheme === 'http'))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('The mobile verification endpoint is invalid.');
        }
        return $endpoint;
    }

    function Ramza_MobileDiscoverLicensePublicKey(): string
    {
        $url = Ramza_MobileLicenseEndpoint() . '/public-key';
        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($curl);
            curl_close($curl);
            if (is_string($raw) && $raw !== '' && $lastError === '' && $status >= 200 && $status < 300) {
                try {
                    $response = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $response = null;
                }
                $encoded = is_array($response) ? trim((string) ($response['public_key'] ?? '')) : '';
                try {
                    if ($encoded !== '' && strlen(Ramza_MobileDecodeBase64Url($encoded)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                        if (function_exists('Ramza_SaveMobileSetting')) {
                            Ramza_SaveMobileSetting('license_public_key', $encoded, false);
                        }
                        return $encoded;
                    }
                } catch (Throwable) {
                    // Treat malformed discovery data as unavailable.
                }
                break;
            }
            if (!in_array($status, [0, 429, 502, 503, 504], true)) {
                break;
            }
            usleep(($attempt + 1) * 250000);
        }
        error_log('Ramza mobile public-key discovery unavailable for ' . (string) parse_url($url, PHP_URL_HOST)
            . ($lastError !== '' ? ' (' . preg_replace('/[\r\n]+/', ' ', $lastError) . ')' : ''));
        return '';
    }

    function Ramza_MobileLicenseRequest(string $action, array $requestPayload): array
    {
        if (!in_array($action, array('activate', 'refresh', 'status', 'deactivate'), true)) {
            throw new InvalidArgumentException('The mobile license action is invalid.');
        }
        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $idempotencyKey = sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );
        $requestPayload['nonce'] = $nonce;
        $requestPayload['timestamp'] = time();

        $endpoint = Ramza_MobileLicenseEndpoint() . '/' . $action;
        $raw = false;
        $status = 0;
        $curlError = '';
        $curlErrorNumber = 0;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $curl = curl_init($endpoint);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Idempotency-Key: ' . $idempotencyKey,
                    'X-Request-Timestamp: ' . $requestPayload['timestamp'],
                    'X-Request-Nonce: ' . $nonce,
                ],
                CURLOPT_POSTFIELDS => json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $curlErrorNumber = curl_errno($curl);
            $curlError = curl_error($curl);
            curl_close($curl);
            if (is_string($raw) && $raw !== '' && $curlError === '' && !in_array($status, [429, 502, 503, 504], true)) {
                break;
            }
            usleep(($attempt + 1) * 250000);
        }

        if (!is_string($raw) || $raw === '' || $curlError !== '') {
            error_log('Ramza mobile license transport failure for ' . (string) parse_url($endpoint, PHP_URL_HOST)
                . ' action=' . $action . ' curl_errno=' . $curlErrorNumber
                . ($curlError !== '' ? ' error=' . preg_replace('/[\r\n]+/', ' ', $curlError) : ''));
            return ['success' => false, 'code' => 'VERIFICATION_UNAVAILABLE'];
        }
        try {
            $response = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'success' => false,
                'code' => in_array($status, [429, 502, 503, 504], true)
                    ? 'VERIFICATION_UNAVAILABLE'
                    : 'VERIFICATION_INVALID_RESPONSE',
            ];
        }
        if (!is_array($response) || $status < 200 || $status >= 300 || empty($response['success'])) {
            return [
                'success' => false,
                'code' => preg_match('/^[A-Z0-9_]{3,64}$/', (string) ($response['code'] ?? ''))
                    ? (string) $response['code']
                    : 'LICENSE_INVALID',
            ];
        }

        if ($action === 'deactivate') {
            return ['success' => true, 'status' => (string) ($response['status'] ?? 'deactivated')];
        }

        $signature = (string) ($response['signature'] ?? '');
        $signedPayload = is_array($response['signed_payload'] ?? null)
            ? $response['signed_payload']
            : array_diff_key($response, array_flip(['success', 'signature', 'message']));
        if ($signature === '' || !Ramza_MobileVerifyLicensePayload($signedPayload, $signature)) {
            return ['success' => false, 'code' => 'SIGNATURE_INVALID'];
        }
        if (!hash_equals($nonce, (string) ($signedPayload['nonce'] ?? ''))) {
            return ['success' => false, 'code' => 'REPLAY_CHECK_FAILED'];
        }
        $activationToken = preg_match(
            '/^[A-Za-z0-9_-]{40,128}$/',
            (string) ($response['activation_token'] ?? '')
        ) === 1 ? (string) $response['activation_token'] : '';
        if ($action === 'activate' && $activationToken === '') {
            return ['success' => false, 'code' => 'INSTALLATION_CREDENTIAL_MISSING'];
        }
        return [
            'success' => true,
            'payload' => $signedPayload,
            'signature' => $signature,
            'activation_token' => $activationToken,
        ];
    }

    function Ramza_MobileActivateLicense(array $requestPayload): array
    {
        return Ramza_MobileLicenseRequest('activate', $requestPayload);
    }
}

if (!function_exists('Ramza_MobilePersistLicense')) {
    function Ramza_MobilePersistLicense(array $payload, string $signature, string $activationToken = ''): bool
    {
        global $sqlConnect;

        $status = in_array((string) ($payload['status'] ?? ''), array('active', 'grace'), true)
            ? (string) $payload['status']
            : 'invalid';
        $licenseId = substr((string) ($payload['license_id'] ?? ''), 0, 100);
        $installationId = substr((string) ($payload['installation_id'] ?? ''), 0, 100);
        $verifiedAt = (int) ($payload['issued_at'] ?? time());
        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        $graceUntil = (int) ($payload['grace_until'] ?? $expiresAt);
        if ($licenseId === '' || $installationId === '' || $expiresAt <= $verifiedAt || $signature === '') {
            return false;
        }
        $nextCheckAt = min($expiresAt, time() + 21600);
        $features = json_encode($payload['features'] ?? array(), JSON_UNESCAPED_SLASHES) ?: '[]';
        $signedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $lastError = '';
        $updatedAt = time();
        $statement = mysqli_prepare(
            $sqlConnect,
            'INSERT INTO `Ramza_MobileLicenseCache`
             (`id`,`license_id`,`installation_id`,`status`,`features_json`,`signed_payload`,`signature`,`verified_at`,
              `expires_at`,`grace_until`,`next_check_at`,`last_error_code`,`updated_at`)
             VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE `license_id`=VALUES(`license_id`),`installation_id`=VALUES(`installation_id`),
             `status`=VALUES(`status`),`features_json`=VALUES(`features_json`),`signed_payload`=VALUES(`signed_payload`),
             `signature`=VALUES(`signature`),`verified_at`=VALUES(`verified_at`),`expires_at`=VALUES(`expires_at`),
             `grace_until`=VALUES(`grace_until`),`next_check_at`=VALUES(`next_check_at`),`last_error_code`=VALUES(`last_error_code`),
             `updated_at`=VALUES(`updated_at`)'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'ssssssiiiisi',
            $licenseId,
            $installationId,
            $status,
            $features,
            $signedPayload,
            $signature,
            $verifiedAt,
            $expiresAt,
            $graceUntil,
            $nextCheckAt,
            $lastError,
            $updatedAt
        );
        $saved = mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        if ($saved && $activationToken !== '') {
            $saved = Ramza_SaveMobileSetting('license_activation_token', $activationToken, true);
        }
        return $saved;
    }
}

if (!function_exists('Ramza_MobileRefreshLicense')) {
    function Ramza_MobileRefreshLicense(bool $force = false): array
    {
        global $sqlConnect;

        if (!Ramza_MobileTablesReady()) {
            return ['success' => false, 'code' => 'MOBILE_SCHEMA_UNAVAILABLE'];
        }
        $result = mysqli_query($sqlConnect, 'SELECT * FROM `Ramza_MobileLicenseCache` WHERE `id` = 1 LIMIT 1');
        $row = $result ? mysqli_fetch_assoc($result) : null;
        if (!is_array($row) || empty($row['license_id']) || empty($row['signature'])) {
            return ['success' => false, 'code' => 'ADDON_LICENSE_REQUIRED'];
        }
        if (!$force && (int) $row['next_check_at'] > time()) {
            return ['success' => true, 'status' => (string) $row['status'], 'cached' => true];
        }
        $payload = json_decode((string) $row['signed_payload'], true);
        if (!is_array($payload) || !Ramza_MobileVerifyLicensePayload($payload, (string) $row['signature'])) {
            mysqli_query(
                $sqlConnect,
                "UPDATE `Ramza_MobileLicenseCache` SET `status`='invalid',`last_error_code`='SIGNATURE_INVALID',`updated_at`=" . time() . ' WHERE `id`=1'
            );
            return ['success' => false, 'code' => 'SIGNATURE_INVALID'];
        }
        $response = Ramza_MobileLicenseRequest('refresh', array(
            'license_id' => (string) $row['license_id'],
            'installation_id' => (string) $row['installation_id'],
            'activation_token' => Ramza_MobileSetting('license_activation_token', '', true),
            'signed_payload' => $payload,
            'signature' => (string) $row['signature'],
        ));
        if (!empty($response['success']) && Ramza_MobilePersistLicense(
            $response['payload'],
            (string) $response['signature'],
            (string) ($response['activation_token'] ?? '')
        )) {
            return ['success' => true, 'status' => (string) ($response['payload']['status'] ?? 'active'), 'cached' => false];
        }

        $code = preg_match('/^[A-Z0-9_]{3,64}$/', (string) ($response['code'] ?? ''))
            ? (string) $response['code']
            : 'VERIFICATION_UNAVAILABLE';
        $graceUntil = (int) $row['grace_until'];
        $status = time() <= $graceUntil && in_array((string) $row['status'], array('active', 'grace'), true)
            ? 'grace'
            : (in_array($code, array('LICENSE_REVOKED', 'LICENSE_SUSPENDED', 'LICENSE_EXPIRED'), true)
                ? strtolower(substr($code, 8))
                : 'unavailable');
        $nextCheckAt = time() + 3600;
        $statement = mysqli_prepare(
            $sqlConnect,
            'UPDATE `Ramza_MobileLicenseCache` SET `status`=?,`last_error_code`=?,`next_check_at`=?,`updated_at`=? WHERE `id`=1'
        );
        mysqli_stmt_bind_param($statement, 'ssii', $status, $code, $nextCheckAt, $nextCheckAt);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        return ['success' => false, 'status' => $status, 'code' => $code];
    }
}

if (!function_exists('Ramza_MobileDeactivateLicense')) {
    function Ramza_MobileDeactivateLicense(): array
    {
        global $sqlConnect;

        $result = mysqli_query($sqlConnect, 'SELECT * FROM `Ramza_MobileLicenseCache` WHERE `id` = 1 LIMIT 1');
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $payload = is_array($row) ? json_decode((string) $row['signed_payload'], true) : null;
        if (!is_array($row) || !is_array($payload) || !Ramza_MobileVerifyLicensePayload($payload, (string) $row['signature'])) {
            return ['success' => false, 'code' => 'SIGNATURE_INVALID'];
        }
        $response = Ramza_MobileLicenseRequest('deactivate', array(
            'license_id' => (string) $row['license_id'],
            'installation_id' => (string) $row['installation_id'],
            'activation_token' => Ramza_MobileSetting('license_activation_token', '', true),
            'signed_payload' => $payload,
            'signature' => (string) $row['signature'],
        ));
        if (!empty($response['success'])) {
            mysqli_query(
                $sqlConnect,
                "UPDATE `Ramza_MobileLicenseCache` SET `status`='revoked',`next_check_at`=0,`updated_at`=" . time() . ' WHERE `id`=1'
            );
        }
        return $response;
    }
}
