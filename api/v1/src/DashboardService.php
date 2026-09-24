<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/**
 * Authenticated creator-dashboard aggregates.
 *
 * All figures are calculated from existing first-party tables. Unsupported
 * concepts are explicitly marked as such instead of being inferred.
 */
final class DashboardService
{
    private const RANGES = ['today', '7d', '14d', '28d', '90d', 'lifetime'];
    private const CONTENT_TYPES = ['all', 'text', 'photo', 'multi_photo', 'video', 'reel', 'live', 'link', 'poll'];
    private const METRICS = ['views', 'engagement', 'comments', 'shares'];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly Database $database,
        private readonly FeedService $feed
    ) {
    }

    public function overview(string $accessToken, string $clientId, string $range): array
    {
        $userId = $this->authenticate($accessToken, $clientId, 'mobile_dashboard_overview');
        $period = $this->period($range);
        return [
            'profile' => $this->profile($userId),
            'configuration' => $this->configuration(),
            'range' => $period,
            'metrics' => $this->metrics($userId, $period),
            'series' => $this->series($userId, $period),
            'breakdowns' => $this->breakdowns($userId, $period),
            'latest_content' => $this->contentRows($userId, $period, 'all', 'views', 0, 1)['items'],
            'community' => ['unanswered_comments' => $this->unansweredCount($userId)],
            'monetisation' => $this->monetisation($userId),
            'professional_status' => $this->professionalStatus($userId),
            'unsupported' => $this->unsupported(),
        ];
    }

    public function analytics(string $accessToken, string $clientId, string $range): array
    {
        $userId = $this->authenticate($accessToken, $clientId, 'mobile_dashboard_analytics');
        $period = $this->period($range);
        return [
            'range' => $period,
            'metrics' => $this->metrics($userId, $period),
            'series' => $this->series($userId, $period),
            'breakdowns' => $this->breakdowns($userId, $period),
            'top_content' => $this->contentRows($userId, $period, 'all', 'views', 0, 5)['items'],
            'unsupported' => $this->unsupported(),
        ];
    }

    public function content(
        string $accessToken,
        string $clientId,
        string $range,
        string $type,
        string $metric,
        int $afterId,
        int $limit
    ): array {
        $userId = $this->authenticate($accessToken, $clientId, 'mobile_dashboard_content');
        if (!in_array($type, self::CONTENT_TYPES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The content type is invalid.', 'type');
        }
        if (!in_array($metric, self::METRICS, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The content metric is invalid.', 'metric');
        }
        return $this->contentRows($userId, $this->period($range), $type, $metric, $afterId, max(1, min(30, $limit)));
    }

    public function comments(string $accessToken, string $clientId, int $afterId, int $limit): array
    {
        $userId = $this->authenticate($accessToken, $clientId, 'mobile_dashboard_comments');
        $limit = max(1, min(30, $limit));
        $sql = 'SELECT c.`id`,c.`post_id`,c.`user_id`,c.`text`,c.`time`
                FROM `' . T_COMMENTS . '` c
                INNER JOIN `' . T_POSTS . '` p ON p.`id` = c.`post_id` AND p.`user_id` = ?
                LEFT JOIN `' . T_COMMENTS_REPLIES . '` r ON r.`comment_id` = c.`id` AND r.`user_id` = ?
                WHERE c.`user_id` <> ? AND r.`id` IS NULL';
        $types = 'iii';
        $params = [$userId, $userId, $userId];
        if ($afterId > 0) {
            $sql .= ' AND c.`id` < ?';
            $types .= 'i';
            $params[] = $afterId;
        }
        $sql .= ' ORDER BY c.`id` DESC LIMIT ' . ($limit + 1);
        $rows = $this->database->all($sql, $types, $params);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = [];
        foreach ($rows as $row) {
            $author = \Wo_UserData((int)$row['user_id']);
            $items[] = [
                'id' => (int)$row['id'],
                'post_id' => (int)$row['post_id'],
                'text' => $this->plain((string)$row['text']),
                'created_at' => (int)$row['time'],
                'author' => [
                    'id' => (int)($author['user_id'] ?? 0),
                    'name' => $this->displayName($author),
                    'avatar' => $this->mediaUrl((string)($author['avatar'] ?? '')),
                    'verified' => (int)($author['verified'] ?? 0) === 1,
                ],
            ];
        }
        return [
            'items' => $items,
            'next_after' => $hasMore && !empty($items) ? $items[array_key_last($items)]['id'] : null,
        ];
    }

    public function status(string $accessToken, string $clientId): array
    {
        $userId = $this->authenticate($accessToken, $clientId, 'mobile_dashboard_status');
        return [
            'profile' => $this->profile($userId),
            'configuration' => $this->configuration(),
            'professional_status' => $this->professionalStatus($userId),
            'monetisation' => $this->monetisation($userId),
            'unsupported' => $this->unsupported(),
        ];
    }

    private function authenticate(string $accessToken, string $clientId, string $bucket): int
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $userId = (int)$session['user_id'];
        $this->rateLimiter->enforce($bucket, (string)$userId, 120, 60);
        $this->feed->bootstrapWebContext($userId);
        return $userId;
    }

    private function period(string $range): array
    {
        if (!in_array($range, self::RANGES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The date range is invalid.', 'range');
        }
        $now = time();
        $startToday = strtotime('today', $now);
        $days = match ($range) {
            'today' => 1,
            '7d' => 7,
            '14d' => 14,
            '28d' => 28,
            '90d' => 90,
            default => 0,
        };
        $start = $days === 0 ? 0 : $startToday - (($days - 1) * 86400);
        return [
            'key' => $range,
            'start' => $start,
            'end' => $now,
            'days' => $days,
            'supported' => self::RANGES,
        ];
    }

    private function metrics(int $userId, array $period): array
    {
        $start = (int)$period['start'];
        $end = (int)$period['end'];
        $postWhere = 'p.`user_id` = ? AND p.`active` = 1';
        $postParams = [$userId];
        $postTypes = 'i';
        if ($start > 0) {
            $postWhere .= ' AND p.`time` BETWEEN ? AND ?';
            $postTypes .= 'ii';
            $postParams[] = $start;
            $postParams[] = $end;
        }
        $engagement = $this->database->one(
            'SELECT COALESCE(SUM(
                (SELECT COUNT(*) FROM `' . T_LIKES . '` l WHERE l.`post_id` = p.`id`) +
                (SELECT COUNT(*) FROM `' . T_WONDERS . '` w WHERE w.`post_id` = p.`id`) +
                (SELECT COUNT(*) FROM `' . T_REACTIONS . '` r WHERE r.`post_id` = p.`id`) +
                (SELECT COUNT(*) FROM `' . T_COMMENTS . '` c WHERE c.`post_id` = p.`id`) +
                (SELECT COUNT(*) FROM `' . T_COMMENTS_REPLIES . '` cr INNER JOIN `' . T_COMMENTS . '` c2 ON c2.`id` = cr.`comment_id` WHERE c2.`post_id` = p.`id`) +
                (SELECT COUNT(*) FROM `' . T_POSTS . '` s WHERE s.`parent_id` = p.`id`)
            ),0) AS `value` FROM `' . T_POSTS . '` p WHERE ' . $postWhere,
            $postTypes,
            $postParams
        );
        $views = $this->database->one(
            'SELECT COALESCE(SUM(i.`impression_count`),0) AS `views`,COUNT(DISTINCT i.`user_id`) AS `viewers`
             FROM `Ramza_AlgorithmImpressions` i
             INNER JOIN `' . T_POSTS . '` p ON p.`id` = i.`post_id` AND p.`user_id` = ?
             WHERE (? = 0 OR i.`last_seen_at` BETWEEN ? AND ?)',
            'iiii',
            [$userId, $start, $start, $end]
        );
        $followers = $this->database->one(
            'SELECT COUNT(*) AS `value` FROM `' . T_FOLLOWERS . '`
             WHERE `following_id` = ? AND `active` = 1 AND (? = 0 OR `time` BETWEEN ? AND ?)',
            'iiii',
            [$userId, $start, $start, $end]
        );
        return [
            'views' => ['value' => (int)($views['views'] ?? 0), 'viewers' => (int)($views['viewers'] ?? 0), 'supported' => true, 'comparison' => null],
            'earnings' => ['value' => null, 'supported' => false, 'comparison' => null, 'reason' => 'Earnings are not attributed to individual content impressions.'],
            'engagement' => ['value' => (int)($engagement['value'] ?? 0), 'supported' => true, 'comparison' => null],
            'audience_growth' => ['value' => (int)($followers['value'] ?? 0), 'supported' => true, 'comparison' => null],
        ];
    }

    private function series(int $userId, array $period): array
    {
        $start = (int)$period['start'];
        $end = (int)$period['end'];
        $views = $this->database->all(
            'SELECT DATE(FROM_UNIXTIME(i.`last_seen_at`)) AS `day`,SUM(i.`impression_count`) AS `value`
             FROM `Ramza_AlgorithmImpressions` i INNER JOIN `' . T_POSTS . '` p ON p.`id`=i.`post_id`
             WHERE p.`user_id`=? AND (?=0 OR i.`last_seen_at` BETWEEN ? AND ?)
             GROUP BY DATE(FROM_UNIXTIME(i.`last_seen_at`)) ORDER BY `day`',
            'iiii',
            [$userId, $start, $start, $end]
        );
        $followers = $this->database->all(
            'SELECT DATE(FROM_UNIXTIME(`time`)) AS `day`,COUNT(*) AS `value`
             FROM `' . T_FOLLOWERS . '` WHERE `following_id`=? AND `active`=1 AND (?=0 OR `time` BETWEEN ? AND ?)
             GROUP BY DATE(FROM_UNIXTIME(`time`)) ORDER BY `day`',
            'iiii',
            [$userId, $start, $start, $end]
        );
        return [
            'views' => $this->points($views),
            'audience_growth' => $this->points($followers),
            'engagement' => [],
            'earnings' => [],
        ];
    }

    private function breakdowns(int $userId, array $period): array
    {
        $start = (int)$period['start'];
        $end = (int)$period['end'];
        $sources = $this->database->all(
            'SELECT i.`surface` AS `label`,SUM(i.`impression_count`) AS `value`
             FROM `Ramza_AlgorithmImpressions` i INNER JOIN `' . T_POSTS . '` p ON p.`id`=i.`post_id`
             WHERE p.`user_id`=? AND (?=0 OR i.`last_seen_at` BETWEEN ? AND ?)
             GROUP BY i.`surface` ORDER BY `value` DESC',
            'iiii',
            [$userId, $start, $start, $end]
        );
        $media = $this->database->all(
            'SELECT CASE
                WHEN p.`is_reel`=1 THEN \'reel\'
                WHEN p.`postPhoto`<>\'\' THEN IF(p.`multi_image`=1,\'multi_photo\',\'photo\')
                WHEN p.`postFile`<>\'\' THEN \'video\'
                WHEN p.`postLink`<>\'\' THEN \'link\'
                ELSE \'text\' END AS `label`,SUM(i.`impression_count`) AS `value`
             FROM `Ramza_AlgorithmImpressions` i INNER JOIN `' . T_POSTS . '` p ON p.`id`=i.`post_id`
             WHERE p.`user_id`=? AND (?=0 OR i.`last_seen_at` BETWEEN ? AND ?)
             GROUP BY `label` ORDER BY `value` DESC',
            'iiii',
            [$userId, $start, $start, $end]
        );
        return ['sources' => $this->labeledValues($sources), 'media' => $this->labeledValues($media)];
    }

    private function contentRows(int $userId, array $period, string $type, string $metric, int $afterId, int $limit): array
    {
        $where = ['p.`user_id` = ?', 'p.`active` = 1', 'p.`post_id` = 0'];
        $types = 'i';
        $params = [$userId];
        if ((int)$period['start'] > 0) {
            $where[] = 'p.`time` BETWEEN ? AND ?';
            $types .= 'ii';
            $params[] = (int)$period['start'];
            $params[] = (int)$period['end'];
        }
        if ($afterId > 0) {
            $where[] = 'p.`id` < ?';
            $types .= 'i';
            $params[] = $afterId;
        }
        if ($type !== 'all') {
            $where[] = $this->typeSql($type);
        }
        $rows = $this->database->all(
            'SELECT p.`id`,
                (SELECT COALESCE(SUM(i.`impression_count`),0) FROM `Ramza_AlgorithmImpressions` i WHERE i.`post_id`=p.`id`) AS `dashboard_views`,
                (SELECT COUNT(*) FROM `' . T_COMMENTS . '` c WHERE c.`post_id`=p.`id`) AS `dashboard_comments`,
                (SELECT COUNT(*) FROM `' . T_POSTS . '` s WHERE s.`parent_id`=p.`id`) AS `dashboard_shares`,
                ((SELECT COUNT(*) FROM `' . T_LIKES . '` l WHERE l.`post_id`=p.`id`) +
                 (SELECT COUNT(*) FROM `' . T_WONDERS . '` w WHERE w.`post_id`=p.`id`) +
                 (SELECT COUNT(*) FROM `' . T_REACTIONS . '` r WHERE r.`post_id`=p.`id`) +
                 (SELECT COUNT(*) FROM `' . T_COMMENTS . '` c2 WHERE c2.`post_id`=p.`id`)) AS `dashboard_engagement`
             FROM `' . T_POSTS . '` p WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.`id` DESC LIMIT ' . ($limit + 1),
            $types,
            $params
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = [];
        foreach ($rows as $row) {
            $raw = \Wo_PostData((int)$row['id']);
            if (!is_array($raw)) {
                continue;
            }
            $post = $this->feed->present($raw, $userId);
            if (!is_array($post)) {
                continue;
            }
            $post['views'] = (int)$row['dashboard_views'];
            $post['dashboard_metrics'] = [
                'views' => (int)$row['dashboard_views'],
                'engagement' => (int)$row['dashboard_engagement'],
                'comments' => (int)$row['dashboard_comments'],
                'shares' => (int)$row['dashboard_shares'],
                'earnings' => null,
                'net_audience' => null,
            ];
            $post['content_type'] = $this->contentType($raw);
            $post['selected_metric'] = ['key' => $metric, 'value' => (int)$row['dashboard_' . $metric]];
            $items[] = $post;
        }
        return [
            'items' => $items,
            'next_after' => $hasMore && !empty($items) ? $items[array_key_last($items)]['id'] : null,
            'filters' => ['ranges' => self::RANGES, 'types' => self::CONTENT_TYPES, 'metrics' => self::METRICS],
        ];
    }

    private function profile(int $userId): array
    {
        $user = \Wo_UserData($userId);
        return [
            'id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'name' => $this->displayName($user),
            'avatar' => $this->mediaUrl((string)($user['avatar'] ?? '')),
            'verified' => (int)($user['verified'] ?? 0) === 1,
            'is_pro' => (int)($user['is_pro'] ?? 0) === 1,
            'pro_type' => (int)($user['pro_type'] ?? 0),
        ];
    }

    private function configuration(): array
    {
        global $wo;
        $config = (array)($wo['config'] ?? []);
        return [
            'app_name' => (string)($config['siteName'] ?? ''),
            'currency' => SystemCurrency::code(),
            'currency_symbol' => SystemCurrency::symbol(),
            'relationship_mode' => (int)($config['connectivitySystem'] ?? 0) === 1 ? 'friends' : 'followers',
            'modules' => [
                'groups' => (int)($config['groups'] ?? 0) === 1,
                'monetisation' => (int)($config['monetization'] ?? 0) === 1,
                'subscriptions' => (int)($config['user_monetization'] ?? 0) === 1,
                'advertising' => (int)($config['user_ads'] ?? 0) === 1,
                'boosting' => (int)($config['post_boost'] ?? 0) === 1,
            ],
        ];
    }

    private function monetisation(int $userId): array
    {
        global $wo;
        $user = \Wo_UserData($userId);
        $config = (array)($wo['config'] ?? []);
        return [
            'currency' => SystemCurrency::code(),
            'currency_symbol' => SystemCurrency::symbol(),
            'balance' => (float)($user['balance'] ?? 0),
            'wallet' => (float)($user['wallet'] ?? 0),
            'eligible' => (int)($user['have_monetization'] ?? 0) === 1,
            'features' => [
                ['key' => 'content_monetisation', 'enabled' => (int)($config['monetization'] ?? 0) === 1],
                ['key' => 'subscriptions', 'enabled' => (int)($config['user_monetization'] ?? 0) === 1],
                ['key' => 'advertising', 'enabled' => (int)($config['user_ads'] ?? 0) === 1],
                ['key' => 'boosting', 'enabled' => (int)($config['post_boost'] ?? 0) === 1],
            ],
        ];
    }

    private function professionalStatus(int $userId): array
    {
        $user = \Wo_UserData($userId);
        $profileFields = ['avatar', 'cover', 'about', 'birthday', 'country_id'];
        $completed = 0;
        foreach ($profileFields as $field) {
            $value = trim((string)($user[$field] ?? ''));
            if ($value !== '' && $value !== '0' && $value !== '0000-00-00') {
                $completed++;
            }
        }
        return [
            'weekly_progress' => ['supported' => false, 'value' => null, 'reason' => 'No weekly creator goal is configured.'],
            'profile_completion' => (int)round(($completed / count($profileFields)) * 100),
            'verified' => (int)($user['verified'] ?? 0) === 1,
            'subscribed' => (int)($user['is_pro'] ?? 0) === 1,
            'recommendable' => (int)($user['active'] ?? 0) === 1 && (int)($user['banned'] ?? 0) === 0,
            'support_tickets' => ['supported' => false, 'value' => null],
        ];
    }

    private function unansweredCount(int $userId): int
    {
        $row = $this->database->one(
            'SELECT COUNT(*) AS `value` FROM `' . T_COMMENTS . '` c
             INNER JOIN `' . T_POSTS . '` p ON p.`id`=c.`post_id` AND p.`user_id`=?
             LEFT JOIN `' . T_COMMENTS_REPLIES . '` r ON r.`comment_id`=c.`id` AND r.`user_id`=?
             WHERE c.`user_id`<>? AND r.`id` IS NULL',
            'iii',
            [$userId, $userId, $userId]
        );
        return (int)($row['value'] ?? 0);
    }

    private function unsupported(): array
    {
        return [
            'earnings_attribution', 'audience_demographics', 'external_traffic',
            'trending_topics', 'moderation_rules', 'moderation_activity', 'support_tickets',
        ];
    }

    private function typeSql(string $type): string
    {
        return match ($type) {
            'photo' => 'p.`postPhoto` <> \'\' AND p.`multi_image` = 0',
            'multi_photo' => 'p.`multi_image` = 1',
            'video' => 'p.`postFile` <> \'\' AND p.`is_reel` = 0 AND p.`postType` <> \'live\'',
            'reel' => 'p.`is_reel` = 1',
            'live' => 'p.`postType` = \'live\'',
            'link' => 'p.`postLink` <> \'\'',
            'poll' => 'p.`poll_id` > 0',
            default => 'p.`postPhoto` = \'\' AND p.`postFile` = \'\' AND p.`postLink` = \'\' AND p.`poll_id` = 0',
        };
    }

    private function contentType(array $post): string
    {
        if ((int)($post['is_reel'] ?? 0) === 1) return 'reel';
        if ((string)($post['postType'] ?? '') === 'live') return 'live';
        if ((int)($post['multi_image'] ?? 0) === 1) return 'multi_photo';
        if (trim((string)($post['postPhoto'] ?? '')) !== '') return 'photo';
        if (trim((string)($post['postFile'] ?? '')) !== '') return 'video';
        if (trim((string)($post['postLink'] ?? '')) !== '') return 'link';
        if ((int)($post['poll_id'] ?? 0) > 0) return 'poll';
        return 'text';
    }

    private function points(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'date' => (string)$row['day'], 'value' => (int)$row['value'],
        ], $rows);
    }

    private function labeledValues(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'label' => (string)$row['label'], 'value' => (int)$row['value'],
        ], $rows);
    }

    private function displayName(array $user): string
    {
        $name = trim((string)($user['name'] ?? ''));
        if ($name !== '') return $this->plain($name);
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        return $name !== '' ? $this->plain($name) : (string)($user['username'] ?? '');
    }

    private function plain(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function mediaUrl(string $value): string
    {
        if ($value === '') return '';
        return function_exists('Wo_GetMedia') ? (string)\Wo_GetMedia($value) : $value;
    }
}
