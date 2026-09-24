<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class TokenService
{
    private const ACCESS_TTL = 900;
    // Remembered mobile sessions use rotating, revocable refresh tokens. Keep
    // them available for a year and slide the window on every successful
    // rotation; access tokens remain short-lived.
    private const REFRESH_TTL = 31_536_000;

    public function __construct(
        private readonly Database $database,
        private readonly Security $security,
        private readonly AuditLogger $audit
    ) {
    }

    public function issue(
        int $userId,
        int $clientId,
        string $installationId,
        string $platform
    ): array {
        $this->validateInstallation($installationId, $platform);
        $now = time();
        $access = $this->security->opaqueToken('rma_');
        $refresh = $this->security->opaqueToken('rmr_');
        $sessionId = $this->security->uuid();
        $familyId = $this->security->uuid();
        $accessExpires = $now + self::ACCESS_TTL;
        $refreshExpires = $now + self::REFRESH_TTL;

        $this->database->execute(
            'INSERT INTO `Ramza_MobileSessions`
             (`session_id`,`family_id`,`user_id`,`client_id`,`installation_id`,`platform`,`access_token_hash`,`refresh_token_hash`,
              `access_expires_at`,`refresh_expires_at`,`last_seen_at`,`ip_hash`,`user_agent_hash`,`created_at`,`updated_at`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'ssiissssiiissii',
            [
                $sessionId,
                $familyId,
                $userId,
                $clientId,
                $installationId,
                $platform,
                $this->security->hash($access),
                $this->security->hash($refresh),
                $accessExpires,
                $refreshExpires,
                $now,
                $this->security->hash(RequestContext::clientIp()),
                $this->security->hash(RequestContext::userAgent()),
                $now,
                $now,
            ]
        );
        $this->audit->write('auth.login', 'success', $userId, $clientId, $installationId);

        return $this->tokenPayload($sessionId, $access, $refresh, $accessExpires, $refreshExpires);
    }

    public function authenticate(string $accessToken, string $publicClientId): array
    {
        $row = $this->database->one(
            'SELECT s.`session_id`,s.`family_id`,s.`user_id`,s.`client_id`,s.`installation_id`,s.`platform`,
                    s.`access_expires_at`,s.`refresh_expires_at`,c.`public_client_id`
             FROM `Ramza_MobileSessions` s
             INNER JOIN `Ramza_MobileApiClients` c ON c.`id` = s.`client_id`
             WHERE s.`access_token_hash` = ? AND c.`public_client_id` = ? AND c.`enabled` = 1
               AND s.`revoked_at` IS NULL LIMIT 1',
            'ss',
            [$this->security->hash($accessToken), $publicClientId]
        );
        if ($row === null || (int) $row['access_expires_at'] <= time()) {
            throw new ApiException(401, 'ACCESS_TOKEN_INVALID', 'The access token is invalid or expired.');
        }
        $this->database->execute(
            'UPDATE `Ramza_MobileSessions` SET `last_seen_at` = ?, `updated_at` = ? WHERE `session_id` = ?',
            'iis',
            [time(), time(), $row['session_id']]
        );
        return $row;
    }

    public function rotate(
        string $refreshToken,
        string $publicClientId,
        string $installationId
    ): array {
        $hash = $this->security->hash($refreshToken);
        $row = $this->database->one(
            'SELECT s.*,c.`public_client_id`,c.`enabled` AS `client_enabled`
             FROM `Ramza_MobileSessions` s
             INNER JOIN `Ramza_MobileApiClients` c ON c.`id` = s.`client_id`
             WHERE s.`refresh_token_hash` = ? AND c.`public_client_id` = ? LIMIT 1',
            'ss',
            [$hash, $publicClientId]
        );

        if ($row === null) {
            $reuse = $this->database->one(
                'SELECT s.`session_id`,s.`family_id`,s.`user_id`,s.`client_id`,s.`installation_id`
                 FROM `Ramza_MobileSessions` s
                 INNER JOIN `Ramza_MobileApiClients` c ON c.`id` = s.`client_id`
                 WHERE s.`previous_refresh_hash` = ? AND c.`public_client_id` = ? LIMIT 1',
                'ss',
                [$hash, $publicClientId]
            );
            if ($reuse !== null) {
                $this->revokeFamily((string) $reuse['family_id'], 'refresh_reuse');
                $this->audit->write(
                    'auth.refresh_reuse',
                    'blocked',
                    (int) $reuse['user_id'],
                    (int) $reuse['client_id'],
                    (string) $reuse['installation_id']
                );
                throw new ApiException(401, 'REFRESH_TOKEN_REUSE', 'The session was revoked. Sign in again.');
            }
            throw new ApiException(401, 'REFRESH_TOKEN_INVALID', 'The refresh token is invalid or expired.');
        }

        if ((int) $row['client_enabled'] !== 1 ||
            $row['revoked_at'] !== null ||
            (int) $row['refresh_expires_at'] <= time() ||
            !hash_equals((string) $row['installation_id'], $installationId)) {
            throw new ApiException(401, 'REFRESH_TOKEN_INVALID', 'The refresh token is invalid or expired.');
        }

        $now = time();
        $access = $this->security->opaqueToken('rma_');
        $refresh = $this->security->opaqueToken('rmr_');
        $accessExpires = $now + self::ACCESS_TTL;
        $refreshExpires = $now + self::REFRESH_TTL;

        $this->database->execute(
            'UPDATE `Ramza_MobileSessions`
             SET `previous_refresh_hash` = `refresh_token_hash`, `refresh_token_hash` = ?, `access_token_hash` = ?,
                 `access_expires_at` = ?, `refresh_expires_at` = ?, `last_seen_at` = ?, `updated_at` = ?
             WHERE `session_id` = ? AND `refresh_token_hash` = ? AND `revoked_at` IS NULL',
            'ssiiiiss',
            [
                $this->security->hash($refresh),
                $this->security->hash($access),
                $accessExpires,
                $refreshExpires,
                $now,
                $now,
                $row['session_id'],
                $hash,
            ]
        );
        $this->audit->write(
            'auth.refresh',
            'success',
            (int) $row['user_id'],
            (int) $row['client_id'],
            $installationId
        );
        return $this->tokenPayload((string) $row['session_id'], $access, $refresh, $accessExpires, $refreshExpires);
    }

    public function revokeSession(string $sessionId, int $userId, string $reason = 'logout'): void
    {
        $this->database->execute(
            'UPDATE `Ramza_MobileSessions` SET `revoked_at` = ?, `revocation_reason` = ?, `updated_at` = ?
             WHERE `session_id` = ? AND `user_id` = ? AND `revoked_at` IS NULL',
            'isisi',
            [time(), $reason, time(), $sessionId, $userId]
        );
    }

    public function sessions(int $userId): array
    {
        return $this->database->all(
            'SELECT `session_id`,`installation_id`,`platform`,`last_seen_at`,`created_at`,`revoked_at`
             FROM `Ramza_MobileSessions` WHERE `user_id` = ? ORDER BY `last_seen_at` DESC LIMIT 100',
            'i',
            [$userId]
        );
    }

    private function revokeFamily(string $familyId, string $reason): void
    {
        $this->database->execute(
            'UPDATE `Ramza_MobileSessions` SET `revoked_at` = ?, `revocation_reason` = ?, `updated_at` = ?
             WHERE `family_id` = ? AND `revoked_at` IS NULL',
            'isis',
            [time(), $reason, time(), $familyId]
        );
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

    private function tokenPayload(
        string $sessionId,
        string $access,
        string $refresh,
        int $accessExpires,
        int $refreshExpires
    ): array {
        return [
            'token_type' => 'Bearer',
            'access_token' => $access,
            'access_expires_at' => $accessExpires,
            'refresh_token' => $refresh,
            'refresh_expires_at' => $refreshExpires,
            'session_id' => $sessionId,
        ];
    }
}
