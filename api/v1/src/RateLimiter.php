<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class RateLimiter
{
    public function __construct(
        private readonly Database $database,
        private readonly Security $security
    ) {
    }

    public function enforce(string $scope, string $bucket, int $limit, int $windowSeconds): void
    {
        $now = time();
        $windowFloor = $now - $windowSeconds;
        $bucketHash = $this->security->hash(strtolower(trim($bucket)));
        $expiresAt = $now + ($windowSeconds * 2);

        $this->database->execute(
            'INSERT INTO `Ramza_MobileRateLimits`
             (`scope`,`bucket_hash`,`window_started_at`,`hits`,`expires_at`)
             VALUES (?,?,?,1,?)
             ON DUPLICATE KEY UPDATE
               `hits` = IF(`window_started_at` < ?, 1, `hits` + 1),
               `window_started_at` = IF(`window_started_at` < ?, VALUES(`window_started_at`), `window_started_at`),
               `expires_at` = VALUES(`expires_at`)',
            'ssiiii',
            [$scope, $bucketHash, $now, $expiresAt, $windowFloor, $windowFloor]
        );

        $row = $this->database->one(
            'SELECT `hits`,`window_started_at` FROM `Ramza_MobileRateLimits` WHERE `scope` = ? AND `bucket_hash` = ?',
            'ss',
            [$scope, $bucketHash]
        );
        if ($row !== null && (int) $row['hits'] > $limit) {
            $retryAfter = max(1, ((int) $row['window_started_at'] + $windowSeconds) - $now);
            header('Retry-After: ' . $retryAfter);
            throw new ApiException(429, 'RATE_LIMITED', 'Too many requests. Try again later.');
        }
    }
}
