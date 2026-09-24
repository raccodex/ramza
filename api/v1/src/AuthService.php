<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class AuthService
{
    public function __construct(
        private readonly Database $database,
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogger $audit,
        private readonly AuthChallengeService $challenges
    ) {
    }

    public function login(array $body, array $client): array
    {
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        $platform = strtolower(trim((string) ($body['platform'] ?? '')));
        $this->validateInstallation($installationId, $platform);
        if ($identifier === '' || mb_strlen($identifier) > 190) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A username, email, or phone number is required.', 'identifier');
        }
        if ($password === '' || strlen($password) > 4096) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A password is required.', 'password');
        }

        $this->rateLimiter->enforce('mobile_login_ip', RequestContext::clientIp(), 10, 900);
        $this->rateLimiter->enforce('mobile_login_identifier', $identifier, 6, 900);
        if (!\Wo_Login($identifier, $password)) {
            $this->audit->write('auth.login', 'invalid_credentials', 0, (int) $client['id'], $installationId);
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'The username or password is incorrect.');
        }
        if (\Wo_UserInactive($identifier)) {
            throw new ApiException(403, 'ACCOUNT_DISABLED', 'This account is disabled.');
        }
        if (!\Wo_UserActive($identifier)) {
            throw new ApiException(403, 'ACCOUNT_ACTIVATION_REQUIRED', 'Account activation is required.');
        }

        $userId = (int) \Wo_UserIdForLogin($identifier);
        $user = (array) \Wo_UserData($userId);
        if ($userId <= 0 || (int) ($user['banned'] ?? 0) === 1) {
            throw new ApiException(403, 'ACCOUNT_DISABLED', 'This account is not available.');
        }
        if (!\Wo_VerfiyIP($identifier)) {
            return $this->loginChallenge('unusual_login', $user, $client, $installationId);
        }
        if (!\Wo_TwoFactor($identifier)) {
            return $this->loginChallenge('two_factor', $user, $client, $installationId);
        }

        return [
            'user' => $this->publicUser($user),
            'session' => $this->tokens->issue($userId, (int) $client['id'], $installationId, $platform),
        ];
    }

    public function register(array $body, array $client): array
    {
        global $wo;

        if ((int) ($wo['config']['user_registration'] ?? 0) !== 1) {
            throw new ApiException(403, 'REGISTRATION_DISABLED', 'Registration is currently unavailable.');
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $confirmation = (string) ($body['confirm_password'] ?? '');
        $firstName = trim((string) ($body['first_name'] ?? ''));
        $lastName = trim((string) ($body['last_name'] ?? ''));
        $username = trim((string) ($body['username'] ?? ''));
        $phone = trim((string) ($body['phone_number'] ?? ''));
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        $platform = strtolower(trim((string) ($body['platform'] ?? '')));
        $this->validateInstallation($installationId, $platform);

        $this->rateLimiter->enforce('mobile_register_ip', RequestContext::clientIp(), 5, 3600);
        $this->rateLimiter->enforce('mobile_register_email', $email, 3, 3600);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid email address.', 'email');
        }
        if (\Wo_EmailExists($email)) {
            throw new ApiException(409, 'EMAIL_ALREADY_USED', 'This email address is already registered.', 'email');
        }
        if (strlen($password) < 6 || strlen($password) > 4096) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Password must contain at least 6 characters.', 'password');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Passwords do not match.', 'confirm_password');
        }

        if ((int) ($wo['config']['auto_username'] ?? 0) === 1) {
            if ($firstName === '' || $lastName === '' ||
                preg_match('/[^\p{L}\p{M}\s\'\-]/u', $firstName . $lastName) === 1) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid first and last name.', 'first_name');
            }
            $username = $this->generatedUsername($firstName, $lastName);
        } elseif (preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) !== 1 ||
            in_array(true, (array) \Wo_IsNameExist($username, 0), true) ||
            in_array($username, (array) ($wo['site_pages'] ?? []), true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Use 5 to 32 letters, numbers, or underscores.', 'username');
        }

        $channel = (string) ($wo['config']['sms_or_email'] ?? 'mail');
        if ($channel === 'sms' && ($phone === '' || mb_strlen($phone) > 32)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A phone number is required.', 'phone_number');
        }
        $requiresActivation = (int) ($wo['config']['emailValidation'] ?? 0) === 1;
        $gender = trim((string) ($body['gender'] ?? 'male'));
        if (!array_key_exists($gender, (array) ($wo['genders'] ?? []))) {
            $gender = 'male';
        }
        $timezone = trim((string) ($body['timezone'] ?? 'UTC'));
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $account = [
            'email' => \Wo_Secure($email, 0),
            'username' => \Wo_Secure($username, 0),
            'password' => $password,
            'email_code' => bin2hex(random_bytes(16)),
            'src' => 'Mobile',
            'timezone' => \Wo_Secure($timezone),
            'gender' => \Wo_Secure($gender),
            'lastseen' => time(),
            'active' => $requiresActivation ? '0' : '1',
        ];
        if ($firstName !== '') {
            $account['first_name'] = \Wo_Secure($firstName);
        }
        if ($lastName !== '') {
            $account['last_name'] = \Wo_Secure($lastName);
        }
        if ($phone !== '') {
            $account['phone_number'] = \Wo_Secure($phone);
        }
        if (!\Wo_RegisterUser($account)) {
            throw new ApiException(500, 'REGISTRATION_FAILED', 'The account could not be created.');
        }

        $userId = (int) \Wo_UserIdForLogin($email);
        $user = (array) \Wo_UserData($userId);
        $this->applyRegistrationDefaults($userId);
        if ($requiresActivation) {
            $challenge = $this->challenges->issue('activation', $userId, (int) $client['id'], $installationId);
            $sent = $this->sendVerificationCode($user, (string) $challenge['code'], $channel, 'Account verification');
            $this->audit->write('auth.register', $sent ? 'activation_sent' : 'activation_delivery_failed', $userId, (int) $client['id'], $installationId);
            return [
                'activation_required' => true,
                'challenge' => $this->publicChallenge($challenge, $channel),
            ];
        }

        $this->audit->write('auth.register', 'success', $userId, (int) $client['id'], $installationId);
        return [
            'activation_required' => false,
            'user' => $this->publicUser($user),
            'session' => $this->tokens->issue($userId, (int) $client['id'], $installationId, $platform),
        ];
    }

    public function activate(array $body, array $client): array
    {
        $challengeId = trim((string) ($body['challenge_id'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        $platform = strtolower(trim((string) ($body['platform'] ?? '')));
        $this->validateInstallation($installationId, $platform);
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            throw new ApiException(422, 'VERIFICATION_CODE_INVALID', 'Enter the 6-digit verification code.', 'code');
        }
        $challenge = $this->challenges->verifyCode($challengeId, 'activation', (int) $client['id'], $installationId, $code);
        $userId = (int) $challenge['user_id'];
        $this->database->execute(
            'UPDATE `' . T_USERS . '` SET `active` = 1, `email_code` = \'\' WHERE `user_id` = ? AND `active` = 0',
            'i',
            [$userId]
        );
        $user = (array) \Wo_UserData($userId);
        $this->audit->write('auth.activate', 'success', $userId, (int) $client['id'], $installationId);
        return [
            'user' => $this->publicUser($user),
            'session' => $this->tokens->issue($userId, (int) $client['id'], $installationId, $platform),
        ];
    }

    public function requestPasswordReset(array $body, array $client): array
    {
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        if ($identifier === '' || mb_strlen($identifier) > 190) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter your username, email, or phone number.', 'identifier');
        }
        if (preg_match('/^[A-Za-z0-9._-]{16,128}$/', $installationId) !== 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The installation identifier is invalid.', 'installation_id');
        }
        $this->rateLimiter->enforce('mobile_password_reset_ip', RequestContext::clientIp(), 5, 3600);
        $this->rateLimiter->enforce('mobile_password_reset_identifier', $identifier, 3, 3600);

        $userId = (int) \Wo_UserIdForLogin($identifier);
        $challenge = $this->challenges->issue('password_reset', max(0, $userId), (int) $client['id'], $installationId);
        if ($userId > 0) {
            global $wo;
            $user = (array) \Wo_UserData($userId);
            $channel = (string) ($wo['config']['sms_or_email'] ?? 'mail');
            $this->sendVerificationCode($user, (string) $challenge['code'], $channel, 'Password reset');
        }
        $this->audit->write('auth.password_reset.request', 'accepted', $userId, (int) $client['id'], $installationId);
        return [
            'challenge_id' => $challenge['challenge_id'],
            'expires_at' => $challenge['expires_at'],
        ];
    }

    public function resetPassword(array $body, array $client): void
    {
        $challengeId = trim((string) ($body['challenge_id'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirmation = (string) ($body['confirm_password'] ?? '');
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        if (strlen($password) < 6 || strlen($password) > 4096) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Password must contain at least 6 characters.', 'password');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Passwords do not match.', 'confirm_password');
        }
        $challenge = $this->challenges->verifyCode($challengeId, 'password_reset', (int) $client['id'], $installationId, $code);
        $userId = (int) $challenge['user_id'];
        if ($userId <= 0 || !\Wo_ResetPassword($userId, $password)) {
            throw new ApiException(422, 'VERIFICATION_CODE_INVALID', 'The verification code is invalid or expired.', 'code');
        }
        $now = time();
        $this->database->execute(
            'UPDATE `Ramza_MobileSessions` SET `revoked_at` = ?, `revocation_reason` = \'password_reset\', `updated_at` = ?
             WHERE `user_id` = ? AND `revoked_at` IS NULL',
            'iii',
            [$now, $now, $userId]
        );
        $this->database->execute('DELETE FROM `' . T_APP_SESSIONS . '` WHERE `user_id` = ?', 'i', [$userId]);
        $this->audit->write('auth.password_reset.complete', 'success', $userId, (int) $client['id'], $installationId);
    }

    public function verifyLoginChallenge(array $body, array $client): array
    {
        $challengeId = trim((string) ($body['challenge_id'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        $platform = strtolower(trim((string) ($body['platform'] ?? '')));
        $this->validateInstallation($installationId, $platform);
        if ($code === '' || mb_strlen($code) > 32) {
            throw new ApiException(422, 'VERIFICATION_CODE_INVALID', 'Enter the verification code.', 'code');
        }
        $challenge = $this->challenges->active(
            $challengeId,
            ['two_factor', 'unusual_login'],
            (int) $client['id'],
            $installationId
        );
        $userId = (int) $challenge['user_id'];
        $user = (array) \Wo_UserData($userId);
        if (!$this->validLoginCode($user, $code, (string) $challenge['purpose'])) {
            $this->challenges->reject($challengeId);
            throw new ApiException(422, 'VERIFICATION_CODE_INVALID', 'The verification code is incorrect.', 'code');
        }
        $this->challenges->consume($challengeId);
        if ((string) ($user['two_factor_method'] ?? '') === 'two_factor' || $challenge['purpose'] === 'unusual_login') {
            $this->database->execute('UPDATE `' . T_USERS . '` SET `email_code` = \'\' WHERE `user_id` = ?', 'i', [$userId]);
        }
        $this->audit->write('auth.challenge.verify', 'success', $userId, (int) $client['id'], $installationId, ['purpose' => $challenge['purpose']]);
        return [
            'user' => $this->publicUser($user),
            'session' => $this->tokens->issue($userId, (int) $client['id'], $installationId, $platform),
        ];
    }

    public function socialProviders(): array
    {
        global $wo;

        return [
            'google' => [
                'enabled' => (int) ($wo['config']['googleLogin'] ?? 0) === 1
                    && trim((string) ($wo['config']['googleAppId'] ?? '')) !== '',
                'client_id' => (string) ($wo['config']['googleAppId'] ?? ''),
            ],
            'facebook' => [
                'enabled' => (int) ($wo['config']['facebookLogin'] ?? 0) === 1
                    && trim((string) ($wo['config']['facebookAppId'] ?? '')) !== ''
                    && trim((string) ($wo['config']['facebookAppKey'] ?? '')) !== '',
                'client_id' => (string) ($wo['config']['facebookAppId'] ?? ''),
            ],
        ];
    }

    public function socialLogin(array $body, array $client): array
    {
        global $wo;

        $provider = strtolower(trim((string) ($body['provider'] ?? '')));
        $identityToken = trim((string) ($body['identity_token'] ?? ''));
        $installationId = trim((string) ($body['installation_id'] ?? ''));
        $platform = strtolower(trim((string) ($body['platform'] ?? '')));
        $this->validateInstallation($installationId, $platform);
        if (!in_array($provider, ['google', 'facebook'], true) ||
            $identityToken === '' || strlen($identityToken) > 16384) {
            throw new ApiException(422, 'SOCIAL_CREDENTIAL_INVALID', 'The social sign-in credential is invalid.');
        }
        $providers = $this->socialProviders();
        if (empty($providers[$provider]['enabled'])) {
            throw new ApiException(403, 'SOCIAL_PROVIDER_DISABLED', 'This social sign-in provider is disabled.');
        }
        $this->rateLimiter->enforce('mobile_social_login_ip', RequestContext::clientIp(), 10, 900);

        $identity = $provider === 'google'
            ? $this->verifyGoogleIdentity($identityToken, (string) ($wo['config']['googleAppId'] ?? ''))
            : $this->verifyFacebookIdentity(
                $identityToken,
                (string) ($wo['config']['facebookAppId'] ?? ''),
                (string) ($wo['config']['facebookAppKey'] ?? '')
            );
        $userId = $this->socialUser(
            $provider,
            (string) $identity['subject'],
            (string) $identity['email'],
            (string) $identity['name']
        );
        $user = (array) \Wo_UserData($userId);
        if ($userId <= 0 || (int) ($user['banned'] ?? 0) === 1) {
            throw new ApiException(403, 'ACCOUNT_DISABLED', 'This account is not available.');
        }
        $this->audit->write('auth.social_login', 'success', $userId, (int) $client['id'], $installationId, ['provider' => $provider]);
        return [
            'user' => $this->publicUser($user),
            'session' => $this->tokens->issue($userId, (int) $client['id'], $installationId, $platform),
        ];
    }

    public function publicUser(array $user): array
    {
        return [
            'id' => (int) ($user['user_id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'avatar' => $this->mediaUrl((string) ($user['avatar'] ?? '')),
            'cover' => $this->mediaUrl((string) ($user['cover'] ?? '')),
            'verified' => (bool) ($user['verified'] ?? false),
            'is_pro' => (int) ($user['is_pro'] ?? 0) === 1,
            'pro_type' => (int) ($user['pro_type'] ?? 0),
            'pro_time' => (int) ($user['pro_time'] ?? 0),
        ];
    }

    private function mediaUrl(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return (string) \Wo_GetMedia($value);
    }

    private function loginChallenge(string $purpose, array $user, array $client, string $installationId): array
    {
        $challenge = $this->challenges->issue($purpose, (int) $user['user_id'], (int) $client['id'], $installationId, false);
        return [
            'authentication_required' => true,
            'challenge' => [
                'challenge_id' => $challenge['challenge_id'],
                'purpose' => $purpose,
                'method' => (string) ($user['two_factor_method'] ?? 'two_factor'),
                'expires_at' => $challenge['expires_at'],
            ],
        ];
    }

    private function validLoginCode(array $user, string $code, string $purpose): bool
    {
        if ($purpose === 'unusual_login' || (string) ($user['two_factor_method'] ?? '') === 'two_factor') {
            return hash_equals((string) ($user['email_code'] ?? ''), md5($code));
        }
        if ((string) ($user['two_factor_method'] ?? '') === 'google' && !empty($user['google_secret'])) {
            require_once dirname(__DIR__, 3) . '/assets/libraries/google_auth/vendor/autoload.php';
            try {
                $google = new \PragmaRX\Google2FA\Google2FA();
                if ($google->verifyKey((string) $user['google_secret'], $code)) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        if ((string) ($user['two_factor_method'] ?? '') === 'authy' && !empty($user['authy_id']) && function_exists('verifyAuthy')) {
            return (bool) \verifyAuthy($code, (string) $user['authy_id']);
        }
        return $this->consumeBackupCode((int) ($user['user_id'] ?? 0), $code);
    }

    private function consumeBackupCode(int $userId, string $code): bool
    {
        if ($userId <= 0 || !defined('T_BACKUP_CODES')) {
            return false;
        }
        $row = $this->database->one('SELECT `codes` FROM `' . T_BACKUP_CODES . '` WHERE `user_id` = ? LIMIT 1', 'i', [$userId]);
        $codes = json_decode((string) ($row['codes'] ?? ''), true);
        if (!is_array($codes)) {
            return false;
        }
        $key = array_search($code, $codes, true);
        if ($key === false) {
            return false;
        }
        unset($codes[$key]);
        $this->database->execute(
            'UPDATE `' . T_BACKUP_CODES . '` SET `codes` = ? WHERE `user_id` = ?',
            'si',
            [json_encode(array_values($codes)) ?: '[]', $userId]
        );
        return true;
    }

    private function publicChallenge(array $challenge, string $channel): array
    {
        return [
            'challenge_id' => $challenge['challenge_id'],
            'purpose' => $challenge['purpose'],
            'channel' => $channel === 'sms' ? 'sms' : 'email',
            'expires_at' => $challenge['expires_at'],
        ];
    }

    private function sendVerificationCode(array $user, string $code, string $channel, string $subject): bool
    {
        global $wo;

        $message = $subject . ' code: ' . $code;
        if ($channel === 'sms' && !empty($user['phone_number'])) {
            return (bool) \Wo_SendSMSMessage((string) $user['phone_number'], $message);
        }
        if (empty($user['email'])) {
            return false;
        }
        return (bool) \Wo_SendMessage([
            'from_email' => (string) ($wo['config']['siteEmail'] ?? ''),
            'from_name' => (string) ($wo['config']['siteName'] ?? 'Ramza'),
            'to_email' => (string) $user['email'],
            'to_name' => (string) ($user['name'] ?? $user['username'] ?? ''),
            'subject' => $subject,
            'charSet' => 'utf-8',
            'message_body' => $message,
            'is_html' => false,
        ]);
    }

    private function verifyGoogleIdentity(string $token, string $expectedAudience): array
    {
        if ($expectedAudience === '') {
            throw new ApiException(503, 'SOCIAL_PROVIDER_NOT_CONFIGURED', 'Google sign-in is not configured.');
        }
        $json = $this->providerJson('https://oauth2.googleapis.com/tokeninfo?' . http_build_query(['id_token' => $token]));
        if (!hash_equals($expectedAudience, (string) ($json['aud'] ?? '')) ||
            empty($json['sub']) ||
            !filter_var((string) ($json['email'] ?? ''), FILTER_VALIDATE_EMAIL) ||
            !in_array($json['email_verified'] ?? false, [true, 'true', 1, '1'], true)) {
            throw new ApiException(401, 'SOCIAL_CREDENTIAL_INVALID', 'Google sign-in could not be verified.');
        }
        return [
            'subject' => (string) $json['sub'],
            'email' => strtolower((string) $json['email']),
            'name' => trim((string) ($json['name'] ?? '')),
        ];
    }

    private function verifyFacebookIdentity(string $token, string $appId, string $appSecret): array
    {
        if ($appId === '' || $appSecret === '') {
            throw new ApiException(503, 'SOCIAL_PROVIDER_NOT_CONFIGURED', 'Facebook sign-in is not configured.');
        }
        $debug = $this->providerJson('https://graph.facebook.com/debug_token?' . http_build_query([
            'input_token' => $token,
            'access_token' => $appId . '|' . $appSecret,
        ]));
        $data = is_array($debug['data'] ?? null) ? $debug['data'] : [];
        if (empty($data['is_valid']) || !hash_equals($appId, (string) ($data['app_id'] ?? '')) || empty($data['user_id'])) {
            throw new ApiException(401, 'SOCIAL_CREDENTIAL_INVALID', 'Facebook sign-in could not be verified.');
        }
        $profile = $this->providerJson('https://graph.facebook.com/me?' . http_build_query([
            'fields' => 'id,name,email',
            'access_token' => $token,
        ]));
        if (!hash_equals((string) $data['user_id'], (string) ($profile['id'] ?? '')) ||
            !filter_var((string) ($profile['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(401, 'SOCIAL_EMAIL_REQUIRED', 'A verified Facebook email address is required.');
        }
        return [
            'subject' => (string) $profile['id'],
            'email' => strtolower((string) $profile['email']),
            'name' => trim((string) ($profile['name'] ?? '')),
        ];
    }

    private function socialUser(string $provider, string $subject, string $email, string $name): int
    {
        global $wo;

        $bound = $this->database->one(
            'SELECT `user_id` FROM `Ramza_MobileSocialIdentities` WHERE `provider` = ? AND `provider_subject` = ? LIMIT 1',
            'ss',
            [$provider, $subject]
        );
        if ($bound !== null) {
            return (int) $bound['user_id'];
        }
        $userId = (int) \Wo_UserIdFromEmail($email);
        if ($userId <= 0) {
            if ((int) ($wo['config']['user_registration'] ?? 0) !== 1) {
                throw new ApiException(403, 'REGISTRATION_DISABLED', 'Registration is currently unavailable.');
            }
            $parts = preg_split('/\s+/u', trim($name), 2) ?: [];
            $firstName = trim((string) ($parts[0] ?? 'Ramza'));
            $lastName = trim((string) ($parts[1] ?? 'User'));
            $username = $this->generatedUsername($firstName, $lastName);
            if (!\Wo_RegisterUser([
                'email' => \Wo_Secure($email, 0),
                'username' => \Wo_Secure($username, 0),
                'password' => bin2hex(random_bytes(32)),
                'email_code' => '',
                'first_name' => \Wo_Secure($firstName),
                'last_name' => \Wo_Secure($lastName),
                'src' => \Wo_Secure(ucfirst($provider)),
                'lastseen' => time(),
                'social_login' => '1',
                'active' => '1',
            ])) {
                throw new ApiException(500, 'REGISTRATION_FAILED', 'The social account could not be created.');
            }
            $userId = (int) \Wo_UserIdFromEmail($email);
            $this->applyRegistrationDefaults($userId);
        }
        $now = time();
        $this->database->execute(
            'INSERT IGNORE INTO `Ramza_MobileSocialIdentities`
             (`provider`,`provider_subject`,`user_id`,`verified_email`,`created_at`,`updated_at`)
             VALUES (?,?,?,?,?,?)',
            'ssisii',
            [$provider, $subject, $userId, $email, $now, $now]
        );
        $bound = $this->database->one(
            'SELECT `user_id` FROM `Ramza_MobileSocialIdentities` WHERE `provider` = ? AND `provider_subject` = ? LIMIT 1',
            'ss',
            [$provider, $subject]
        );
        return (int) ($bound['user_id'] ?? 0);
    }

    private function providerJson(string $url): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ApiException(503, 'SOCIAL_PROVIDER_UNAVAILABLE', 'The social provider is unavailable.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Ramza-Mobile-API/1.0',
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new ApiException(503, 'SOCIAL_PROVIDER_UNAVAILABLE', 'The social provider is unavailable.');
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ApiException(503, 'SOCIAL_PROVIDER_UNAVAILABLE', 'The social provider returned an invalid response.');
        }
        return $decoded;
    }

    private function generatedUsername(string $firstName, string $lastName): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $firstName . '_' . $lastName) ?? 'user');
        $base = substr($base, 0, 22);
        if (strlen($base) < 3) {
            $base = 'user';
        }
        do {
            $candidate = $base . '_' . random_int(10000, 999999);
        } while (\Wo_UserExists($candidate));
        return substr($candidate, 0, 32);
    }

    private function applyRegistrationDefaults(int $userId): void
    {
        global $wo;

        if (!empty($wo['config']['auto_friend_users'])) {
            \Wo_AutoFollow($userId);
        }
        if (!empty($wo['config']['auto_page_like'])) {
            \Wo_AutoPageLike($userId);
        }
        if (!empty($wo['config']['auto_group_join'])) {
            \Wo_AutoGroupJoin($userId);
        }
    }

    private function validateInstallation(string $installationId, string $platform): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{16,128}$/', $installationId) !== 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The installation identifier is invalid.', 'installation_id');
        }
        if (!in_array($platform, ['android', 'ios'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The platform is invalid.', 'platform');
        }
    }
}
