<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class FeedService
{
    private const CURSOR_TTL = 86_400;

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly Security $security
    ) {
    }

    public function feed(
        string $accessToken,
        string $publicClientId,
        ?string $cursor,
        int $limit,
        string $filter,
        string $sort = 'recent'
    ): array {
        $session = $this->tokens->authenticate($accessToken, $publicClientId);
        $userId = (int) $session['user_id'];
        $this->rateLimiter->enforce('mobile_feed', (string) $userId, 120, 60);

        $limit = max(1, min(20, $limit));
        $allowedFilters = ['all', 'text', 'photos', 'video', 'music', 'files', 'maps'];
        if (!in_array($filter, $allowedFilters, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The feed filter is invalid.', 'filter');
        }
        if (!in_array($sort, ['recent', 'popular', 'most_liked'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The feed sort is invalid.', 'sort');
        }
        // Xamarin's MostLikedAsync contract uses the special 72-hour
        // engagement cursor (lasttotal + dt + after_post_id). Popular Posts is
        // a separate activity/API contract and uses an offset cursor.
        $mostLikedMode = $sort === 'most_liked';
        $popularMode = $sort === 'popular';
        $popularCursor = $mostLikedMode
            ? $this->decodePopularCursor($cursor, $userId)
            : null;
        $afterPostId = $mostLikedMode
            ? (int) ($popularCursor['after_post_id'] ?? 0)
            : ($popularMode ? 0 : $this->decodeCursor($cursor, $userId, $filter));
        $popularOffset = $popularMode
            ? $this->decodeCursor($cursor, $userId, 'popular')
            : 0;
        $this->bootstrapWebContext($userId);

        $options = [
            'limit' => $limit,
            'publisher_id' => 0,
            'after_post_id' => $afterPostId,
            'placement' => 'multi_image_post',
            'anonymous' => true,
        ];
        if ($mostLikedMode) {
            $options['filter_by'] = 'most_liked';
            $options['dt'] = (int) ($popularCursor['dt'] ?? 0);
            $options['lasttotal'] = (int) ($popularCursor['last_total'] ?? 0);
        } elseif ($filter !== 'all') {
            $options['filter_by'] = $filter;
        }

        $rawPosts = $popularMode
            ? $this->popularPosts($userId, $popularOffset, $limit)
            : \Wo_GetPosts($options);
        if (!is_array($rawPosts)) {
            $rawPosts = [];
        }
        $items = [];
        foreach ($rawPosts as $rawPost) {
            if (!is_array($rawPost)) {
                continue;
            }
            $item = $this->post($rawPost, $userId);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        // The native Xamarin feed owns one PromotePost section. On the first
        // page it moves the first boosted post above regular content; a shared
        // post also qualifies when its original post is boosted.
        if ($cursor === null) {
            $items = $this->pinFirstPromotedPost($items);
        }

        // Display ordering must never change cursor ordering. Always derive the
        // cursor from the final raw database row, before promoted/ad insertion.
        $lastRaw = empty($rawPosts) ? [] : $rawPosts[array_key_last($rawPosts)];
        $lastId = is_array($lastRaw)
            ? (int) ($lastRaw['id'] ?? $lastRaw['post_id'] ?? 0)
            : 0;
        // Xamarin injects one targeted native advertisement into the regular
        // News feed. Keep it outside cursor calculation because ads are not
        // rows in Wo_Posts.
        if (
            $cursor === null
            && $filter === 'all'
            && !$mostLikedMode
            && !$popularMode
            && (int)($GLOBALS['wo']['user']['is_pro'] ?? 0) !== 1
        ) {
            $advertisement = \Wo_GetPostAds();
            // A single idempotent campaign is seeded for local mobile UI
            // verification. Local users intentionally have zero wallets, so
            // Wo_GetPostAds excludes every campaign; allow only this named
            // fixture on localhost without weakening production billing.
            global $wo, $db;
            $siteHost = strtolower((string)parse_url(
                (string)($wo['config']['site_url'] ?? ''),
                PHP_URL_HOST
            ));
            if (
                (!is_array($advertisement) || empty($advertisement['id']))
                && in_array($siteHost, ['localhost', '127.0.0.1'], true)
            ) {
                $fixture = $db
                    ->where('name', 'Ramza Mobile Advertisement Test')
                    ->where('status', 1)
                    ->getOne(T_USER_ADS);
                if (!empty($fixture->id)) {
                    $advertisement = (array)$fixture;
                    $advertisement['user_data'] = \Wo_UserData((int)$fixture->user_id);
                }
            }
            if (is_array($advertisement) && !empty($advertisement['id'])) {
                $adItem = $this->advertisementPost($advertisement, $userId);
                array_splice($items, min(2, count($items)), 0, [$adItem]);
                if (($advertisement['bidding'] ?? '') === 'views') {
                    \Wo_RegisterAdConversionView((int)$advertisement['id']);
                }
            }
        }
        return [
            'items' => $items,
            'next_cursor' => $mostLikedMode
                ? (
                    count($rawPosts) >= $limit
                    && $lastId > 0
                    && (int) ($lastRaw['LastTotal'] ?? 0) > 0
                    && (int) ($lastRaw['dt'] ?? 0) > 0
                    ? $this->encodePopularCursor(
                        $userId,
                        $lastId,
                        (int) $lastRaw['LastTotal'],
                        (int) $lastRaw['dt']
                    )
                    : null
                )
                : ($popularMode
                    ? (count($rawPosts) >= $limit
                        ? $this->encodeCursor($userId, $popularOffset + count($rawPosts), 'popular')
                        : null)
                : (
                    count($rawPosts) >= $limit && $lastId > 0
                        ? $this->encodeCursor($userId, $lastId, $filter)
                        : null
                )),
        ];
    }

    /**
     * Mirrors ApiPostAsync + FeedCombiner: pin exactly one promoted section,
     * rather than grouping every boosted post above the chronological feed.
     */
    private function pinFirstPromotedPost(array $items): array
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $shared = $item['shared_post'] ?? null;
            $isPromoted = !empty($item['boosted'])
                || (is_array($shared) && !empty($shared['boosted']));
            if (!$isPromoted) {
                continue;
            }
            if ($index > 0) {
                $promoted = $items[$index];
                array_splice($items, $index, 1);
                array_unshift($items, $promoted);
            }
            break;
        }
        return $items;
    }

    /**
     * Xamarin GetPopularPost(limit, offset): public posts ranked by sustained
     * engagement. Unlike MostLikedAsync this is not limited to the last 72
     * hours and it paginates by offset.
     */
    private function popularPosts(int $viewerId, int $offset, int $limit): array
    {
        global $sqlConnect;
        $offset = max(0, $offset);
        $limit = max(1, min(20, $limit));
        $reactionTable = defined('T_REACTIONS') ? T_REACTIONS : 'Wo_Reactions';
        $sql = "SELECT p.`id`,
                    ((SELECT COUNT(*) FROM " . T_COMMENTS . " c WHERE c.`post_id`=p.`id`) * 3
                    + (SELECT COUNT(*) FROM {$reactionTable} r WHERE r.`post_id`=p.`id`) * 2
                    + (SELECT COUNT(*) FROM " . T_POSTS . " s WHERE s.`parent_id`=p.`id`) * 4) AS popularity
                FROM " . T_POSTS . " p
                WHERE p.`active`=1
                  AND p.`postPrivacy`='0'
                  AND p.`recipient_id`=0
                  AND p.`group_id`=0
                ORDER BY popularity DESC, p.`id` DESC
                LIMIT {$offset},{$limit}";
        $query = @mysqli_query($sqlConnect, $sql);
        if (!$query) {
            // Older schemas do not expose every optional metric. Fall back to
            // the visibility-safe core feed instead of returning a 500.
            return \Wo_GetPosts([
                'limit' => $limit,
                'publisher_id' => 0,
                'after_post_id' => 0,
                'placement' => 'multi_image_post',
                'anonymous' => true,
                'disable_algorithm' => true,
            ]);
        }
        $posts = [];
        while ($row = mysqli_fetch_assoc($query)) {
            $post = \Wo_PostData((int)$row['id']);
            if (is_array($post) && !empty($post['id'])) {
                $posts[] = $post;
            }
        }
        return $posts;
    }

    public function recordAlgorithmEvents(
        string $accessToken,
        string $publicClientId,
        array $payload
    ): array {
        $session = $this->tokens->authenticate($accessToken, $publicClientId);
        $userId = (int)$session['user_id'];
        $this->rateLimiter->enforce('mobile_algorithm_events', (string)$userId, 240, 60);
        $this->bootstrapWebContext($userId);
        $events = isset($payload['events']) && is_array($payload['events'])
            ? $payload['events']
            : [];
        $sessionKey = isset($payload['session_key']) ? (string)$payload['session_key'] : '';
        if (!function_exists('Wo_RamzaAlgorithmRecordEvents')) {
            return ['accepted' => 0, 'ignored' => count($events), 'enabled' => false];
        }
        $result = \Wo_RamzaAlgorithmRecordEvents($userId, $events, $sessionKey);
        $result['enabled'] = \Wo_RamzaAlgorithmConfigSwitch('algorithm_system', '0');
        return $result;
    }

    public function algorithmConfiguration(
        string $accessToken,
        string $publicClientId
    ): array {
        $session = $this->tokens->authenticate($accessToken, $publicClientId);
        $userId = (int)$session['user_id'];
        $this->rateLimiter->enforce('mobile_algorithm_configuration', (string)$userId, 60, 60);
        $this->bootstrapWebContext($userId);

        $available = function_exists('Wo_RamzaAlgorithmAdvancedAvailable')
            && \Wo_RamzaAlgorithmAdvancedAvailable();
        $enabled = $available
            && \Wo_RamzaAlgorithmConfigSwitch('algorithm_system', '0')
            && \Wo_RamzaAlgorithmConfigSwitch('algorithm_track_behavior', '0');
        $types = [];
        if ($enabled) {
            $types = ['click', 'open_post', 'save', 'profile_visit', 'expand_text',
                'open_comments', 'hide', 'not_interested', 'mute', 'report', 'block'];
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_impressions', '1')) {
                $types[] = 'impression';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_dwell', '1')) {
                $types[] = 'dwell';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_quick_skips', '1')) {
                $types[] = 'quick_skip';
                $types[] = 'short_watch';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_likes', '1')) {
                $types[] = 'like';
                $types[] = 'reaction';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_comments', '1')) {
                $types[] = 'comment';
                $types[] = 'reply';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_shares', '1')) {
                $types[] = 'share';
                $types[] = 'repost';
                $types[] = 'send_post';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_follows', '1')) {
                $types[] = 'follow_after_view';
                $types[] = 'unfollow';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_search', '1')) {
                $types[] = 'search_topic';
                $types[] = 'hashtag_click';
                $types[] = 'topic_preference';
            }
            if (\Wo_RamzaAlgorithmConfigSwitch('algorithm_track_video_watch', '1')) {
                array_push($types, 'watch_25', 'watch_50', 'watch_75', 'watch_complete', 'rewatch', 'sound_on');
            }
        }
        return [
            'enabled' => $enabled,
            'event_types' => array_values(array_unique($types)),
            'batch_limit' => function_exists('Wo_RamzaAlgorithmConfigNumber')
                ? (int)\Wo_RamzaAlgorithmConfigNumber('algorithm_event_batch_limit', 40)
                : 0,
        ];
    }

    private function advertisementPost(array $advertisement, int $viewerId): array
    {
        $pageId = (int)($advertisement['page_id'] ?? 0);
        $publisher = [];
        if ($pageId > 0) {
            $page = \Wo_PageData($pageId);
            if (is_array($page)) {
                $publisher = [
                    'id' => (int)($page['page_id'] ?? 0),
                    'type' => 'page',
                    'username' => (string)($page['page_name'] ?? ''),
                    'name' => (string)($page['page_title'] ?? ''),
                    'avatar' => (string)($page['avatar'] ?? ''),
                    'verified' => !empty($page['verified']),
                ];
            }
        }
        if (empty($publisher)) {
            $user = is_array($advertisement['user_data'] ?? null)
                ? $advertisement['user_data']
                : \Wo_UserData((int)($advertisement['user_id'] ?? 0));
            $user = is_array($user) ? $user : [];
            $publisher = [
                'id' => (int)($user['user_id'] ?? 0),
                'type' => 'user',
                'username' => (string)($user['username'] ?? ''),
                'name' => trim((string)($user['name'] ?? (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')))),
                'avatar' => (string)($user['avatar'] ?? ''),
                'verified' => !empty($user['verified']),
            ];
        }
        $media = $this->mediaUrl((string)($advertisement['ad_media'] ?? ''));
        $path = strtolower((string)(parse_url($media, PHP_URL_PATH) ?? $media));
        return [
            'id' => 900000000000 + (int)$advertisement['id'],
            'text' => '',
            'created_at' => (int)($advertisement['posted'] ?? time()),
            'post_type' => 'ad',
            'publisher' => $publisher,
            'media' => [],
            'comments_count' => 0,
            'shares_count' => 0,
            'is_liked' => false,
            'is_saved' => false,
            'is_owner' => (int)($advertisement['user_id'] ?? 0) === $viewerId,
            'advertisement' => [
                'id' => (int)$advertisement['id'],
                'user_id' => (int)($advertisement['user_id'] ?? 0),
                'page_id' => $pageId,
                'name' => html_entity_decode((string)($advertisement['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'website' => (string)($advertisement['url'] ?? ''),
                'headline' => html_entity_decode((string)($advertisement['headline'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'description' => html_entity_decode(strip_tags((string)($advertisement['description'] ?? '')), ENT_QUOTES | ENT_HTML5),
                'location' => html_entity_decode((string)($advertisement['location'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'audience' => array_values(array_filter(explode(',', (string)($advertisement['audience'] ?? '')))),
                'gender' => (string)($advertisement['gender'] ?? 'all'),
                'bidding' => (string)($advertisement['bidding'] ?? 'clicks'),
                'placement' => (string)($advertisement['appears'] ?? 'post'),
                'posted' => (int)($advertisement['posted'] ?? 0),
                'start' => (string)($advertisement['start'] ?? ''),
                'end' => (string)($advertisement['end'] ?? ''),
                'budget' => (float)($advertisement['budget'] ?? 0),
                'spent' => (float)($advertisement['spent'] ?? 0),
                'clicks' => (int)($advertisement['clicks'] ?? 0),
                'views' => (int)($advertisement['views'] ?? 0),
                'status' => (int)($advertisement['status'] ?? 0),
                'media_url' => $media,
                'media_type' => preg_match('/\.(mp4|mov|m4v|webm|mpeg|avi)$/', $path) === 1 ? 'video' : 'image',
                'is_owner' => (int)($advertisement['user_id'] ?? 0) === $viewerId,
                'publisher' => $publisher,
            ],
        ];
    }

    /**
     * Xamarin MemoriesActivity: friendversaries and the signed-in member's
     * posts from the same day/month one year ago. This endpoint deliberately
     * has no cursor because the Xamarin activity disables load-more.
     */
    public function memories(string $accessToken, string $publicClientId): array
    {
        $session = $this->tokens->authenticate($accessToken, $publicClientId);
        $userId = (int)$session['user_id'];
        $this->rateLimiter->enforce('mobile_memories', (string)$userId, 60, 60);
        $this->bootstrapWebContext($userId);

        $rawFriends = \Wo_GetMemoriesFreinds($userId);
        if (!is_array($rawFriends)) {
            $rawFriends = [];
        }
        $friends = [];
        foreach ($rawFriends as $friend) {
            if (!is_array($friend)) {
                continue;
            }
            $friendId = (int)($friend['user_id'] ?? 0);
            if ($friendId <= 0) {
                continue;
            }
            $name = trim((string)($friend['name'] ?? ''));
            if ($name === '') {
                $name = trim(
                    (string)($friend['first_name'] ?? '')
                    . ' '
                    . (string)($friend['last_name'] ?? '')
                );
            }
            $friends[] = [
                'id' => $friendId,
                'username' => (string)($friend['username'] ?? ''),
                'name' => $name,
                'avatar' => $this->mediaUrl((string)($friend['avatar'] ?? '')),
                'time' => (int)($friend['time'] ?? 0),
            ];
        }

        $rawPosts = \Wo_GetMemoriesPosts($userId);
        if (!is_array($rawPosts)) {
            $rawPosts = [];
        }

        return [
            'friends' => $friends,
            'posts' => $this->presentMany($rawPosts, $userId),
        ];
    }

    /** Xamarin BoostedPostsFragment: the same native post shape, restricted to boosted posts. */
    public function boostedPosts(string $accessToken, string $publicClientId, int $limit = 20): array
    {
        $session = $this->tokens->authenticate($accessToken, $publicClientId);
        $userId = (int)$session['user_id'];
        $this->rateLimiter->enforce('mobile_boosted_posts', (string)$userId, 120, 60);
        $this->bootstrapWebContext($userId);
        global $sqlConnect;
        $limit = max(1, min(40, $limit));
        $stmt = $sqlConnect->prepare(
            "SELECT id FROM Wo_Posts WHERE boosted='1' "
            . 'AND (user_id=? OR page_id IN (SELECT page_id FROM Wo_Pages WHERE user_id=?)) '
            . 'ORDER BY id DESC LIMIT ?'
        );
        if ($stmt === false) return [];
        $stmt->bind_param('iii', $userId, $userId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $items = [];
        foreach ($rows as $row) {
            $raw = \Wo_PostData((int)$row['id']);
            if (is_array($raw)) {
                $presented = $this->post($raw, $userId);
                if ($presented !== null) $items[] = $presented;
            }
        }
        return $items;
    }

    /**
     * The reels feed: video posts flagged `is_reel = 1`, fetched via
     * Wo_GetPosts(['is_reel' => 'only']) (which also applies the algorithm's
     * reel ranking when the addon is enabled). Same post shape as feed().
     */
    public function reels(
        string $accessToken,
        string $publicClientId,
        ?string $cursor,
        int $limit
    ): array {
        $session = $this->tokens->authenticate($accessToken, $publicClientId);
        $userId = (int) $session['user_id'];
        $this->rateLimiter->enforce('mobile_feed', (string) $userId, 120, 60);

        $limit = max(1, min(20, $limit));
        $afterPostId = $this->decodeCursor($cursor, $userId, 'reels');
        $this->bootstrapWebContext($userId);

        $rawPosts = \Wo_GetPosts([
            'limit' => $limit,
            'publisher_id' => 0,
            'after_post_id' => $afterPostId,
            'is_reel' => 'only',
            'anonymous' => true,
        ]);
        if (!is_array($rawPosts)) {
            $rawPosts = [];
        }
        $items = [];
        foreach ($rawPosts as $rawPost) {
            if (is_array($rawPost)) {
                $item = $this->post($rawPost, $userId);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        $lastId = empty($items) ? 0 : (int) $items[array_key_last($items)]['id'];
        return [
            'items' => $items,
            'next_cursor' => count($items) >= $limit && $lastId > 0
                ? $this->encodeCursor($userId, $lastId, 'reels')
                : null,
        ];
    }

    /**
     * Establishes the classic WoWonder logged-in context ($wo['user']) so the
     * shared helper functions behave as they do on the web. Reused by other
     * mobile services that need the same context.
     */
    public function bootstrapWebContext(int $userId): void
    {
        global $wo;

        $user = \Wo_UserData($userId);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = (int) $user['user_id'];
        if (function_exists('Wo_LangsFromDB') && !empty($user['language'])) {
            $wo['lang'] = \Wo_LangsFromDB((string) $user['language']);
        }
    }

    /**
     * Presents a raw WoWonder post row as the mobile API post shape. Public so
     * the interaction/profile services can render posts identically to /feed.
     */
    public function present(array $post, int $viewerId): ?array
    {
        return $this->post($post, $viewerId);
    }

    /**
     * Presents a list of raw WoWonder post rows using the same canonical
     * serializer as /feed. Page/group/profile endpoints use this helper so a
     * malformed or unavailable row is skipped without failing the whole feed.
     *
     * @param array<int,mixed> $posts
     * @return array<int,array<string,mixed>>
     */
    public function presentMany(array $posts, int $viewerId): array
    {
        $items = [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $item = $this->post($post, $viewerId);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** @var array<int,array{color1:string,color2:string,text_color:string,image:string}>|null */
    private ?array $colorCache = null;

    /** Loads all colored-post gradients once (Wo_Colored_Posts), keyed by id. */
    private function allColors(): array
    {
        if ($this->colorCache !== null) {
            return $this->colorCache;
        }
        $this->colorCache = [];
        global $sqlConnect;
        if ($sqlConnect instanceof \mysqli) {
            $res = @mysqli_query(
                $sqlConnect,
                'SELECT id, color_1, color_2, text_color, image FROM Wo_Colored_Posts ORDER BY id ASC'
            );
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $this->colorCache[(int) $row['id']] = [
                    'color1' => (string) $row['color_1'],
                    'color2' => (string) ($row['color_2'] !== '' ? $row['color_2'] : $row['color_1']),
                    'text_color' => (string) ($row['text_color'] !== '' ? $row['text_color'] : '#ffffff'),
                    'image' => $this->mediaUrl((string) ($row['image'] ?? '')),
                ];
            }
        }
        return $this->colorCache;
    }

    /** Available colored-post gradients for the composer picker. */
    public function postColors(): array
    {
        $out = [];
        foreach ($this->allColors() as $id => $c) {
            $out[] = ['id' => $id] + $c;
        }
        return $out;
    }

    /** Resolves a post's color_id to its gradient, or null for a normal post. */
    private function postColor(int $colorId): ?array
    {
        return $colorId < 1 ? null : ($this->allColors()[$colorId] ?? null);
    }

    /**
     * Serializes a poll (Wo_PostData attaches `options` via
     * Ju_GetPercentageOfOptionPost + `voted_id`), or null for a non-poll.
     */
    private function pollData(array $post): ?array
    {
        if ((int) ($post['poll_id'] ?? 0) !== 1) {
            return null;
        }
        $options = is_array($post['options'] ?? null) ? $post['options'] : [];
        $total = 0;
        $out = [];
        foreach ($options as $o) {
            if (!is_array($o)) {
                continue;
            }
            $total = (int) ($o['all'] ?? $total);
            $out[] = [
                'id' => (int) ($o['id'] ?? 0),
                'text' => (string) ($o['text'] ?? ''),
                'votes' => (int) ($o['option_votes'] ?? 0),
                'percent' => (int) ($o['percentage_num'] ?? 0),
            ];
        }
        return [
            'options' => $out,
            'total_votes' => $total,
            'voted_id' => (int) ($post['voted_id'] ?? 0),
        ];
    }

    private function post(array $post, int $viewerId, int $sharedDepth = 0): ?array
    {
        $postId = (int) ($post['post_id'] ?? $post['id'] ?? 0);
        if ($postId < 1) {
            return null;
        }
        $publisher = is_array($post['publisher'] ?? null)
            ? $post['publisher']
            : (is_array($post['user_data'] ?? null) ? $post['user_data'] : []);
        $pageId = (int) ($post['page_id'] ?? 0);
        if ($pageId > 0 && function_exists('Wo_PageData')) {
            $pagePublisher = \Wo_PageData($pageId);
            if (is_array($pagePublisher)) {
                $publisher = $pagePublisher;
            }
        }
        $publisherId = (int) ($publisher['user_id'] ?? $post['user_id'] ?? 0);
        $reactions = function_exists('Wo_GetPostReactionsTypes')
            ? \Wo_GetPostReactionsTypes($postId)
            : [];
        if (!is_array($reactions)) {
            $reactions = [];
        }

        $parentId = (int) ($post['parent_id'] ?? 0);
        $sharedPost = null;
        if ($parentId > 0 && $sharedDepth < 1 && function_exists('Wo_PostData')) {
            $rawParent = \Wo_PostData($parentId);
            if (is_array($rawParent) && !empty($rawParent['id'])) {
                $sharedPost = $this->post($rawParent, $viewerId, $sharedDepth + 1);
            }
        }
        $groupId = (int) ($post['group_id'] ?? 0);
        $group = is_array($post['group_recipient'] ?? null)
            ? $post['group_recipient']
            : [];
        if ($groupId > 0 && empty($group) && function_exists('Wo_GroupData')) {
            $loadedGroup = \Wo_GroupData($groupId);
            if (is_array($loadedGroup)) {
                $group = $loadedGroup;
            }
        }

        // Viewer's relationship to the publisher, for the post-header
        // Follow / Add-Friend action. Same vocabulary as the follow endpoint:
        // 'none' | 'following' | 'requested'. Own posts get is_own so the app
        // hides the action.
        $isOwn = $publisherId > 0 && $publisherId === $viewerId;
        $relation = 'none';
        if (!$isOwn && $publisherId > 0) {
            if (function_exists('Wo_IsFollowing') && \Wo_IsFollowing($publisherId, $viewerId)) {
                $relation = 'following';
            } elseif (function_exists('Wo_IsFollowRequested') && \Wo_IsFollowRequested($publisherId, $viewerId)) {
                $relation = 'requested';
            }
        }
        $pageEventId = (int) ($post['page_event_id'] ?? 0);
        $linkedEventId = (int) ($post['event_id'] ?? 0);
        $eventId = $pageEventId > 0 ? $pageEventId : $linkedEventId;
        $event = $eventId > 0 && function_exists('Wo_EventData')
            ? \Wo_EventData($eventId)
            : null;
        $offerId = (int) ($post['offer_id'] ?? 0);
        $offer = $offerId > 0 && function_exists('Wo_GetOfferById')
            ? \Wo_GetOfferById($offerId)
            : null;
        $jobId = (int)($post['job_id'] ?? 0);
        $job = $jobId > 0 && function_exists('Wo_GetJobById')
            ? \Wo_GetJobById($jobId)
            : null;
        $fundId = (int)($post['fund_id'] ?? 0);
        $fundRaiseId = (int)($post['fund_raise_id'] ?? 0);
        $funding = null;
        $donationAmount = 0.0;
        $isDonationPost = false;
        if ($fundId > 0) {
            $funding = is_array($post['fund_data'] ?? null)
                ? $post['fund_data']
                : (function_exists('GetFundingById') ? \GetFundingById($fundId) : null);
        } elseif ($fundRaiseId > 0) {
            $raise = is_array($post['fund'] ?? null)
                ? $post['fund']
                : (
                    function_exists('GetFundByRaiseId')
                        ? \GetFundByRaiseId($fundRaiseId, (int)($post['user_id'] ?? 0))
                        : null
                );
            if (is_array($raise)) {
                $funding = is_array($raise['fund'] ?? null) ? $raise['fund'] : null;
                $donationAmount = (float)($raise['amount'] ?? 0);
                $isDonationPost = true;
            }
        }

        return [
            'id' => $postId,
            'page_id' => $pageId,
            'group_id' => $groupId,
            'text' => $this->postBodyText($post),
            // Wo_PostData renders postText to HTML and removes the @ token.
            // Orginaltext is the canonical decoded source (e.g. @username),
            // which is what Xamarin's header decorator is based on.
            'tagged_users' => $this->taggedUsers((string) ($post['postText_raw'] ?? $post['Orginaltext'] ?? $post['postText'] ?? '')),
            'created_at' => (int) ($post['time'] ?? 0),
            'post_type' => (string) ($post['postType'] ?? ''),
            'live' => (string)($post['postType'] ?? '') === 'live' ? [
                'stream_name' => (string)($post['stream_name'] ?? ''),
                'live_time' => (int)($post['live_time'] ?? 0),
                'is_still_live' => (int)($post['live_ended'] ?? 0) === 0
                    && (int)($post['live_time'] ?? 0) >= time() - 10,
                'has_ended' => (int)($post['live_ended'] ?? 0) === 1,
                'thumbnail' => $this->mediaUrl((string)($post['postFileThumb'] ?? '')),
                'is_saved' => trim((string)($post['postFile'] ?? '')) !== '',
                'label' => (int)($post['live_time'] ?? 0) > 0
                    && (int)($post['live_ended'] ?? 0) === 0
                    && (int)($post['live_time'] ?? 0) >= time() - 10
                        ? $this->publisherName($publisher) . ' Started broadcasting live'
                        : $this->publisherName($publisher) . ' Stream has ended',
            ] : null,
            'boosted' => (int)($post['boosted'] ?? 0) === 1,
            'promoted_label' => (int)($post['boosted'] ?? 0) === 1 ? 'Promoted' : '',
            // Xamarin WoTextDecorator reads AlbumName and renders:
            // "added new photos to {name} Album" in the post header.
            'album_name' => html_entity_decode(
                (string) ($post['album_name'] ?? $post['albumName'] ?? ''),
                ENT_QUOTES | ENT_HTML5
            ),
            'feeling' => (string) ($post['postFeeling'] ?? ''),
            // Xamarin appends these four activity phrases to the author line
            // in this order, before location and group-recipient context.
            'traveling' => $this->plainText((string) ($post['postTraveling'] ?? '')),
            'watching' => $this->plainText((string) ($post['postWatching'] ?? '')),
            'playing' => $this->plainText((string) ($post['postPlaying'] ?? '')),
            'listening' => $this->plainText((string) ($post['postListening'] ?? '')),
            'parent_id' => $parentId,
            // The web/v2 contract exposes the complete original post as
            // `shared_info`. Mobile keeps a typed equivalent so a timeline,
            // page, or group share survives refresh and can render its real
            // publisher/header/content instead of only a parent id.
            'shared_post' => $sharedPost,
            'views' => (int) ($post['post_views'] ?? $post['postViews'] ?? 0),
            // Colored-post gradient (Wo_Colored_Posts) or null for a normal post.
            'color' => $this->postColor((int) ($post['color_id'] ?? 0)),
            // Poll (options + votes + the viewer's vote) or null for a non-poll.
            'poll' => $this->pollData($post),
            // Set by the algorithm addon when enabled (e.g. "From your network").
            'algorithm_reason' => (string) ($post['algorithm_reason'] ?? ''),
            'publisher' => [
                'id' => $pageId > 0 ? $pageId : $publisherId,
                'page_id' => $pageId,
                'is_page' => $pageId > 0,
                'username' => (string) ($pageId > 0 ? ($publisher['page_name'] ?? '') : ($publisher['username'] ?? '')),
                'name' => $pageId > 0
                    ? html_entity_decode((string)($publisher['page_title'] ?? ''), ENT_QUOTES | ENT_HTML5)
                    : $this->publisherName($publisher),
                'avatar' => $this->mediaUrl((string) ($publisher['avatar'] ?? '')),
                'verified' => (int) ($publisher['verified'] ?? 0) === 1,
                'is_pro' => (int) ($publisher['is_pro'] ?? 0) === 1,
                'is_own' => $pageId > 0 ? \Wo_IsPageOnwer($pageId) : $isOwn,
                'relation' => $relation,
            ],
            'group' => $groupId > 0 ? [
                'id' => $groupId,
                'username' => (string) ($group['group_name'] ?? $group['username'] ?? ''),
                'name' => html_entity_decode(
                    (string) ($group['group_title'] ?? $group['name'] ?? ''),
                    ENT_QUOTES | ENT_HTML5
                ),
                'avatar' => $this->mediaUrl((string) ($group['avatar'] ?? '')),
            ] : null,
            'event' => is_array($event) ? [
                'id' => $eventId,
                'name' => html_entity_decode(strip_tags((string) ($event['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                // Xamarin uses "Created new event" for PageEventId posts, but
                // uses the event name itself for EventId posts.
                'header_label' => $pageEventId > 0
                    ? 'Created new event'
                    : html_entity_decode(strip_tags((string) ($event['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'location' => html_entity_decode(strip_tags((string) ($event['location'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'start_date' => (string) ($event['start_date'] ?? ''),
                'start_time' => (string) ($event['start_time'] ?? ''),
                'end_date' => (string) ($event['end_date'] ?? ''),
                'going_count' => function_exists('Wo_TotalGoingUsers')
                    ? (int) \Wo_TotalGoingUsers($eventId)
                    : (int) ($event['going_count'] ?? 0),
                'cover' => $this->mediaUrl((string) ($event['cover'] ?? '')),
            ] : null,
            'offer' => is_array($offer) ? [
                'id' => $offerId,
                'offer_text' => (string) ($offer['offer_text'] ?? 'Free Shipping'),
                'discounted_items' => html_entity_decode(strip_tags((string) ($offer['discounted_items'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => html_entity_decode(strip_tags((string) ($offer['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'expire_date' => (string) ($offer['expire_date'] ?? ''),
                'image' => $this->mediaUrl((string) ($offer['image'] ?? '')),
                'header_label' => 'Created new offer',
            ] : null,
            'job' => is_array($job) ? [
                'id' => $jobId,
                'title' => html_entity_decode(strip_tags((string)($job['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'minimum' => (string)($job['minimum'] ?? '0'),
                'maximum' => (string)($job['maximum'] ?? '0'),
                'currency' => SystemCurrency::normalize((string)($job['currency'] ?? '')),
                'currency_symbol' => SystemCurrency::symbol((string)($job['currency'] ?? '')),
                'job_type' => (string)($job['job_type'] ?? ''),
                'image' => $this->mediaUrl((string)($job['image'] ?? '')),
                'page_name' => '@' . ltrim((string)($job['page']['page_name'] ?? ''), '@'),
                'is_owner' => (int)($job['user_id'] ?? 0) === $viewerId,
                'has_applied' => !empty($job['apply']),
                'apply_count' => (int)($job['apply_count'] ?? 0),
                'button_text' => (int)($job['user_id'] ?? 0) === $viewerId
                    ? 'Show applies (' . (int)($job['apply_count'] ?? 0) . ')'
                    : (!empty($job['apply']) ? 'Already applied' : 'Apply now'),
            ] : null,
            // Xamarin renders both the original fund_id post and a
            // fund_raise_id donation activity with the same native funding
            // content card. Their normal publisher header is left untouched.
            'funding' => is_array($funding) ? [
                'id' => (int)($funding['id'] ?? 0),
                'hashed_id' => (string)($funding['hashed_id'] ?? ''),
                'title' => html_entity_decode(
                    strip_tags((string)($funding['title'] ?? '')),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ),
                'description' => html_entity_decode(
                    strip_tags((string)($funding['description'] ?? '')),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ),
                'amount' => (float)($funding['amount'] ?? 0),
                'raised' => (float)($funding['raised'] ?? 0),
                'progress' => max(0.0, min(100.0, (float)($funding['bar'] ?? 0))),
                'image' => $this->mediaUrl((string)($funding['image'] ?? '')),
                'is_donation_post' => $isDonationPost,
                'donation_amount' => $donationAmount,
            ] : null,
            'link' => !empty($post['postLink']) ? [
                'url' => (string) $post['postLink'],
                'title' => html_entity_decode(strip_tags((string) ($post['postLinkTitle'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => html_entity_decode(strip_tags((string) ($post['postLinkContent'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'image' => $this->mediaUrl((string) ($post['postLinkImage'] ?? '')),
            ] : null,
            'media' => $this->media($post),
            'map' => $this->plainText((string) ($post['postMap'] ?? '')),
            'comments_count' => (int) (\Wo_CountPostComment($postId) ?: 0),
            'shares_count' => (int) ($post['post_shared'] ?? $post['shared'] ?? 0),
            'reactions' => [
                'count' => (int) ($reactions['count'] ?? 0),
                'selected' => !empty($reactions['is_reacted'])
                    ? (string) ($reactions['type'] ?? '')
                    : null,
                'types' => $this->reactionCounts($reactions),
            ],
            'can_delete' => $publisherId === $viewerId,
            'can_edit' => $publisherId === $viewerId,
            'is_saved' => function_exists('Wo_IsPostSaved')
                ? (bool) \Wo_IsPostSaved($postId, $viewerId)
                : false,
        ];
    }

    private function media(array $post): array
    {
        $items = [];
        foreach (['photo_album', 'photo_multi'] as $collection) {
            if (!is_array($post[$collection] ?? null)) {
                continue;
            }
            foreach ($post[$collection] as $photo) {
                if (!is_array($photo)) {
                    continue;
                }
                $url = $this->mediaUrl((string) ($photo['image_org'] ?? $photo['image'] ?? ''));
                if ($url !== '') {
                    $items[] = ['type' => 'image', 'url' => $url, 'thumbnail' => $url];
                }
            }
        }
        if (!empty($items)) {
            return array_slice($items, 0, 10);
        }

        $file = $this->mediaUrl((string) ($post['postFile'] ?? ''));
        if ($file !== '') {
            $extension = strtolower((string) pathinfo(parse_url($file, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            $type = match ($extension) {
                'jpg', 'jpeg', 'png', 'gif', 'webp' => 'image',
                'mp4', 'mov', 'm4v', 'webm', 'mkv' => 'video',
                'mp3', 'wav', 'm4a', 'aac', 'ogg' => 'audio',
                default => 'file',
            };
            $items[] = [
                'type' => $type,
                'url' => $file,
                'thumbnail' => $this->mediaUrl((string) ($post['postFileThumb'] ?? '')),
            ];
        }
        return $items;
    }

    private function reactionCounts(array $reactions): array
    {
        $counts = [];
        for ($type = 1; $type <= 6; $type++) {
            if (isset($reactions[$type]) || isset($reactions[(string) $type])) {
                $counts[(string) $type] = 1;
            }
        }
        return $counts;
    }

    private function publisherName(array $publisher): string
    {
        $name = trim((string) ($publisher['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = trim((string) ($publisher['first_name'] ?? '') . ' ' . (string) ($publisher['last_name'] ?? ''));
        return $name !== '' ? $name : (string) ($publisher['username'] ?? '');
    }

    private function taggedUsers(string $text): array
    {
        preg_match_all('/@(?:\[(?<id>[0-9]+)\]|(?<username>[A-Za-z0-9_]+))/', $text, $matches, PREG_SET_ORDER);
        $items = [];
        $seen = [];
        foreach ($matches as $match) {
            $id = !empty($match['id'])
                ? (int)$match['id']
                : (int)(function_exists('Wo_UserIdFromUsername') ? \Wo_UserIdFromUsername((string)($match['username'] ?? '')) : 0);
            if ($id < 1 || isset($seen[$id])) continue;
            $user = \Wo_UserData($id);
            if (!is_array($user)) continue;
            $seen[$id] = true;
            $items[] = [
                'id' => $id,
                'username' => (string)($user['username'] ?? ''),
                'name' => $this->publisherName($user),
                'avatar' => $this->mediaUrl((string)($user['avatar'] ?? '')),
                'verified' => (int)($user['verified'] ?? 0) === 1,
                'is_pro' => (int)($user['is_pro'] ?? 0) === 1,
                'is_own' => false,
                'relation' => 'none',
            ];
        }
        return $items;
    }

    private function postBodyText(array $post): string
    {
        $raw = (string)($post['postText_raw'] ?? '');
        if ($raw !== '') {
            $withoutHeaderTags = preg_replace('/^(?:\s*@\[[0-9]+\][\s,]*)+/', '', $raw);
            $decoded = function_exists('Wo_EditMarkup')
                ? \Wo_EditMarkup((string)$withoutHeaderTags, true, true, true)
                : (string)$withoutHeaderTags;
            return $this->plainText($decoded);
        }
        $original = (string)($post['Orginaltext'] ?? '');
        if ($original !== '') {
            $withoutHeaderTags = preg_replace('/^(?:\s*@(?:\[[0-9]+\]|[A-Za-z0-9_]+)[\s,]*)+/', '', $original);
            return $this->plainText((string)$withoutHeaderTags);
        }
        return $this->plainText((string)($post['postText'] ?? ''));
    }

    private function plainText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function mediaUrl(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return function_exists('Wo_GetMedia') ? (string) \Wo_GetMedia($value) : $value;
    }

    private function encodeCursor(int $userId, int $afterPostId, string $filter): string
    {
        $json = json_encode([
            'v' => 1,
            'u' => $userId,
            'a' => $afterPostId,
            'f' => $filter,
            'e' => time() + self::CURSOR_TTL,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $encoded . '.' . $this->security->hash('feed-cursor:' . $encoded);
    }

    private function decodeCursor(?string $cursor, int $userId, string $filter): int
    {
        if ($cursor === null || trim($cursor) === '') {
            return 0;
        }
        if (strlen($cursor) > 500 || preg_match('/^(?<data>[A-Za-z0-9_-]+)\.(?<mac>[a-f0-9]{64})$/', $cursor, $parts) !== 1) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The feed cursor is invalid.', 'cursor');
        }
        $expected = $this->security->hash('feed-cursor:' . $parts['data']);
        if (!hash_equals($expected, $parts['mac'])) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The feed cursor is invalid.', 'cursor');
        }
        $padded = $parts['data'] . str_repeat('=', (4 - strlen($parts['data']) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload)
            || (int) ($payload['v'] ?? 0) !== 1
            || (int) ($payload['u'] ?? 0) !== $userId
            || (string) ($payload['f'] ?? '') !== $filter
            || (int) ($payload['e'] ?? 0) < time()
            || (int) ($payload['a'] ?? 0) < 1) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The feed cursor is invalid.', 'cursor');
        }
        return (int) $payload['a'];
    }

    /**
     * Cursor matching WoWonder's most_liked API contract (after_post_id,
     * lasttotal, and dt). This keeps Popular Posts pagination identical to
     * Xamarin's GetPopularPost/GetMoreMostLiked flow.
     *
     * @return array{after_post_id:int,last_total:int,dt:int}
     */
    private function decodePopularCursor(?string $cursor, int $userId): array
    {
        if ($cursor === null || trim($cursor) === '') {
            return ['after_post_id' => 0, 'last_total' => 0, 'dt' => 0];
        }
        if (
            strlen($cursor) > 500
            || preg_match('/^(?<data>[A-Za-z0-9_-]+)\.(?<mac>[a-f0-9]{64})$/', $cursor, $parts) !== 1
        ) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The popular posts cursor is invalid.', 'cursor');
        }
        $expected = $this->security->hash('popular-cursor:' . $parts['data']);
        if (!hash_equals($expected, $parts['mac'])) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The popular posts cursor is invalid.', 'cursor');
        }
        $padded = $parts['data'] . str_repeat('=', (4 - strlen($parts['data']) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (
            !is_array($payload)
            || (int) ($payload['v'] ?? 0) !== 1
            || (int) ($payload['u'] ?? 0) !== $userId
            || (int) ($payload['e'] ?? 0) < time()
            || (int) ($payload['a'] ?? 0) < 1
            || (int) ($payload['t'] ?? 0) < 1
            || (int) ($payload['d'] ?? 0) < 1
        ) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The popular posts cursor is invalid.', 'cursor');
        }
        return [
            'after_post_id' => (int) $payload['a'],
            'last_total' => (int) $payload['t'],
            'dt' => (int) $payload['d'],
        ];
    }

    private function encodePopularCursor(
        int $userId,
        int $afterPostId,
        int $lastTotal,
        int $dt
    ): string {
        $json = json_encode([
            'v' => 1,
            'u' => $userId,
            'a' => $afterPostId,
            't' => $lastTotal,
            'd' => $dt,
            'e' => time() + self::CURSOR_TTL,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $encoded . '.' . $this->security->hash('popular-cursor:' . $encoded);
    }
}
