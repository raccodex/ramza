<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class AuthChallengeService
{
    private const TTL = 600;
    private const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly Database $database,
        private readonly Security $security
    ) {
    }

    public function issue(
        string $purpose,
        int $userId,
        int $clientId,
        string $installationId,
        bool $generateCode = true
    ): array {
        $this->assertPurpose($purpose);
        $now = time();
        $id = $this->security->uuid();
        $code = $generateCode ? (string) random_int(100000, 999999) : '';
        $this->database->execute(
            'UPDATE `Ramza_MobileAuthChallenges`
             SET `consumed_at` = ?, `updated_at` = ?
             WHERE `purpose` = ? AND `user_id` = ? AND `client_id` = ?
               AND `installation_id` = ? AND `consumed_at` IS NULL',
            'iisiis',
            [$now, $now, $purpose, $userId, $clientId, $installationId]
        );
        $this->database->execute(
            'INSERT INTO `Ramza_MobileAuthChallenges`
             (`challenge_id`,`purpose`,`user_id`,`client_id`,`installation_id`,`secret_hash`,
              `attempts`,`max_attempts`,`expires_at`,`consumed_at`,`created_at`,`updated_at`)
             VALUES (?,?,?,?,?,?,0,?,?,NULL,?,?)',
            'ssiissiiii',
            [
                $id,
                $purpose,
                $userId,
                $clientId,
                $installationId,
                $code === '' ? '' : $this->secretHash($purpose, $code),
                self::MAX_ATTEMPTS,
                $now + self::TTL,
                $now,
                $now,
            ]
        );
        return [
            'challenge_id' => $id,
            'purpose' => $purpose,
            'code' => $code,
            'expires_at' => $now + self::TTL,
        ];
    }

    public function verifyCode(
        string $challengeId,
        string $purpose,
        int $clientId,
        string $installationId,
        string $code
    ): array {
        $row = $this->active($challengeId, [$purpose], $clientId, $installationId);
        if ($row['secret_hash'] === '' ||
            !hash_equals((string) $row['secret_hash'], $this->secretHash($purpose, $code))) {
            $this->reject($challengeId);
            throw new ApiException(422, 'VERIFICATION_CODE_INVALID', 'The verification code is invalid or expired.', 'code');
        }
        $this->consume($challengeId);
        return $row;
    }

    public function active(
        string $challengeId,
        array $purposes,
        int $clientId,
        string $installationId
    ): array {
        if (preg_match('/^[0-9a-f-]{36}$/', $challengeId) !== 1 || $installationId === '') {
            throw new ApiException(422, 'CHALLENGE_INVALID', 'The authentication challenge is invalid or expired.');
        }
        $row = $this->database->one(
            'SELECT * FROM `Ramza_MobileAuthChallenges`
             WHERE `challenge_id` = ? AND `client_id` = ? AND `installation_id` = ? LIMIT 1',
            'sis',
            [$challengeId, $clientId, $installationId]
        );
        if ($row === null ||
            !in_array((string) $row['purpose'], $purposes, true) ||
            $row['consumed_at'] !== null ||
            (int) $row['expires_at'] <= time() ||
            (int) $row['attempts'] >= (int) $row['max_attempts']) {
            throw new ApiException(422, 'CHALLENGE_INVALID', 'The authentication challenge is invalid or expired.');
        }
        return $row;
    }

    public function reject(string $challengeId): void
    {
        $this->database->execute(
            'UPDATE `Ramza_MobileAuthChallenges`
             SET `attempts` = `attempts` + 1,
                 `consumed_at` = IF(`attempts` + 1 >= `max_attempts`, ?, `consumed_at`),
                 `updated_at` = ? WHERE `challenge_id` = ?',
            'iis',
            [time(), time(), $challengeId]
        );
    }

    public function consume(string $challengeId): void
    {
        $this->database->execute(
            'UPDATE `Ramza_MobileAuthChallenges` SET `consumed_at` = ?, `updated_at` = ?
             WHERE `challenge_id` = ? AND `consumed_at` IS NULL',
            'iis',
            [time(), time(), $challengeId]
        );
    }

    private function secretHash(string $purpose, string $code): string
    {
        return $this->security->hash($purpose . ':' . $code);
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, ['activation', 'password_reset', 'two_factor', 'unusual_login'], true)) {
            throw new \InvalidArgumentException('Unsupported authentication challenge purpose.');
        }
    }
}
