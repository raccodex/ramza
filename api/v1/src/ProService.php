<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/**
 * Xamarin-compatible Go Pro plans and wallet checkout.
 *
 * The legacy app treats another purchase as an immediate renewal/replacement:
 * the selected package becomes active and pro_time starts again at checkout.
 */
final class ProService
{
    private const COLORS = [
        1 => '#4c7737',
        2 => '#f9b340',
        3 => '#e13c4c',
        4 => '#3f4bb8',
    ];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly Database $database
    ) {
    }

    private function viewer(string $accessToken, string $clientId): array
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $userId = (int)($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_pro', (string)$userId, 60, 60);
        global $wo;
        $user = \Wo_UserData($userId);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = $userId;
        return [$userId, $user];
    }

    private function package(int $id): ?array
    {
        global $wo;
        $package = $wo['pro_packages'][$id] ?? null;
        return is_array($package) ? $package : null;
    }

    private function active(array $user, bool $expireStale = true): array
    {
        $isPro = (int)($user['is_pro'] ?? 0) === 1;
        $type = (int)($user['pro_type'] ?? 0);
        $started = (int)($user['pro_time'] ?? 0);
        $package = $this->package($type);
        $duration = (int)($package['ex_time'] ?? 0);
        $expiresAt = $duration > 0 && $started > 0 ? $started + $duration : 0;

        if ($isPro && ($package === null || ($expiresAt > 0 && $expiresAt <= time()))) {
            $isPro = false;
            $type = 0;
            $started = 0;
            $expiresAt = 0;
            if ($expireStale) {
                \Wo_UpdateUserData((int)$user['user_id'], [
                    'is_pro' => 0,
                    'pro_type' => 0,
                    'pro_time' => 0,
                    'pro_' => 0,
                ]);
                if (function_exists('cache')) {
                    \cache((int)$user['user_id'], 'users', 'delete');
                }
            }
        }

        return [
            'is_pro' => $isPro,
            'plan_id' => $isPro ? $type : 0,
            'plan_name' => $isPro ? (string)($package['name'] ?? '') : '',
            'started_at' => $isPro ? $started : 0,
            'expires_at' => $isPro ? $expiresAt : 0,
            'lifetime' => $isPro && $duration === 0,
            // An active Pro member receives no AdMob or sponsored feed ads.
            'ads_enabled' => !$isPro,
        ];
    }

    private function feature(array $package, string $key): int
    {
        return max(0, (int)($package[$key] ?? 0));
    }

    private function period(array $package): string
    {
        $unit = strtolower((string)($package['time'] ?? ''));
        $count = max(1, (int)($package['time_count'] ?? 1));
        if ($unit === 'unlimited' || (int)($package['ex_time'] ?? 0) === 0) {
            return 'Lifetime';
        }
        if ($count === 1) {
            return match ($unit) {
                'day' => 'Per Day',
                'week' => 'Per Week',
                'month' => 'Per Month',
                'year' => 'Per Year',
                default => 'Per ' . ucfirst($unit),
            };
        }
        return 'Every ' . $count . ' ' . ucfirst($unit) . ($count === 1 ? '' : 's');
    }

    private function presentPackage(array $package): array
    {
        $id = (int)($package['id'] ?? 0);
        return [
            'id' => $id,
            'name' => html_entity_decode((string)($package['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'price' => (float)($package['price'] ?? 0),
            'period' => $this->period($package),
            'duration_seconds' => max(0, (int)($package['ex_time'] ?? 0)),
            'color' => (string)($package['color'] ?? (self::COLORS[$id] ?? '#4c7737')),
            'icon_asset' => 'ic_plan_' . $id,
            'features' => [
                'featured_member' => $this->feature($package, 'featured_member') === 1,
                'profile_visitors' => $this->feature($package, 'profile_visitors') === 1,
                'last_seen' => $this->feature($package, 'last_seen') === 1,
                'verified_badge' => $this->feature($package, 'verified_badge') === 1,
                'posts_promotion' => $this->feature($package, 'posts_promotion'),
                'pages_promotion' => $this->feature($package, 'pages_promotion'),
                'discount' => $this->feature($package, 'discount'),
            ],
        ];
    }

    public function configuration(string $accessToken, string $clientId): array
    {
        [, $user] = $this->viewer($accessToken, $clientId);
        $plans = [];
        global $wo;
        foreach ((array)($wo['pro_packages'] ?? []) as $package) {
            if (is_array($package) && (int)($package['status'] ?? 0) === 1) {
                $plans[] = $this->presentPackage($package);
            }
        }
        usort($plans, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
        $currency = SystemCurrency::code();
        return [
            'enabled' => (int)($wo['config']['pro'] ?? 0) === 1,
            'wallet' => (float)($user['wallet'] ?? 0),
            'currency' => $currency,
            'currency_symbol' => SystemCurrency::symbol($currency),
            'current' => $this->active($user),
            'plans' => $plans,
            'payment_methods' => ['wallet'],
        ];
    }

    public function purchase(string $accessToken, string $clientId, array $payload): array
    {
        [$userId, $user] = $this->viewer($accessToken, $clientId);
        global $wo;
        if ((int)($wo['config']['pro'] ?? 0) !== 1) {
            throw new ApiException(403, 'PRO_DISABLED', 'Pro membership is currently unavailable.');
        }
        $method = strtolower(trim((string)($payload['payment_method'] ?? 'wallet')));
        if ($method !== 'wallet') {
            throw new ApiException(422, 'PAYMENT_METHOD_UNAVAILABLE', 'Select wallet payment.', 'payment_method');
        }
        $planId = (int)($payload['plan_id'] ?? 0);
        $package = $this->package($planId);
        if ($package === null || (int)($package['status'] ?? 0) !== 1) {
            throw new ApiException(404, 'PRO_PLAN_NOT_FOUND', 'This Pro plan is unavailable.', 'plan_id');
        }
        $price = max(0, (float)($package['price'] ?? 0));
        $connection = $this->database->connection();
        $connection->begin_transaction();
        try {
            $row = $this->database->one(
                'SELECT `wallet`, `points` FROM `' . T_USERS . '` WHERE `user_id` = ? FOR UPDATE',
                'i',
                [$userId]
            );
            if ($row === null) {
                throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
            }
            $wallet = (float)($row['wallet'] ?? 0);
            if ($wallet < $price) {
                throw new ApiException(422, 'PRO_WALLET_INSUFFICIENT', 'Your wallet balance is too low for this plan.');
            }
            $points = (float)($row['points'] ?? 0);
            if ((int)($wo['config']['point_allow_withdrawal'] ?? 1) === 0) {
                $points = max(0, $points - ($price * (float)($wo['config']['dollar_to_point_cost'] ?? 0)));
            }
            $now = time();
            $verified = $this->feature($package, 'verified_badge') === 1;
            $sql = 'UPDATE `' . T_USERS . '` SET `wallet` = ?, `points` = ?, `is_pro` = 1, '
                . '`pro_time` = ?, `pro_` = 1, `pro_type` = ?'
                . ($verified ? ', `verified` = 1' : '')
                . ' WHERE `user_id` = ?';
            $this->database->execute($sql, 'ddiii', [$wallet - $price, $points, $now, $planId, $userId]);

            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollback();
            throw $error;
        }

        $wo['user']['is_pro'] = 1;
        $wo['user']['pro_type'] = $planId;
        $wo['user']['pro_time'] = $now;
        // The membership and wallet debit are the authoritative transaction.
        // Analytics/history tables differ between script releases, so a log
        // failure must never turn a completed purchase into a mobile API 500.
        $notes = 'Upgrade to Pro ' . (string)($package['name'] ?? ('Plan ' . $planId)) . ' : Wallet';
        try {
            $this->database->execute(
                'INSERT INTO `' . T_PAYMENT_TRANSACTIONS . '` (`userid`, `kind`, `amount`, `notes`) VALUES (?, \'PRO\', ?, ?)',
                'ids',
                [$userId, $price, $notes]
            );
        } catch (\Throwable $logError) {
            error_log('[mobile-pro] transaction history failed: ' . $logError->getMessage());
        }
        try {
            if (function_exists('Wo_CreatePayment')) {
                \Wo_CreatePayment($planId);
            }
        } catch (\Throwable $logError) {
            error_log('[mobile-pro] payment analytics failed: ' . $logError->getMessage());
        }
        try {
            if (function_exists('cache')) {
                \cache($userId, 'users', 'delete');
            }
        } catch (\Throwable $cacheError) {
            error_log('[mobile-pro] cache invalidation failed: ' . $cacheError->getMessage());
        }
        $updated = array_replace($user, [
            'wallet' => $wallet - $price,
            'is_pro' => 1,
            'pro_type' => $planId,
            'pro_time' => $now,
        ]);
        try {
            $fresh = \Wo_UserData($userId);
            if (is_array($fresh) && !empty($fresh['user_id'])) {
                $updated = $fresh;
            }
        } catch (\Throwable $reloadError) {
            error_log('[mobile-pro] purchased account reload failed: ' . $reloadError->getMessage());
        }
        return [
            'purchased' => true,
            'renewed' => (int)($user['is_pro'] ?? 0) === 1 && (int)($user['pro_type'] ?? 0) === $planId,
            'replaced_plan' => (int)($user['is_pro'] ?? 0) === 1 && (int)($user['pro_type'] ?? 0) !== $planId,
            'wallet' => (float)($updated['wallet'] ?? 0),
            'current' => $this->active(is_array($updated) ? $updated : []),
        ];
    }
}
