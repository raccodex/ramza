<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/**
 * Post interactions (reactions + comments) and member profiles for the mobile
 * API. Reuses the classic WoWonder helpers on an authenticated logged-in
 * context, mirroring the presentation contract used by {@see FeedService}.
 */
final class InteractionService
{
    private const CURSOR_TTL = 86_400;

    /**
     * Returns only post types that the current user may actually create.
     * bootstrapWebContext applies WoWonder's admin, verified and Pro-package
     * restrictions before these values are read.
     */
    public function composerConfiguration(string $accessToken, string $clientId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_composer_config', 120, 60);
        return $this->composerFlags();
    }

    /**
     * Returns the effective composer permissions after authenticate() has
     * bootstrapped WoWonder's per-user package restrictions.
     */
    private function composerFlags(): array
    {
        global $wo;

        $config = is_array($wo['config'] ?? null) ? $wo['config'] : [];
        $enabled = static fn(string $key, bool $fallback = false): bool =>
            array_key_exists($key, $config) ? (int)$config[$key] === 1 : $fallback;

        $canVideo = $enabled('video_upload') && $enabled('can_use_video_upload', true);
        $canAudio = $enabled('audio_upload') && $enabled('can_use_audio_upload', true);

        return [
            'photo' => true,
            'video' => $canVideo,
            'tag' => true,
            'location' => true,
            'feeling' => $enabled('post_feelings'),
            'gif' => $enabled('stickers') && $enabled('can_use_gif', true),
            'file' => $enabled('fileSharing'),
            'music' => $canAudio,
            'voice' => $canAudio,
            'poll' => $enabled('post_poll') && $enabled('can_use_poll', true),
            'background' => $enabled('colored_posts_system')
                && $enabled('can_use_colored_posts', true),
            'reel' => $canVideo,
            'live' => $enabled('live_video') && $enabled('can_use_live', true),
            // Life updates are stored as standard text posts and therefore do
            // not depend on a separate WoWonder module.
            'life_update' => true,
        ];
    }

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly Security $security,
        private readonly FeedService $feed
    ) {
    }

    // ---- Reactions ----------------------------------------------------------

    public function react(string $accessToken, string $clientId, int $postId, string $type): array
    {
        // Reaction is applied to the authenticated $wo['user'] context.
        $this->authenticate($accessToken, $clientId, 'mobile_react', 120, 60);
        if (preg_match('/^[1-6]$/', $type) !== 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The reaction type is invalid.', 'type');
        }
        $this->assertPostVisible($postId);
        if (!\Wo_AddReactions($postId, $type)) {
            throw new ApiException(422, 'REACTION_FAILED', 'The reaction could not be saved.');
        }
        return $this->reactionSummary($postId);
    }

    public function removeReaction(string $accessToken, string $clientId, int $postId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_react', 120, 60);
        $this->assertPostVisible($postId);
        \Wo_DeleteReactions($postId);
        return $this->reactionSummary($postId);
    }

    public function reactionUsers(
        string $accessToken,
        string $clientId,
        int $postId,
        string $type,
        int $offset,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_reactions_read', 120, 60);
        $this->assertPostVisible($postId);
        $type = in_array($type, ['1', '2', '3', '4', '5', '6'], true) ? $type : '1';
        $limit = max(1, min(50, $limit));
        global $sqlConnect;
        $afterId = max(0, $offset);
        $sql = 'SELECT id AS row_id, user_id, reaction FROM ' . T_REACTIONS
            . ' WHERE post_id = ? AND reaction = ? AND id > ?'
            . ' ORDER BY id ASC LIMIT ?';
        $stmt = $sqlConnect->prepare($sql);
        if (!$stmt) {
            throw new ApiException(500, 'REACTIONS_LOAD_FAILED', 'Reactions could not be loaded.');
        }
        $reactionType = (int)$type;
        $stmt->bind_param('iiii', $postId, $reactionType, $afterId, $limit);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new ApiException(500, 'REACTIONS_LOAD_FAILED', 'Reactions could not be loaded.');
        }
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $user = \Wo_UserData((int)$row['user_id']);
            if (!is_array($user) || empty($user['user_id'])) {
                continue;
            }
            $items[] = [
                'row_id' => (int)$row['row_id'],
                'reaction' => (string)$row['reaction'],
                'user' => [
                    'id' => (int)$user['user_id'],
                    'username' => (string)($user['username'] ?? ''),
                    'name' => $this->displayName($user),
                    'avatar' => $this->mediaUrl((string)($user['avatar'] ?? '')),
                    'verified' => (int)($user['verified'] ?? 0) === 1,
                    'is_pro' => (int)($user['is_pro'] ?? 0) === 1,
                    'pro_type' => (string)($user['pro_type'] ?? '1'),
                    'is_own' => (int)$user['user_id'] === $viewerId,
                    'relation' => (int)$user['user_id'] === $viewerId
                        ? 'self'
                        : (!empty($user['is_following']) ? 'following' : 'none'),
                ],
            ];
        }
        $stmt->close();
        $next = count($items) >= $limit ? (int)($items[array_key_last($items)]['row_id'] ?? 0) : 0;
        return ['items' => $items, 'next_offset' => $next > 0 ? $next : null];
    }

    public function postAction(string $accessToken, string $clientId, int $postId, string $action): array
    {
        $userId = $this->authenticate($accessToken, $clientId, 'mobile_post_action', 60, 60);
        $this->assertPostVisible($postId);
        global $sqlConnect, $wo, $db;
        $post = \Wo_PostData($postId);
        $own = is_array($post) && (int)($post['user_id'] ?? 0) === $userId;
        $result = false;
        switch ($action) {
            case 'save':
                $result = \Wo_SavePosts(['post_id' => $postId]);
                if ($result === false) {
                    break;
                }
                return [
                    'ok' => true,
                    'result' => $result,
                    'is_saved' => (bool) \Wo_IsPostSaved($postId, $userId),
                ];
            case 'hide': if (!$own) $result = \Wo_HidePost($postId); break;
            case 'report': if (!$own) $result = \Wo_ReportPost(['post_id'=>$postId,'text'=>'Reported from mobile app']); break;
            case 'notify_publisher':
                $publisherId = (int)($post['user_id'] ?? 0);
                if ($publisherId <= 0 || $publisherId === $userId || !is_object($db)) {
                    break;
                }
                $enabled = !\Wo_IsFollowingNotify($publisherId, $userId);
                $result = $db->where('following_id', $publisherId)
                    ->where('follower_id', $userId)
                    ->update(T_FOLLOWERS, ['notify' => $enabled ? 1 : 0]);
                if ($result === false) {
                    break;
                }
                return ['ok' => true, 'notifications_enabled' => $enabled];
            case 'share': $result = \Wo_SharePost($postId); break;
            case 'boost':
                if (!$own && !((int)($post['page_id'] ?? 0) > 0 && function_exists('Wo_IsPageOnwer') && \Wo_IsPageOnwer((int)$post['page_id']))) {
                    break;
                }
                $this->feed->bootstrapWebContext($userId);
                $result = function_exists('Wo_BoostPost') ? \Wo_BoostPost($postId) : false;
                if ($result === false) {
                    throw new ApiException(403, 'BOOST_NOT_ALLOWED', 'Boosting requires an eligible Pro promotion plan.');
                }
                return ['ok' => true, 'boosted' => $result === 'boosted', 'state' => (string)$result];
            case 'delete': if ($own) $result = \Wo_DeletePost($postId); break;
            case 'toggle_comments':
                if ($own) {
                    $next = (int)($post['comments_status'] ?? 1) === 1 ? 0 : 1;
                    $stmt=$sqlConnect->prepare('UPDATE Wo_Posts SET comments_status=? WHERE id=? AND user_id=?');
                    if($stmt){$stmt->bind_param('iii',$next,$postId,$userId);$result=$stmt->execute();$stmt->close();}
                    return ['ok'=>(bool)$result,'comments_status'=>$next];
                }
                break;
            default: throw new ApiException(422, 'VALIDATION_FAILED', 'Unsupported post action.', 'action');
        }
        if ($result === false) throw new ApiException(403, 'POST_ACTION_DENIED', 'This post action is not allowed.');
        return ['ok'=>true,'result'=>$result];
    }

    public function sharePost(
        string $accessToken,
        string $clientId,
        int $postId,
        string $destination,
        int $destinationId,
        string $text
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_post_share', 30, 60);
        $this->assertPostVisible($postId);
        $destination = in_array($destination, ['timeline', 'group', 'page'], true)
            ? $destination
            : 'timeline';
        if ($destination === 'timeline' && trim($text) === '') {
            if (!\Wo_SharePost($postId)) {
                throw new ApiException(422, 'SHARE_FAILED', 'The post could not be shared.');
            }
            // Wo_SharePost historically returns only a boolean even though it
            // inserts a new Wo_Posts row. Resolve that row so mobile clients
            // can insert the share immediately, exactly like text/group/page
            // shares below.
            global $sqlConnect;
            $newId = 0;
            $stmt = $sqlConnect->prepare(
                'SELECT id FROM ' . T_POSTS
                . ' WHERE user_id=? AND parent_id=? ORDER BY id DESC LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $viewerId, $postId);
                $stmt->execute();
                $resolvedId = 0;
                $stmt->bind_result($resolvedId);
                if ($stmt->fetch()) {
                    $newId = (int)$resolvedId;
                }
                $stmt->close();
            }
            $raw = $newId > 0 ? \Wo_PostData($newId) : null;
            $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
            return ['shared' => true, 'post' => $item, 'post_id' => $newId];
        }
        $groupId = $destination === 'group' ? max(0, $destinationId) : 0;
        $pageId = $destination === 'page' ? max(0, $destinationId) : 0;
        if ($groupId > 0 && !\Wo_IsGroupOnwer($groupId) && !\Wo_IsGroupJoined($groupId, $viewerId)) {
            throw new ApiException(403, 'GROUP_SHARE_FORBIDDEN', 'Join this group before sharing.');
        }
        if ($pageId > 0 && !\Wo_IsPageOnwer($pageId)) {
            throw new ApiException(403, 'PAGE_SHARE_FORBIDDEN', 'Only a page owner can share as this page.');
        }
        if (($destination === 'group' && $groupId < 1) || ($destination === 'page' && $pageId < 1)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose where to share the post.', 'destination_id');
        }
        $newId = \Wo_RegisterPost([
            'user_id' => $viewerId,
            'postText' => \Wo_Secure(trim($text)),
            'time' => time(),
            'postType' => 'post',
            'postPrivacy' => '0',
            'parent_id' => $postId,
            'group_id' => $groupId,
            'page_id' => $pageId,
        ]);
        if (!is_numeric($newId) || (int)$newId < 1) {
            throw new ApiException(422, 'SHARE_FAILED', 'The post could not be shared.');
        }
        $raw = \Wo_PostData((int)$newId);
        $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
        return ['shared' => true, 'post' => $item, 'post_id' => (int)$newId];
    }

    public function editPost(string $accessToken, string $clientId, int $postId, string $text): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_post_edit', 30, 60);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 10000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Post text is required.', 'text');
        }
        $post = \Wo_PostData($postId);
        if (!is_array($post) || (int)($post['user_id'] ?? 0) !== $viewerId) {
            throw new ApiException(403, 'POST_EDIT_FORBIDDEN', 'You cannot edit this post.');
        }
        if (!\Wo_UpdatePost(['post_id' => $postId, 'text' => $text])) {
            throw new ApiException(422, 'POST_EDIT_FAILED', 'The post could not be updated.');
        }
        $updated = \Wo_PostData($postId);
        $presented = is_array($updated) ? $this->feed->present($updated, $viewerId) : null;
        if ($presented === null) {
            throw new ApiException(500, 'POST_EDIT_FAILED', 'The post was updated but could not be loaded.');
        }
        return $presented;
    }

    // ---- Comments -----------------------------------------------------------

    public function postDetail(string $accessToken, string $clientId, int $postId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_post_detail', 120, 60);
        $this->assertPostVisible($postId);
        $raw = \Wo_PostData($postId);
        $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
        if (!is_array($item)) {
            throw new ApiException(404, 'POST_NOT_FOUND', 'This post is not available.');
        }
        return $item;
    }

    public function comments(
        string $accessToken,
        string $clientId,
        int $postId,
        ?string $cursor,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_comments_read', 120, 60);
        $limit = max(1, min(30, $limit));
        $this->assertPostVisible($postId);
        $offset = $this->decodeCursor($cursor, $viewerId, 'comments:' . $postId);

        $raw = \Wo_GetPostCommentsAPI($postId, $limit, $offset);
        if (!is_array($raw)) {
            $raw = [];
        }
        $items = [];
        foreach ($raw as $comment) {
            if (is_array($comment)) {
                $items[] = $this->presentComment($comment, $viewerId);
            }
        }
        if (function_exists('Wo_RamzaAlgorithmConfigSwitch')
            && \Wo_RamzaAlgorithmConfigSwitch('algorithm_system', '0')) {
            usort($items, static function (array $left, array $right): int {
                $rank = ((int)$right['rank_score']) <=> ((int)$left['rank_score']);
                return $rank !== 0 ? $rank : ((int)$right['created_at'] <=> (int)$left['created_at']);
            });
        }
        $lastId = empty($items) ? 0 : (int) $items[array_key_last($items)]['id'];
        return [
            'items' => $items,
            'next_cursor' => count($items) >= $limit && $lastId > 0
                ? $this->encodeCursor($viewerId, $lastId, 'comments:' . $postId)
                : null,
        ];
    }

    public function addComment(string $accessToken, string $clientId, int $postId, string $text): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_comment_add', 30, 60);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 10_000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A comment is required.', 'text');
        }
        $this->assertPostVisible($postId);

        // Wo_RegisterPostComment returns the new comment id (int).
        $commentId = \Wo_RegisterPostComment([
            'post_id' => $postId,
            'user_id' => $viewerId,
            'text' => $text,
        ]);
        if (!is_numeric($commentId) || (int) $commentId < 1) {
            throw new ApiException(422, 'COMMENT_FAILED', 'The comment could not be posted.');
        }
        $raw = \Wo_GetPostComment((int) $commentId);
        if (!is_array($raw)) {
            throw new ApiException(500, 'COMMENT_FAILED', 'The comment was posted but could not be loaded.');
        }
        return $this->presentComment($raw, $viewerId);
    }

    public function toggleCommentLike(string $accessToken, string $clientId, int $commentId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_comment_like', 60, 60);
        $comment = \Wo_GetPostComment($commentId);
        if (!is_array($comment)) {
            throw new ApiException(404, 'COMMENT_NOT_FOUND', 'This comment is not available.');
        }
        $this->assertPostVisible((int)($comment['post_id'] ?? 0));
        $result = \Wo_AddCommentLikes($commentId);
        if ($result !== 'liked' && $result !== 'unliked') {
            throw new ApiException(422, 'COMMENT_LIKE_FAILED', 'The comment reaction could not be changed.');
        }
        return [
            'is_liked' => $result === 'liked',
            'likes_count' => (int) \Wo_CountCommentLikes($commentId),
        ];
    }

    public function editComment(string $accessToken, string $clientId, int $commentId, string $text): array
    {
        global $sqlConnect;
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_comment_edit', 30, 60);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 10_000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A comment is required.', 'text');
        }
        $comment = \Wo_GetPostComment($commentId);
        if (!is_array($comment)) {
            throw new ApiException(404, 'COMMENT_NOT_FOUND', 'This comment is not available.');
        }
        if ((int)($comment['user_id'] ?? 0) !== $viewerId) {
            throw new ApiException(403, 'COMMENT_EDIT_FORBIDDEN', 'You cannot edit this comment.');
        }
        $this->assertPostVisible((int)($comment['post_id'] ?? 0));
        $escaped = mysqli_real_escape_string($sqlConnect, $text);
        if (!mysqli_query($sqlConnect, "UPDATE " . T_COMMENTS . " SET `text` = '{$escaped}' WHERE `id` = " . $commentId . " LIMIT 1")) {
            throw new ApiException(500, 'COMMENT_EDIT_FAILED', 'The comment could not be updated.');
        }
        $this->ensureCommentEditsTable();
        $editedAt = time();
        mysqli_query(
            $sqlConnect,
            "INSERT INTO `Wo_RamzaCommentEdits` (`comment_id`, `edited_at`) VALUES (" . $commentId . ", " . $editedAt . ") " .
            "ON DUPLICATE KEY UPDATE `edited_at` = VALUES(`edited_at`)"
        );
        $updated = \Wo_GetPostComment($commentId);
        if (!is_array($updated)) {
            throw new ApiException(500, 'COMMENT_EDIT_FAILED', 'The comment was updated but could not be loaded.');
        }
        return $this->presentComment($updated, $viewerId);
    }

    public function deleteComment(string $accessToken, string $clientId, int $commentId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_comment_delete', 30, 60);
        $comment = \Wo_GetPostComment($commentId);
        if (!is_array($comment)) {
            throw new ApiException(404, 'COMMENT_NOT_FOUND', 'This comment is not available.');
        }
        $this->assertPostVisible((int)($comment['post_id'] ?? 0));
        if (!\Wo_DeletePostComment($commentId)) {
            throw new ApiException(403, 'COMMENT_DELETE_FORBIDDEN', 'You cannot delete this comment.');
        }
        return ['deleted' => true];
    }

    public function commentReplies(
        string $accessToken,
        string $clientId,
        int $commentId,
        int $offset,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_replies_read', 120, 60);
        $comment = \Wo_GetPostComment($commentId);
        if (!is_array($comment)) {
            throw new ApiException(404, 'COMMENT_NOT_FOUND', 'This comment is not available.');
        }
        $this->assertPostVisible((int)($comment['post_id'] ?? 0));
        $limit = max(1, min(30, $limit));
        $rows = \Wo_GetCommentRepliesAPI($commentId, $limit, 'ASC', max(0, $offset));
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $reply) {
            if (is_array($reply)) $items[] = $this->presentReply($reply, $viewerId);
        }
        $next = count($items) >= $limit ? (int)($items[array_key_last($items)]['id'] ?? 0) : 0;
        return ['items' => $items, 'next_offset' => $next > 0 ? $next : null];
    }

    public function addCommentReply(
        string $accessToken,
        string $clientId,
        int $commentId,
        string $text
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_reply_add', 40, 60);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 10_000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A reply is required.', 'text');
        }
        $comment = \Wo_GetPostComment($commentId);
        if (!is_array($comment)) {
            throw new ApiException(404, 'COMMENT_NOT_FOUND', 'This comment is not available.');
        }
        $this->assertPostVisible((int)($comment['post_id'] ?? 0));
        $replyId = \Wo_RegisterCommentReply([
            'comment_id' => $commentId,
            'user_id' => $viewerId,
            'text' => $text,
        ]);
        if (!is_numeric($replyId) || (int)$replyId < 1) {
            throw new ApiException(422, 'REPLY_FAILED', 'The reply could not be posted.');
        }
        $reply = \Wo_GetCommentReply((int)$replyId);
        if (!is_array($reply)) {
            throw new ApiException(500, 'REPLY_FAILED', 'The reply was posted but could not be loaded.');
        }
        return $this->presentReply($reply, $viewerId);
    }

    public function toggleReplyLike(string $accessToken, string $clientId, int $replyId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_reply_like', 60, 60);
        $reply = \Wo_GetCommentReply($replyId);
        if (!is_array($reply)) {
            throw new ApiException(404, 'REPLY_NOT_FOUND', 'This reply is not available.');
        }
        $comment = \Wo_GetPostComment((int)($reply['comment_id'] ?? 0));
        if (!is_array($comment)) {
            throw new ApiException(404, 'COMMENT_NOT_FOUND', 'This comment is not available.');
        }
        $this->assertPostVisible((int)($comment['post_id'] ?? 0));
        $result = \Wo_AddCommentReplyLikes($replyId);
        if ($result !== 'liked' && $result !== 'unliked') {
            throw new ApiException(422, 'REPLY_LIKE_FAILED', 'The reply reaction could not be changed.');
        }
        return [
            'is_liked' => $result === 'liked',
            'likes_count' => (int)\Wo_CountCommentReplyLikes($replyId),
        ];
    }

    public function deleteReply(string $accessToken, string $clientId, int $replyId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_reply_delete', 30, 60);
        $reply = \Wo_GetCommentReply($replyId);
        if (!is_array($reply)) {
            throw new ApiException(404, 'REPLY_NOT_FOUND', 'This reply is not available.');
        }
        $authorId = (int)($reply['user_id'] ?? 0);
        if ($authorId !== $viewerId) {
            throw new ApiException(403, 'REPLY_DELETE_FORBIDDEN', 'You cannot delete this reply.');
        }
        if (!\Wo_DeleteCommentReply($replyId)) {
            throw new ApiException(422, 'REPLY_DELETE_FAILED', 'The reply could not be deleted.');
        }
        return ['deleted' => true];
    }

    public function activities(string $accessToken, string $clientId, int $offset, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_activities_read', 120, 60);
        $limit = max(1, min(30, $limit));
        $rows = \Wo_GetActivities([
            'after_activity_id' => max(0, $offset),
            'limit' => $limit,
            'me' => true,
        ]);
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $activity) {
            if (!is_array($activity)) continue;
            $user = is_array($activity['activator'] ?? null) ? $activity['activator'] : [];
            $post = is_array($activity['postData'] ?? null) ? $activity['postData'] : [];
            $items[] = [
                'id' => (int)($activity['id'] ?? 0),
                'type' => (string)($activity['activity_type'] ?? ''),
                'created_at' => (int)($activity['time'] ?? 0),
                'post_id' => (int)($activity['post_id'] ?? 0),
                'text' => $this->plainText((string)($post['Orginaltext'] ?? $post['postText'] ?? '')),
                'actor' => [
                    'id' => (int)($user['user_id'] ?? $viewerId),
                    'username' => (string)($user['username'] ?? ''),
                    'name' => $this->displayName($user),
                    'avatar' => $this->mediaUrl((string)($user['avatar'] ?? '')),
                    'verified' => (int)($user['verified'] ?? 0) === 1,
                ],
            ];
        }
        $next = count($items) >= $limit ? (int)($items[array_key_last($items)]['id'] ?? 0) : 0;
        return ['items' => $items, 'next_offset' => $next > 0 ? $next : null];
    }

    public function gifts(string $accessToken, string $clientId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_gifts_read', 60, 60);
        $rows = function_exists('Wo_GetAllGifts') ? \Wo_GetAllGifts(50, 0) : [];
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) continue;
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'image' => $this->mediaUrl((string)($row['media_file'] ?? '')),
            ];
        }
        return $items;
    }

    public function sendGift(string $accessToken, string $clientId, int $giftId, int $recipientId): array
    {
        $from = $this->authenticate($accessToken, $clientId, 'mobile_gift_send', 20, 60);
        global $sqlConnect, $wo;
        if ($giftId < 1 || $recipientId < 1 || $recipientId === $from) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid gift recipient.');
        }
        $giftIdSafe = (int)$giftId;
        $result = mysqli_query($sqlConnect, "SELECT `media_file` FROM " . T_GIFTS . " WHERE `id`={$giftIdSafe} LIMIT 1");
        $gift = $result ? mysqli_fetch_assoc($result) : null;
        $recipient = \Wo_UserData($recipientId);
        if (!is_array($gift) || !is_array($recipient)) {
            throw new ApiException(404, 'GIFT_NOT_FOUND', 'The gift or recipient is not available.');
        }
        $now = time();
        if (!mysqli_query($sqlConnect, "INSERT INTO " . T_USERGIFTS . " (`from`,`to`,`gift_id`,`time`) VALUES ({$from},{$recipientId},{$giftIdSafe},{$now})")) {
            throw new ApiException(422, 'GIFT_SEND_FAILED', 'The gift could not be sent.');
        }
        \Wo_RegisterNotification([
            'recipient_id' => $recipientId,
            'post_id' => $from,
            'type' => 'gift',
            'text' => '',
            'type2' => 'gift_' . $giftIdSafe,
            'url' => 'index.php?link1=timeline&mode=opengift&gift_img=' . urlencode((string)$gift['media_file']) . '&u=' . ($wo['user']['username'] ?? ''),
        ]);
        return ['sent' => true];
    }

    // ---- Profiles -----------------------------------------------------------

    /** Incoming pokes, matching Xamarin's FetchPokeAsync result. */
    public function pokes(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_pokes_read', 90, 60);
        global $sqlConnect, $wo;
        $stmt = $sqlConnect->prepare(
            'SELECT p.id AS poke_id, u.* FROM ' . T_POKES . ' p JOIN ' . T_USERS
            . ' u ON u.user_id=p.send_user_id WHERE p.received_user_id=?'
            . " AND u.active='1' AND u.banned='0' ORDER BY p.id DESC"
        );
        $stmt->bind_param('i', $viewerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['poke_id'],
                'user' => [
                    'id' => (int) ($row['user_id'] ?? 0),
                    'username' => (string) ($row['username'] ?? ''),
                    'name' => $this->displayName($row),
                    'avatar' => $this->mediaUrl((string) ($row['avatar'] ?? '')),
                    'verified' => (int) ($row['verified'] ?? 0) === 1,
                    'is_pro' => (int) ($row['is_pro'] ?? 0) === 1,
                    'last_seen' => (int) ($row['lastseen'] ?? 0),
                ],
            ];
        }
        return $items;
    }

    /**
     * Xamarin "Poke Back": consume the selected incoming poke and create the
     * reverse outgoing poke as one transaction, then notify its sender.
     */
    public function pokeBack(string $accessToken, string $clientId, int $pokeId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_poke_back', 30, 60);
        if ($pokeId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid poke.', 'id');
        }
        global $sqlConnect;
        mysqli_begin_transaction($sqlConnect);
        try {
            $stmt = $sqlConnect->prepare(
                'SELECT send_user_id FROM ' . T_POKES
                . ' WHERE id=? AND received_user_id=? LIMIT 1 FOR UPDATE'
            );
            $stmt->bind_param('ii', $pokeId, $viewerId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) {
                throw new ApiException(404, 'POKE_NOT_FOUND', 'This poke is no longer available.');
            }
            $targetId = (int) $row['send_user_id'];
            $delete = $sqlConnect->prepare(
                'DELETE FROM ' . T_POKES
                . ' WHERE (received_user_id=? AND send_user_id=?)'
                . ' OR (received_user_id=? AND send_user_id=?)'
            );
            $delete->bind_param('iiii', $viewerId, $targetId, $targetId, $viewerId);
            $delete->execute();
            $delete->close();
            $insert = $sqlConnect->prepare(
                'INSERT INTO ' . T_POKES . ' (received_user_id,send_user_id) VALUES (?,?)'
            );
            $insert->bind_param('ii', $targetId, $viewerId);
            if (!$insert->execute()) {
                throw new \RuntimeException('poke insert failed');
            }
            $outgoingId = (int) $insert->insert_id;
            $insert->close();
            mysqli_commit($sqlConnect);
        } catch (ApiException $error) {
            mysqli_rollback($sqlConnect);
            throw $error;
        } catch (\Throwable) {
            mysqli_rollback($sqlConnect);
            throw new ApiException(422, 'POKE_FAILED', 'The poke could not be sent.');
        }
        if (function_exists('Wo_RegisterNotification')) {
            \Wo_RegisterNotification([
                'recipient_id' => $targetId,
                'post_id' => $viewerId,
                'type' => 'poke',
                'text' => '',
                'type2' => 'poke',
                'url' => 'index.php?link1=poke',
            ]);
        }
        return ['poked' => true, 'id' => $outgoingId, 'user_id' => $targetId];
    }

    // ---- Albums ------------------------------------------------------------

    /**
     * Xamarin MyPhotosActivity parity: the signed-in member's direct photo
     * posts, ordered newest first and paged by the last post id.
     */
    public function myPhotos(
        string $accessToken,
        string $clientId,
        int $afterId,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_photos_read', 120, 60);
        $limit = max(1, min(30, $limit));
        $this->feed->bootstrapWebContext($viewerId);

        $raw = \Wo_GetPosts([
            'filter_by' => 'photos',
            'publisher_id' => $viewerId,
            'after_post_id' => max(0, $afterId),
            'limit' => $limit,
            'is_reel' => 'disable',
            'anonymous' => true,
        ]);
        if (!is_array($raw)) {
            $raw = [];
        }

        $items = [];
        foreach ($raw as $post) {
            if (!is_array($post)) {
                continue;
            }
            // The Xamarin adapter explicitly drops rows without PostFileFull.
            // Keep album galleries in Albums instead of duplicating them here.
            $directFile = trim((string)($post['postFile_full'] ?? $post['postFile'] ?? ''));
            if ($directFile === '') {
                continue;
            }
            $presented = $this->feed->present($post, $viewerId);
            if ($presented !== null) {
                $items[] = $presented;
            }
        }

        $lastRaw = empty($raw) ? null : $raw[array_key_last($raw)];
        $lastId = is_array($lastRaw) ? (int)($lastRaw['id'] ?? 0) : 0;
        return [
            'items' => $items,
            'next_after' => count($raw) >= $limit && $lastId > 0 ? $lastId : null,
        ];
    }

    /** Xamarin MyVideoActivity parity: direct non-reel video posts. */
    public function myVideos(
        string $accessToken,
        string $clientId,
        int $afterId,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_videos_read', 120, 60);
        $limit = max(1, min(30, $limit));
        $this->feed->bootstrapWebContext($viewerId);

        $raw = \Wo_GetPosts([
            'filter_by' => 'video',
            'publisher_id' => $viewerId,
            'after_post_id' => max(0, $afterId),
            'limit' => $limit,
            'is_reel' => 'disable',
            'anonymous' => true,
        ]);
        if (!is_array($raw)) {
            $raw = [];
        }

        $items = [];
        foreach ($raw as $post) {
            if (!is_array($post)) {
                continue;
            }
            // MyVideoActivity drops entries without PostFileFull.
            if (trim((string)($post['postFile_full'] ?? $post['postFile'] ?? '')) === '') {
                continue;
            }
            $presented = $this->feed->present($post, $viewerId);
            if ($presented !== null) {
                $items[] = $presented;
            }
        }

        $lastRaw = empty($raw) ? null : $raw[array_key_last($raw)];
        $lastId = is_array($lastRaw) ? (int)($lastRaw['id'] ?? 0) : 0;
        return [
            'items' => $items,
            'next_after' => count($raw) >= $limit && $lastId > 0 ? $lastId : null,
        ];
    }

    /** Xamarin SavedPostsActivity parity: full saved-post feed. */
    public function savedPosts(
        string $accessToken,
        string $clientId,
        int $afterId,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_saved_posts_read', 120, 60);
        $limit = max(1, min(30, $limit));
        $this->feed->bootstrapWebContext($viewerId);
        $raw = \Wo_GetSavedPosts($viewerId, max(0, $afterId), $limit);
        if (!is_array($raw)) {
            $raw = [];
        }

        $items = [];
        foreach ($raw as $post) {
            if (!is_array($post)) {
                continue;
            }
            $presented = $this->feed->present($post, $viewerId);
            if ($presented !== null) {
                $presented['is_saved'] = true;
                $items[] = $presented;
            }
        }
        $lastRaw = empty($raw) ? null : $raw[array_key_last($raw)];
        $lastId = is_array($lastRaw) ? (int)($lastRaw['post_id'] ?? $lastRaw['id'] ?? 0) : 0;
        return [
            'items' => $items,
            'next_after' => count($raw) >= $limit && $lastId > 0 ? $lastId : null,
        ];
    }

    public function albums(
        string $accessToken,
        string $clientId,
        int $userId,
        int $afterId,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_albums_read', 90, 60);
        $userId = $userId > 0 ? $userId : $viewerId;
        $limit = max(1, min(30, $limit));
        global $sqlConnect;
        $sql = 'SELECT id FROM ' . T_POSTS
            . " WHERE user_id=? AND album_name<>'' AND active='1'";
        $types = 'ii';
        $params = [$userId];
        if ($afterId > 0) {
            $sql .= ' AND id<?';
            $params[] = $afterId;
            $types = 'iii';
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $sqlConnect->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $items = [];
        foreach ($rows as $row) {
            $album = $this->presentAlbum((int) $row['id'], $viewerId);
            if ($album !== null) $items[] = $album;
        }
        $next = count($items) >= $limit ? (int) $items[array_key_last($items)]['id'] : null;
        return ['items' => $items, 'next_after' => $next];
    }

    public function album(string $accessToken, string $clientId, int $albumId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_album_read', 120, 60);
        $album = $this->presentAlbum($albumId, $viewerId);
        if ($album === null) {
            throw new ApiException(404, 'ALBUM_NOT_FOUND', 'This album is no longer available.');
        }
        return $album;
    }

    public function createAlbum(
        string $accessToken,
        string $clientId,
        string $name,
        ?array $uploads
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_album_create', 10, 3600);
        $name = trim($this->plainText($name));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Please enter an album name.', 'album_name');
        }
        $files = $this->albumFiles($uploads);
        // Xamarin's creator requires more than one selected image.
        if (count($files) < 2) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Please select at least two images.', 'photos');
        }
        $postId = \Wo_RegisterPost([
            'user_id' => $viewerId,
            'album_name' => \Wo_Secure($name),
            'postPrivacy' => 0,
            'time' => time(),
        ]);
        if (!is_numeric($postId) || (int) $postId < 1) {
            throw new ApiException(422, 'ALBUM_CREATE_FAILED', 'The album could not be created.');
        }
        $postId = (int) $postId;
        try {
            $created = $this->storeAlbumFiles($postId, $files);
            if ($created !== count($files)) throw new \RuntimeException('album upload incomplete');
        } catch (\Throwable) {
            \Wo_DeletePost($postId);
            throw new ApiException(422, 'ALBUM_UPLOAD_FAILED', 'The album photos could not be uploaded.');
        }
        $album = $this->presentAlbum($postId, $viewerId);
        if ($album === null) {
            throw new ApiException(500, 'ALBUM_CREATE_FAILED', 'The album was created but could not be loaded.');
        }
        return $album;
    }

    public function addAlbumPhotos(
        string $accessToken,
        string $clientId,
        int $albumId,
        ?array $uploads
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_album_add', 20, 3600);
        $post = \Wo_PostData($albumId);
        if (!is_array($post) || (int) ($post['user_id'] ?? 0) !== $viewerId || trim((string) ($post['album_name'] ?? '')) === '') {
            throw new ApiException(403, 'ALBUM_EDIT_DENIED', 'This album cannot be changed.');
        }
        $files = $this->albumFiles($uploads);
        if ($files === []) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Please select an image.', 'photos');
        }
        if ($this->storeAlbumFiles($albumId, $files) < 1) {
            throw new ApiException(422, 'ALBUM_UPLOAD_FAILED', 'The photos could not be uploaded.');
        }
        $album = $this->presentAlbum($albumId, $viewerId);
        if ($album === null) {
            throw new ApiException(500, 'ALBUM_UPDATE_FAILED', 'The album could not be reloaded.');
        }
        return $album;
    }

    public function profile(string $accessToken, string $clientId, int $userId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_profile', 120, 60);
        if ($userId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid user id is required.', 'id');
        }
        $user = \Wo_UserData($userId);
        if (!is_array($user) || empty($user['user_id']) || (int) ($user['banned'] ?? 0) === 1) {
            throw new ApiException(404, 'USER_NOT_FOUND', 'This member is not available.');
        }
        return $this->presentProfile($user, $viewerId);
    }

    // ---- Private account settings -----------------------------------------

    public function account(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_account', 90, 60);
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        return $this->privateAccount($user, $viewerId);
    }

    public function updateAccount(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_account_update', 20, 3600);
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        $username = trim((string)($body['username'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $birthday = trim((string)($body['birthday'] ?? ''));
        $gender = trim((string)($body['gender'] ?? ''));
        $countryId = (int)($body['country_id'] ?? 0);
        if ($username === '' || preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) !== 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Username must be 3-32 letters, numbers or underscores.', 'username');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid email address.', 'email');
        }
        global $sqlConnect;
        $duplicate = $sqlConnect->prepare('SELECT user_id FROM ' . T_USERS . ' WHERE (username=? OR email=?) AND user_id<>? LIMIT 1');
        $duplicate->bind_param('ssi', $username, $email, $viewerId);
        $duplicate->execute();
        $exists = $duplicate->get_result()->fetch_assoc();
        $duplicate->close();
        if ($exists) throw new ApiException(409, 'ACCOUNT_VALUE_TAKEN', 'That username or email is already in use.');
        if ($birthday !== '' && $birthday !== '0000-00-00') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $birthday);
            if (!$date || $date->format('Y-m-d') !== $birthday) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid birthday.', 'birthday');
            }
        }
        if ($gender === '' || mb_strlen($gender) > 50) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid gender.', 'gender');
        }
        \Wo_UpdateUserData($viewerId, [
            'username' => \Wo_Secure($username), 'email' => \Wo_Secure($email),
            'birthday' => \Wo_Secure($birthday), 'gender' => \Wo_Secure($gender),
            'country_id' => (string)max(0, $countryId),
        ]);
        return $this->privateAccount(\Wo_UserData($viewerId), $viewerId);
    }

    public function accountPrivacy(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_account_privacy', 90, 60);
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        return $this->privacySettings($user);
    }

    public function updateAccountPrivacy(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_account_privacy_update', 60, 60);
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }

        $allowed = [
            'follow_privacy' => ['0', '1'],
            'message_privacy' => ['0', '1', '2'],
            'friend_privacy' => ['0', '1', '2', '3'],
            'post_privacy' => ['everyone', 'ifollow', 'nobody'],
            'birth_privacy' => ['0', '1', '2'],
            'confirm_followers' => ['0', '1'],
            'show_activities_privacy' => ['0', '1'],
            'status' => ['0', '1'],
            'share_my_location' => ['0', '1'],
        ];
        $updates = [];
        foreach ($allowed as $field => $values) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = trim((string)$body[$field]);
            if (!in_array($value, $values, true)) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid privacy option.', $field);
            }
            $updates[$field] = $value;
        }
        if ($updates === []) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a privacy setting to update.');
        }
        if (!\Wo_UpdateUserData($viewerId, $updates)) {
            throw new ApiException(422, 'PRIVACY_UPDATE_FAILED', 'Your privacy setting could not be updated.');
        }
        $updated = \Wo_UserData($viewerId);
        if (!is_array($updated)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        return $this->privacySettings($updated);
    }

    public function accountNotificationSettings(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_account_notifications', 90, 60);
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        return $this->notificationSettings($user);
    }

    public function updateAccountNotificationSettings(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_account_notifications_update', 60, 60);
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $fields = [
            'e_liked', 'e_commented', 'e_shared', 'e_followed', 'e_liked_page',
            'e_visited', 'e_mentioned', 'e_joined_group', 'e_accepted',
            'e_profile_wall_post', 'e_memory',
        ];
        $field = null;
        $value = null;
        foreach ($fields as $candidate) {
            if (!array_key_exists($candidate, $body)) {
                continue;
            }
            if ($field !== null) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Update one notification setting at a time.');
            }
            $field = $candidate;
            $value = trim((string)$body[$candidate]);
        }
        if ($field === null || !in_array($value, ['0', '1'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid notification setting.');
        }

        $settings = $this->notificationSettings($user);
        $settings[$field] = $value === '1';
        $stored = [];
        foreach ($fields as $name) {
            $stored[$name] = !empty($settings[$name]) ? 1 : 0;
        }
        $json = json_encode($stored, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new ApiException(422, 'NOTIFICATION_UPDATE_FAILED', 'Your notification setting could not be updated.');
        }

        global $sqlConnect;
        if ($field === 'e_memory') {
            $stmt = $sqlConnect->prepare(
                'UPDATE ' . T_USERS . ' SET notification_settings=? WHERE user_id=? LIMIT 1'
            );
            $stmt->bind_param('si', $json, $viewerId);
        } else {
            // $field is selected only from the fixed allow-list above.
            $stmt = $sqlConnect->prepare(
                'UPDATE ' . T_USERS . ' SET notification_settings=?, `' . $field . '`=? WHERE user_id=? LIMIT 1'
            );
            $stmt->bind_param('ssi', $json, $value, $viewerId);
        }
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new ApiException(422, 'NOTIFICATION_UPDATE_FAILED', 'Your notification setting could not be updated.');
        }
        if (function_exists('cache')) {
            \cache($viewerId, 'users', 'delete');
        }
        $updated = \Wo_UserData($viewerId);
        return is_array($updated) ? $this->notificationSettings($updated) : $settings;
    }

    public function invitationLinks(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_invitation_links', 90, 60);
        return $this->invitationLinksPayload($viewerId);
    }

    public function createInvitationLink(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_invitation_link_create', 20, 60);
        global $db, $wo;

        if ((int)($wo['config']['invite_links_system'] ?? 0) !== 1) {
            throw new ApiException(403, 'INVITATION_LINKS_DISABLED', 'Invitation links are not available.');
        }
        if (!function_exists('Wo_IfCanGenerateLink') || !\Wo_IfCanGenerateLink($viewerId)) {
            throw new ApiException(422, 'INVITATION_LIMIT_REACHED', 'You cannot generate another invitation link yet.');
        }

        $code = uniqid((string)random_int(100000, 999999), true);
        $id = $db->insert(T_INVITAION_LINKS, [
            'user_id' => $viewerId,
            'code' => $code,
            'time' => time(),
        ]);
        if (!$id) {
            throw new ApiException(422, 'INVITATION_CREATE_FAILED', 'The invitation link could not be generated.');
        }
        return $this->invitationLinksPayload($viewerId);
    }

    public function informationExportConfiguration(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_information_export', 60, 60);
        global $wo;
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $friendSystem = (int)($wo['config']['connectivitySystem'] ?? 0) !== 0;
        return [
            'profile' => $this->presentProfile($user, $viewerId),
            'friend_system' => $friendSystem,
            'options' => $friendSystem
                ? ['my_information', 'posts', 'pages', 'groups', 'friends']
                : ['my_information', 'posts', 'pages', 'groups', 'following', 'followers'],
        ];
    }

    public function exportInformation(string $accessToken, string $clientId, string $type): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_information_export_create', 8, 3600);
        global $wo;
        $friendSystem = (int)($wo['config']['connectivitySystem'] ?? 0) !== 0;
        $allowed = $friendSystem
            ? ['my_information', 'posts', 'pages', 'groups', 'friends']
            : ['my_information', 'posts', 'pages', 'groups', 'following', 'followers'];
        if (!in_array($type, $allowed, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose valid information to download.', 'type');
        }

        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $oldFile = (string)($user['info_file'] ?? '');
        if (str_starts_with($oldFile, 'upload/files/') && is_file($oldFile)) {
            @unlink($oldFile);
        }

        $wo['user_info'] = [];
        switch ($type) {
            case 'my_information':
                $wo['user_info']['setting'] = $user;
                $wo['user_info']['setting']['session'] = \Wo_GetAllSessionsFromUserID($viewerId);
                $wo['user_info']['setting']['block'] = \Wo_GetBlockedMembers($viewerId);
                $wo['user_info']['setting']['trans'] = \Wo_GetMytransactions();
                $wo['user_info']['setting']['refs'] = \Wo_GetReferrers();
                break;
            case 'posts':
                $wo['user_info']['posts'] = \Wo_GetPosts([
                    'filter_by' => 'all',
                    'publisher_id' => $viewerId,
                    'limit' => 100000,
                ]);
                break;
            case 'pages':
                $wo['user_info']['pages'] = (int)($wo['config']['pages'] ?? 0) === 1 ? \Wo_GetMyPages() : [];
                break;
            case 'groups':
                $wo['user_info']['groups'] = (int)($wo['config']['groups'] ?? 0) === 1 ? \Wo_GetMyGroups() : [];
                break;
            case 'followers':
                $wo['user_info']['followers'] = \Wo_GetFollowers($viewerId, 'profile', 1000000);
                break;
            case 'following':
                $wo['user_info']['following'] = \Wo_GetFollowing($viewerId, 'profile', 1000000);
                break;
            case 'friends':
                $wo['user_info']['friends'] = \Wo_GetMutualFriends($viewerId, 'profile', 1000000);
                break;
        }

        $html = \Wo_LoadPage('user_info/content');
        if (!is_string($html) || $html === '') {
            throw new ApiException(500, 'INFORMATION_EXPORT_FAILED', 'Your information file could not be generated.');
        }
        $directory = 'upload/files/' . date('Y') . '/' . date('m');
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ApiException(500, 'INFORMATION_EXPORT_FAILED', 'Your information file could not be generated.');
        }
        $path = $directory . '/' . \Wo_GenerateKey() . '_' . date('d') . '_' . md5((string)microtime(true)) . '_file.html';
        if (file_put_contents($path, $html, LOCK_EX) === false) {
            throw new ApiException(500, 'INFORMATION_EXPORT_FAILED', 'Your information file could not be generated.');
        }
        \Wo_UpdateUserData($viewerId, ['info_file' => $path]);
        return [
            'link' => rtrim((string)($wo['config']['site_url'] ?? ''), '/') . '/' . $path,
            'type' => $type,
        ];
    }

    public function addresses(
        string $accessToken,
        string $clientId,
        int $afterId,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_addresses', 90, 60);
        global $db;
        if ($afterId > 0) {
            $db->where('id', $afterId, '<');
        }
        $records = $db->where('user_id', $viewerId)
            ->orderBy('id', 'DESC')
            ->get(T_USER_ADDRESS, $limit);
        $items = array_map(fn($record): array => $this->presentAddress((array)$record), (array)$records);
        $last = $items === [] ? 0 : (int)$items[array_key_last($items)]['id'];
        return [
            'items' => $items,
            'next_after' => count($items) === $limit ? $last : null,
        ];
    }

    public function createAddress(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_address_create', 30, 3600);
        global $db;
        $values = $this->addressValues($body);
        $id = $db->insert(T_USER_ADDRESS, array_merge($values, [
            'user_id' => $viewerId,
            'time' => time(),
        ]));
        if (!$id) {
            throw new ApiException(422, 'ADDRESS_CREATE_FAILED', 'The address could not be created.');
        }
        return $this->presentAddress(array_merge($values, ['id' => (int)$id, 'user_id' => $viewerId, 'time' => time()]));
    }

    public function updateAddress(
        string $accessToken,
        string $clientId,
        int $addressId,
        array $body
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_address_update', 30, 3600);
        global $db;
        $record = $db->where('id', $addressId)->where('user_id', $viewerId)->getOne(T_USER_ADDRESS);
        if (!$record) {
            throw new ApiException(404, 'ADDRESS_NOT_FOUND', 'This address is unavailable.');
        }
        $values = $this->addressValues($body);
        if (!$db->where('id', $addressId)->where('user_id', $viewerId)->update(T_USER_ADDRESS, $values)) {
            throw new ApiException(422, 'ADDRESS_UPDATE_FAILED', 'The address could not be updated.');
        }
        return $this->presentAddress(array_merge((array)$record, $values));
    }

    public function deleteAddress(string $accessToken, string $clientId, int $addressId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_address_delete', 30, 3600);
        global $db;
        $record = $db->where('id', $addressId)->where('user_id', $viewerId)->getOne(T_USER_ADDRESS);
        if (!$record) {
            throw new ApiException(404, 'ADDRESS_NOT_FOUND', 'This address is unavailable.');
        }
        if (!$db->where('id', $addressId)->where('user_id', $viewerId)->delete(T_USER_ADDRESS)) {
            throw new ApiException(422, 'ADDRESS_DELETE_FAILED', 'The address could not be deleted.');
        }
        return ['deleted' => true, 'id' => $addressId];
    }

    public function earnings(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_earnings', 90, 60);
        return $this->earningsPayload($viewerId);
    }

    public function requestWithdrawal(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_withdrawal', 6, 3600);
        global $wo;
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $type = strtolower(trim((string)($body['type'] ?? '')));
        $amount = (float)($body['amount'] ?? 0);
        if (!in_array($type, ['paypal', 'bank'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a withdrawal method.', 'type');
        }
        if ($amount <= 0) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid amount.', 'amount');
        }
        if (\Wo_IsUserPaymentRequested($viewerId) === true) {
            throw new ApiException(409, 'WITHDRAWAL_PENDING', 'You already have a pending withdrawal request.');
        }
        if ((float)($user['balance'] ?? 0) < $amount) {
            throw new ApiException(422, 'INSUFFICIENT_BALANCE', 'There is not enough balance.');
        }
        if ((float)($wo['config']['m_withdrawal'] ?? 0) > $amount) {
            throw new ApiException(422, 'MINIMUM_WITHDRAWAL', 'The amount is below the minimum withdrawal.', 'amount');
        }

        $payment = [];
        if ($type === 'paypal') {
            $email = trim((string)($body['paypal_email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid PayPal email.', 'paypal_email');
            }
            \Wo_UpdateUserData($viewerId, ['paypal_email' => \Wo_Secure($email)]);
        } else {
            if ((int)($wo['config']['bank_withdrawal_system'] ?? 0) !== 1) {
                throw new ApiException(403, 'BANK_WITHDRAWAL_DISABLED', 'Bank withdrawal is not available.');
            }
            foreach (['iban', 'country', 'full_name', 'swift_code', 'address'] as $field) {
                $value = trim((string)($body[$field] ?? ''));
                if ($value === '' || mb_strlen($value) > 250) {
                    throw new ApiException(422, 'VALIDATION_FAILED', 'Please check your bank details.', $field);
                }
                $payment[$field] = \Wo_Secure($value);
            }
            \Wo_UpdateUserData($viewerId, ['paypal_email' => '']);
        }

        if (!\Wo_RequestNewPayment($viewerId, $amount, $payment)) {
            throw new ApiException(422, 'WITHDRAWAL_FAILED', 'The withdrawal request could not be sent.');
        }
        \Wo_UpdateBalance($viewerId, $amount, '-');
        return $this->earningsPayload($viewerId);
    }

    public function sendWalletMoney(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_wallet_send', 12, 3600);
        $recipientKey = trim((string)($body['recipient'] ?? ''));
        $amount = round((float)($body['amount'] ?? 0), 2);
        if ($recipientKey === '' || mb_strlen($recipientKey) > 150) {
            throw new ApiException(422, 'RECIPIENT_REQUIRED', 'Enter an email address or username.', 'recipient');
        }
        if ($amount <= 0) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid amount.', 'amount');
        }

        $recipient = $this->database->one(
            'SELECT `user_id` FROM `' . T_USERS . '`
             WHERE (`username` = ? OR `email` = ?) AND `active` = ? LIMIT 1',
            'sss',
            [$recipientKey, $recipientKey, '1']
        );
        $recipientId = (int)($recipient['user_id'] ?? 0);
        if ($recipientId <= 0) {
            throw new ApiException(404, 'RECIPIENT_NOT_FOUND', 'This member could not be found.', 'recipient');
        }
        if ($recipientId === $viewerId) {
            throw new ApiException(422, 'INVALID_RECIPIENT', 'You cannot send money to yourself.', 'recipient');
        }

        $connection = $this->database->connection();
        $connection->begin_transaction();
        try {
            $sender = $this->database->one(
                'SELECT `wallet` FROM `' . T_USERS . '` WHERE `user_id` = ? FOR UPDATE',
                'i',
                [$viewerId]
            );
            $receiver = $this->database->one(
                'SELECT `wallet` FROM `' . T_USERS . '` WHERE `user_id` = ? FOR UPDATE',
                'i',
                [$recipientId]
            );
            if (!is_array($sender) || !is_array($receiver)) {
                throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
            }
            $senderWallet = (float)($sender['wallet'] ?? 0);
            if ($senderWallet < $amount) {
                throw new ApiException(422, 'INSUFFICIENT_WALLET', 'The amount exceeded your current wallet.', 'amount');
            }
            $this->database->execute(
                'UPDATE `' . T_USERS . '` SET `wallet` = ? WHERE `user_id` = ?',
                'di',
                [$senderWallet - $amount, $viewerId]
            );
            $this->database->execute(
                'UPDATE `' . T_USERS . '` SET `wallet` = `wallet` + ? WHERE `user_id` = ?',
                'di',
                [$amount, $recipientId]
            );
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollback();
            throw $error;
        }

        if (function_exists('cache')) {
            \cache($viewerId, 'users', 'delete');
            \cache($recipientId, 'users', 'delete');
        }
        global $wo;
        $currencyCode = SystemCurrency::code();
        $currency = SystemCurrency::symbol($currencyCode);
        \Wo_RegisterNotification([
            'recipient_id' => $recipientId,
            'type' => 'sent_u_money',
            'user_id' => $viewerId,
            'text' => 'sent you ' . $amount . $currency . '!',
            'url' => 'index.php?link1=wallet',
        ]);
        return $this->earningsPayload($viewerId);
    }

    public function changePassword(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_password_change', 8, 3600);
        $current = (string)($body['current_password'] ?? '');
        $password = (string)($body['new_password'] ?? '');
        $repeat = (string)($body['repeat_password'] ?? '');
        $user = \Wo_UserData($viewerId);
        if (!is_array($user) || !\Wo_Login((string)$user['username'], $current)) {
            throw new ApiException(422, 'CURRENT_PASSWORD_INVALID', 'The current password is incorrect.', 'current_password');
        }
        if (strlen($password) < 6 || strlen($password) > 4096) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Password must contain at least 6 characters.', 'new_password');
        }
        if (!hash_equals($password, $repeat)) {
            throw new ApiException(422, 'PASSWORDS_DO_NOT_MATCH', 'Your passwords do not match.', 'repeat_password');
        }
        if (!\Wo_ResetPassword($viewerId, $password)) {
            throw new ApiException(422, 'PASSWORD_UPDATE_FAILED', 'The password could not be updated.');
        }
        return ['updated' => true];
    }

    public function twoFactor(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_two_factor', 10, 3600);
        $action = strtolower(trim((string)($body['action'] ?? '')));
        $user = \Wo_UserData($viewerId);
        if (!is_array($user)) throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        if ($action === 'disable') {
            \Wo_UpdateUserData($viewerId, ['two_factor' => '0', 'two_factor_verified' => '0']);
            return ['enabled' => false, 'confirmation_required' => false];
        }
        if ($action === 'enable') {
            $email = (string)($user['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ApiException(422, 'EMAIL_REQUIRED', 'Add a valid email address before enabling two-factor authentication.');
            }
            $code = (string)random_int(111111, 999999);
            $sent = \Wo_SendMessage([
                'from_email' => (string)($GLOBALS['wo']['config']['siteEmail'] ?? ''),
                'from_name' => (string)($GLOBALS['wo']['config']['siteName'] ?? ''),
                'to_email' => $email, 'to_name' => (string)($user['name'] ?? $user['username']),
                'subject' => 'Please verify that it is you', 'charSet' => 'utf-8',
                'message_body' => 'Your confirmation code is: ' . $code, 'is_html' => true,
            ]);
            if (!$sent) throw new ApiException(502, 'CONFIRMATION_SEND_FAILED', 'The confirmation code could not be sent.');
            \Wo_UpdateUserData($viewerId, ['email_code' => md5($code), 'two_factor' => '0', 'two_factor_verified' => '0']);
            return ['enabled' => false, 'confirmation_required' => true];
        }
        if ($action === 'verify') {
            $code = trim((string)($body['code'] ?? ''));
            if ($code === '' || !hash_equals((string)($user['email_code'] ?? ''), md5($code))) {
                throw new ApiException(422, 'CONFIRMATION_CODE_INVALID', 'The confirmation code is incorrect.', 'code');
            }
            \Wo_UpdateUserData($viewerId, ['two_factor' => '1', 'two_factor_verified' => '1', 'two_factor_method' => 'two_factor']);
            return ['enabled' => true, 'confirmation_required' => false];
        }
        throw new ApiException(422, 'VALIDATION_FAILED', 'Choose enable, verify or disable.', 'action');
    }

    public function blockedUsers(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_blocked_users', 60, 60);
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT blocked FROM ' . T_BLOCKS . ' WHERE blocker=? ORDER BY id DESC LIMIT 100');
        $stmt->bind_param('i', $viewerId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $items = [];
        foreach ($rows as $row) {
            $user = \Wo_UserData((int)$row['blocked']);
            if (!is_array($user)) continue;
            $items[] = ['id'=>(int)$user['user_id'],'username'=>(string)$user['username'],
                'name'=>$this->displayName($user),'avatar'=>$this->mediaUrl((string)($user['avatar'] ?? '')),
                'about'=>$this->plainText((string)($user['about'] ?? '')),'verified'=>(int)($user['verified'] ?? 0) === 1];
        }
        return $items;
    }

    public function unblockUser(string $accessToken, string $clientId, int $userId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_unblock', 30, 60);
        if ($userId < 1 || !\Wo_IsBlocked($userId)) throw new ApiException(404, 'BLOCK_NOT_FOUND', 'This member is not blocked.');
        if (!\Wo_RemoveBlock($userId)) throw new ApiException(422, 'UNBLOCK_FAILED', 'The member could not be unblocked.');
        return ['unblocked' => true, 'user_id' => $userId];
    }

    public function deleteAccount(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_account_delete', 3, 86400);
        if (($body['confirmed'] ?? false) !== true) throw new ApiException(422, 'CONFIRMATION_REQUIRED', 'Confirm that you want to delete this account.', 'confirmed');
        $password = (string)($body['password'] ?? '');
        $user = \Wo_UserData($viewerId);
        if (!is_array($user) || !\Wo_Login((string)$user['username'], $password)) {
            throw new ApiException(422, 'CURRENT_PASSWORD_INVALID', 'Please confirm your password.', 'password');
        }
        if (!function_exists('Wo_DeleteUser') || !\Wo_DeleteUser($viewerId)) {
            throw new ApiException(422, 'ACCOUNT_DELETE_FAILED', 'The account could not be deleted.');
        }
        return ['deleted' => true];
    }

    /** Xamarin VerificationActivity contract: name, message, personal photo and ID. */
    public function requestVerification(
        string $accessToken,
        string $clientId,
        string $name,
        string $message,
        ?array $photo,
        ?array $passport
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_verification_request', 3, 86400);
        $name = trim($this->plainText($name));
        $message = trim($this->plainText($message));
        if (mb_strlen($name) < 5 || mb_strlen($name) > 50) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Name must be between 5 and 50 characters.', 'name');
        }
        if ($message === '' || mb_strlen($message) > 1000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a verification message.', 'message');
        }
        $files = ['photo' => $photo, 'passport' => $passport];
        foreach ($files as $field => $file) {
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Choose both verification images.', $field);
            }
            $temporary = (string)($file['tmp_name'] ?? '');
            $size = (int)($file['size'] ?? 0);
            if ($temporary === '' || !is_uploaded_file($temporary) || $size < 1 || $size > 10 * 1024 * 1024) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'The selected verification image is invalid.', $field);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/bmp'], true)) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Verification files must be images.', $field);
            }
        }
        if (!function_exists('Wo_SendVerificationRequest') || !function_exists('Wo_UpdateVerificationRequest')) {
            throw new ApiException(503, 'VERIFICATION_UNAVAILABLE', 'Verification requests are currently unavailable.');
        }
        $requestId = \Wo_SendVerificationRequest([
            'user_id' => $viewerId,
            'message' => \Wo_Secure($message),
            'user_name' => \Wo_Secure($name),
            'passport' => '',
            'photo' => '',
            'type' => 'User',
            'seen' => 0,
        ]);
        if (!$requestId || !is_numeric($requestId)) {
            throw new ApiException(422, 'VERIFICATION_REQUEST_FAILED', 'The verification request could not be created.');
        }
        $stored = [];
        foreach ($files as $field => $file) {
            $shared = \Wo_ShareFile([
                'file' => (string)$file['tmp_name'],
                'name' => basename((string)($file['name'] ?? $field . '.jpg')),
                'size' => (int)$file['size'],
                'type' => (string)($file['type'] ?? 'image/jpeg'),
                'types' => 'jpg,jpeg,png,bmp,gif',
            ]);
            if (!is_array($shared) || empty($shared['filename'])) {
                throw new ApiException(500, 'UPLOAD_FAILED', 'A verification image could not be uploaded.', $field);
            }
            $stored[$field] = (string)$shared['filename'];
        }
        if (!\Wo_UpdateVerificationRequest((int)$requestId, $stored)) {
            throw new ApiException(500, 'VERIFICATION_REQUEST_FAILED', 'The verification request could not be saved.');
        }
        return ['submitted' => true, 'request_id' => (int)$requestId];
    }

    /** Xamarin profile overflow actions (block, report/cancel and poke). */
    public function profileAction(
        string $accessToken,
        string $clientId,
        int $userId,
        string $action,
        string $text = ''
    ): array {
        global $sqlConnect;
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_profile_action', 30, 60);
        if ($userId < 1 || $userId === $viewerId || !is_array(\Wo_UserData($userId))) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid member.');
        }
        $action = strtolower(trim($action));
        if ($action === 'block') {
            if (!\Wo_IsBlocked($userId) && !\Wo_RegisterBlock($userId)) {
                throw new ApiException(422, 'PROFILE_ACTION_FAILED', 'The member could not be blocked.');
            }
            return ['blocked' => true];
        }
        if ($action === 'report' || $action === 'cancel_report') {
            $reported = function_exists('Wo_IsReportExists') && (bool) \Wo_IsReportExists($userId, 'user');
            $wanted = $action === 'report';
            if ($reported !== $wanted) {
                \Wo_ReportUser($userId, $wanted ? $this->plainText($text) : '');
            }
            return ['reported' => $wanted];
        }
        if ($action === 'poke') {
            if (!function_exists('Wo_IsPoked') || !\Wo_IsPoked($userId, $viewerId)) {
                $target = (int) $userId;
                $sender = (int) $viewerId;
                // Xamarin's v2 create-poke contract consumes a reverse
                // incoming poke before creating this outgoing one.
                if (function_exists('Wo_IsPoked') && \Wo_IsPoked($viewerId, $userId)) {
                    $cleanup = $sqlConnect->prepare(
                        'DELETE FROM ' . T_POKES
                        . ' WHERE (received_user_id=? AND send_user_id=?)'
                        . ' OR (received_user_id=? AND send_user_id=?)'
                    );
                    $cleanup->bind_param('iiii', $sender, $target, $target, $sender);
                    $cleanup->execute();
                    $cleanup->close();
                }
                $ok = mysqli_query(
                    $sqlConnect,
                    'INSERT INTO ' . T_POKES . " (`received_user_id`,`send_user_id`) VALUES ({$target},{$sender})"
                );
                if (!$ok) {
                    throw new ApiException(422, 'PROFILE_ACTION_FAILED', 'The poke could not be sent.');
                }
                if (function_exists('Wo_RegisterNotification')) {
                    \Wo_RegisterNotification([
                        'recipient_id' => $target,
                        'post_id' => $sender,
                        'type' => 'poke',
                        'text' => '',
                        'type2' => 'poke',
                        'url' => 'index.php?link1=poke',
                    ]);
                }
            }
            return ['poked' => true];
        }
        throw new ApiException(422, 'VALIDATION_FAILED', 'Unsupported profile action.', 'action');
    }

    public function userPosts(
        string $accessToken,
        string $clientId,
        int $userId,
        ?string $cursor,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_user_posts', 120, 60);
        if ($userId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid user id is required.', 'id');
        }
        $limit = max(1, min(20, $limit));
        $this->feed->bootstrapWebContext($viewerId);
        $afterPostId = $this->decodeCursor($cursor, $viewerId, 'user_posts:' . $userId);

        $raw = \Wo_GetPosts([
            'limit' => $limit,
            'publisher_id' => $userId,
            'after_post_id' => $afterPostId,
            'placement' => 'multi_image_post',
        ]);
        if (!is_array($raw)) {
            $raw = [];
        }
        $items = [];
        foreach ($raw as $post) {
            if (!is_array($post)) {
                continue;
            }
            $item = $this->feed->present($post, $viewerId);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        $lastId = empty($items) ? 0 : (int) $items[array_key_last($items)]['id'];
        return [
            'items' => $items,
            'next_cursor' => count($items) >= $limit && $lastId > 0
                ? $this->encodeCursor($viewerId, $lastId, 'user_posts:' . $userId)
                : null,
        ];
    }

    // ---- Notifications ------------------------------------------------------

    public function notifications(
        string $accessToken,
        string $clientId,
        ?string $cursor,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_notifications', 120, 60);
        $limit = max(1, min(30, $limit));
        $offset = $this->decodeCursor($cursor, $viewerId, 'notifications');

        $params = ['account_id' => $viewerId, 'limit' => $limit];
        if ($offset > 0) {
            $params['offset'] = $offset;
        }
        $rows = function_exists('Wo_GetNotifications') ? \Wo_GetNotifications($params) : [];
        if (!is_array($rows)) {
            $rows = [];
        }
        $preferences = $this->notificationPreferences($viewerId);
        $items = [];
        $cursorId = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cursorId = (int) ($row['id'] ?? $cursorId);
            $type = (string) ($row['type'] ?? '');
            if (($preferences[$type]['disabled'] ?? false) === true) {
                continue;
            }
            $actor = is_array($row['notifier'] ?? null) ? $row['notifier'] : [];
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'type' => $type,
                'type2' => (string) ($row['type2'] ?? ''),
                'text' => $this->plainText((string) ($row['text'] ?? '')),
                'created_at' => (int) ($row['time'] ?? 0),
                'seen' => (int) ($row['seen'] ?? 0) === 1,
                'post_id' => (int) ($row['post_id'] ?? 0),
                'comment_id' => (int) ($row['comment_id'] ?? 0),
                'reply_id' => (int) ($row['reply_id'] ?? 0),
                'page_id' => (int) ($row['page_id'] ?? 0),
                'group_id' => (int) ($row['group_id'] ?? 0),
                'event_id' => (int) ($row['event_id'] ?? 0),
                'thread_id' => (int) ($row['thread_id'] ?? 0),
                'blog_id' => (int) ($row['blog_id'] ?? 0),
                'story_id' => (int) ($row['story_id'] ?? 0),
                'recipient_id' => $viewerId,
                'preference_weight' => (int) ($preferences[$type]['weight'] ?? 0),
                'url' => (string) ($row['url'] ?? ''),
                'full_link' => (string) ($row['full_link'] ?? ''),
                'actor' => [
                    'id' => (int) ($actor['user_id'] ?? $row['notifier_id'] ?? 0),
                    'username' => (string) ($actor['username'] ?? ''),
                    'name' => $this->displayName($actor),
                    'avatar' => $this->mediaUrl((string) ($actor['avatar'] ?? '')),
                    'verified' => (int) ($actor['verified'] ?? 0) === 1,
                ],
            ];
        }
        usort($items, static function (array $left, array $right): int {
            $weight = ((int) $right['preference_weight']) <=> ((int) $left['preference_weight']);
            return $weight !== 0 ? $weight : (((int) $right['created_at']) <=> ((int) $left['created_at']));
        });
        return [
            'items' => $items,
            'next_cursor' => count($rows) >= $limit && $cursorId > 0
                ? $this->encodeCursor($viewerId, $cursorId, 'notifications')
                : null,
        ];
    }

    public function markNotificationSeen(
        string $accessToken,
        string $clientId,
        int $notificationId
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_notification_seen', 180, 60);
        if ($notificationId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid notification is required.');
        }
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('UPDATE ' . T_NOTIFICATION . ' SET seen=1, seen_pop=1 WHERE id=? AND recipient_id=?');
        if (!$stmt) {
            throw new ApiException(500, 'NOTIFICATION_UPDATE_FAILED', 'Notification could not be updated.');
        }
        $stmt->bind_param('ii', $notificationId, $viewerId);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();
        return ['seen' => true, 'updated' => $updated, 'id' => $notificationId];
    }

    public function updateNotification(
        string $accessToken,
        string $clientId,
        int $notificationId,
        string $action
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_notification_action', 120, 60);
        if ($notificationId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid notification is required.');
        }
        $allowed = ['show_more', 'show_less', 'delete', 'disable_type', 'report'];
        if (!in_array($action, $allowed, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'This notification action is not supported.', 'action');
        }

        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT type FROM ' . T_NOTIFICATION . ' WHERE id=? AND recipient_id=? LIMIT 1');
        if (!$stmt) {
            throw new ApiException(500, 'NOTIFICATION_LOOKUP_FAILED', 'Notification could not be checked.');
        }
        $stmt->bind_param('ii', $notificationId, $viewerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            throw new ApiException(404, 'NOTIFICATION_NOT_FOUND', 'Notification was not found.');
        }
        $type = trim((string) ($row['type'] ?? ''));

        if ($action === 'delete') {
            $delete = $sqlConnect->prepare('DELETE FROM ' . T_NOTIFICATION . ' WHERE id=? AND recipient_id=?');
            if (!$delete) {
                throw new ApiException(500, 'NOTIFICATION_DELETE_FAILED', 'Notification could not be deleted.');
            }
            $delete->bind_param('ii', $notificationId, $viewerId);
            $delete->execute();
            $deleted = $delete->affected_rows > 0;
            $delete->close();
            return ['id' => $notificationId, 'action' => $action, 'deleted' => $deleted];
        }

        if ($action === 'report') {
            $reportTable = $this->ensureNotificationReportTable();
            $report = $sqlConnect->prepare(
                'INSERT INTO `' . $reportTable . '` (user_id, notification_id, notification_type, created_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE created_at=VALUES(created_at)'
            );
            if (!$report) {
                throw new ApiException(500, 'NOTIFICATION_REPORT_FAILED', 'Notification issue could not be reported.');
            }
            $now = time();
            $report->bind_param('iisi', $viewerId, $notificationId, $type, $now);
            $report->execute();
            $report->close();
            return [
                'id' => $notificationId,
                'type' => $type,
                'action' => $action,
                'reported' => true,
            ];
        }

        $table = $this->ensureNotificationPreferenceTable();
        $weight = $action === 'show_more' ? 1 : ($action === 'show_less' ? -1 : 0);
        $disabled = $action === 'disable_type' ? 1 : 0;
        $reported = 0;
        $query = 'INSERT INTO `' . $table . '` (user_id, notification_type, weight, disabled, reported_count, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    weight = CASE WHEN VALUES(weight) = 0 THEN weight ELSE GREATEST(-5, LEAST(5, weight + VALUES(weight))) END,
                    disabled = GREATEST(disabled, VALUES(disabled)),
                    reported_count = reported_count + VALUES(reported_count),
                    updated_at = VALUES(updated_at)';
        $update = $sqlConnect->prepare($query);
        if (!$update) {
            throw new ApiException(500, 'NOTIFICATION_PREFERENCE_FAILED', 'Notification preference could not be updated.');
        }
        $now = time();
        $update->bind_param('isiiii', $viewerId, $type, $weight, $disabled, $reported, $now);
        $update->execute();
        $update->close();
        return [
            'id' => $notificationId,
            'type' => $type,
            'action' => $action,
            'disabled' => $disabled === 1,
            'reported' => $reported === 1,
        ];
    }

    private function ensureNotificationPreferenceTable(): string
    {
        global $sqlConnect;
        $table = T_NOTIFICATION . '_mobile_preferences';
        $sqlConnect->query(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` (
                `user_id` int(11) NOT NULL,
                `notification_type` varchar(255) NOT NULL,
                `weight` tinyint(4) NOT NULL DEFAULT 0,
                `disabled` tinyint(1) NOT NULL DEFAULT 0,
                `reported_count` int(11) NOT NULL DEFAULT 0,
                `updated_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`user_id`, `notification_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        return $table;
    }

    private function ensureNotificationReportTable(): string
    {
        global $sqlConnect;
        $table = T_NOTIFICATION . '_mobile_reports';
        $sqlConnect->query(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` (
                `user_id` int(11) NOT NULL,
                `notification_id` int(11) NOT NULL,
                `notification_type` varchar(255) NOT NULL,
                `created_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`user_id`, `notification_id`),
                KEY `notification_type` (`notification_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        return $table;
    }

    private function notificationPreferences(int $viewerId): array
    {
        global $sqlConnect;
        $table = $this->ensureNotificationPreferenceTable();
        $stmt = $sqlConnect->prepare(
            'SELECT notification_type, weight, disabled FROM `' . $table . '` WHERE user_id=?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $viewerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $preferences = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $preferences[(string) $row['notification_type']] = [
                'weight' => (int) $row['weight'],
                'disabled' => (int) $row['disabled'] === 1,
            ];
        }
        $stmt->close();
        return $preferences;
    }

    public function friendsBirthdays(string $accessToken, string $clientId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_friends_birthdays', 60, 60);
        $rows = function_exists('Wo_CheckBirthdays') ? \Wo_CheckBirthdays() : [];
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $user = is_array($row['user_data'] ?? null)
                ? $row['user_data']
                : (is_array($row) ? $row : []);
            if (empty($user['user_id'])) {
                continue;
            }
            $items[] = [
                'id' => (int)$user['user_id'],
                'username' => (string)($user['username'] ?? ''),
                'name' => $this->displayName($user),
                'avatar' => $this->mediaUrl((string)($user['avatar'] ?? '')),
                'verified' => (int)($user['verified'] ?? 0) === 1,
                'birthday' => (string)($user['birthday'] ?? ''),
            ];
        }
        return $items;
    }

    public function registerPushDevice(
        string $accessToken,
        string $clientId,
        array $body
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_push_device', 60, 60);
        $platform = strtolower(trim((string)($body['platform'] ?? '')));
        $subscriptionId = trim((string)($body['subscription_id'] ?? ''));
        $token = trim((string)($body['token'] ?? ''));
        if (!in_array($platform, ['android', 'ios'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid push platform is required.', 'platform');
        }
        $deviceId = $subscriptionId !== '' ? $subscriptionId : $token;
        if ($deviceId === '' || strlen($deviceId) > 255) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid push subscription is required.', 'subscription_id');
        }
        // The main app notification sender reads the native device columns.
        // Keep the messenger column in sync as well so chat pushes continue to
        // work for installs that use the same OneSignal subscription.
        $fields = $platform === 'ios'
            ? ['ios_n_device_id' => $deviceId, 'ios_m_device_id' => $deviceId]
            : ['android_n_device_id' => $deviceId, 'android_m_device_id' => $deviceId];
        if (!\Wo_UpdateUserData($viewerId, $fields)) {
            throw new ApiException(500, 'PUSH_DEVICE_UPDATE_FAILED', 'Push registration could not be saved.');
        }
        return [
            'registered' => true,
            'platform' => $platform,
            'subscription_id' => $subscriptionId,
        ];
    }

    // ---- Search + profile edit ---------------------------------------------

    public function searchUsers(string $accessToken, string $clientId, string $query, int $limit, array $filters = []): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_search', 60, 60);
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        global $sqlConnect;
        $limit = max(1, min(30, $limit));
        $safe = mysqli_real_escape_string($sqlConnect, $query);
        $where = ["(username LIKE '%{$safe}%' OR first_name LIKE '%{$safe}%' OR last_name LIKE '%{$safe}%')", "active = '1'", "banned = '0'"];
        $gender = (string)($filters['gender'] ?? 'all');
        if (in_array($gender, ['male', 'female'], true)) $where[] = "gender='" . $gender . "'";
        $country = (int)($filters['country'] ?? 0);
        if ($country > 0) $where[] = 'country_id=' . $country;
        if (($filters['verified'] ?? '') === '1') $where[] = "verified='1'";
        if (($filters['status'] ?? '') === '1') $where[] = "status='1'";
        if (($filters['image'] ?? '') === '1') $where[] = "avatar NOT LIKE '%d-avatar.jpg%'";
        $ageMin = max(0, min(120, (int)($filters['age_from'] ?? 0)));
        $ageMax = max($ageMin, min(120, (int)($filters['age_to'] ?? 0)));
        if (($filters['filterbyage'] ?? '') === '1' && $ageMax > 0) {
            $yearNow = (int)date('Y');
            $where[] = "birthday <> '0000-00-00' AND CAST(SUBSTRING(birthday,1,4) AS UNSIGNED) BETWEEN " . ($yearNow - $ageMax) . ' AND ' . ($yearNow - $ageMin);
        }
        $result = mysqli_query($sqlConnect, 'SELECT user_id FROM ' . T_USERS . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY user_id DESC LIMIT ' . $limit);
        $rows = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) $rows[] = (int)$row['user_id'];
        if (!is_array($rows)) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $row) {
            $user = is_array($row) ? $row : (is_numeric($row) ? \Wo_UserData((int) $row) : null);
            if (!is_array($user) || empty($user['user_id'])) {
                continue;
            }
            $items[] = [
                'id' => (int) $user['user_id'],
                'username' => (string) ($user['username'] ?? ''),
                'name' => $this->displayName($user),
                'avatar' => $this->mediaUrl((string) ($user['avatar'] ?? '')),
                'verified' => (int) ($user['verified'] ?? 0) === 1,
            ];
        }
        return $items;
    }

    public function searchPosts(string $accessToken, string $clientId, string $query, int $afterId, int $limit, int $groupId = 0): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_post_search', 120, 60);
        global $sqlConnect;
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 100) return ['items' => [], 'next_after' => 0];
        $limit = max(1, min(20, $limit));
        $safe = mysqli_real_escape_string($sqlConnect, $query);
        $after = $afterId > 0 ? ' AND id < ' . (int)$afterId : '';
        $group = $groupId > 0 ? ' AND group_id = ' . (int)$groupId : '';
        if ($groupId > 0) {
            $rawGroup = \Wo_GroupData($groupId);
            if (!is_array($rawGroup)) throw new ApiException(404, 'GROUP_NOT_FOUND', 'This group is not available.');
            if ((int)($rawGroup['privacy'] ?? 1) !== 1 && !\Wo_IsGroupOnwer($groupId) && !\Wo_IsGroupJoined($groupId, $viewerId)) {
                throw new ApiException(403, 'GROUP_PRIVATE', 'Join this group to search its posts.');
            }
        }
        $result = mysqli_query($sqlConnect, "SELECT id FROM " . T_POSTS . " WHERE postText LIKE '%{$safe}%'{$group}{$after} ORDER BY id DESC LIMIT {$limit}");
        $items = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $raw = \Wo_PostData((int)$row['id']);
            $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
            if ($item !== null) $items[] = $item;
        }
        $lastId = empty($items) ? 0 : (int)$items[array_key_last($items)]['id'];
        return ['items' => $items, 'next_after' => count($items) >= $limit ? $lastId : 0];
    }

    public function hashtagPosts(string $accessToken, string $clientId, string $tag, int $afterId, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_hashtag_posts', 120, 60);
        $tag = ltrim(trim($tag), '#');
        if ($tag === '' || mb_strlen($tag) > 100) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid hashtag is required.', 'tag');
        }
        $limit = max(1, min(20, $limit));
        $rows = function_exists('Wo_GetHashtagPosts') ? \Wo_GetHashtagPosts($tag, max(0, $afterId), $limit) : [];
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) continue;
            $item = $this->feed->present($row, $viewerId);
            if ($item !== null) $items[] = $item;
        }
        $lastId = empty($items) ? 0 : (int)$items[array_key_last($items)]['id'];
        return ['items' => $items, 'next_after' => count($items) >= $limit ? $lastId : 0];
    }

    public function searchCommunities(string $accessToken, string $clientId, string $query, int $limit): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_community_search', 120, 60);
        global $sqlConnect;
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 100) return ['pages' => [], 'groups' => []];
        $limit = max(1, min(30, $limit));
        $like = '%' . $query . '%';
        $fetch = static function (string $sql, string $like, int $limit) use ($sqlConnect): array {
            $stmt = mysqli_prepare($sqlConnect, $sql);
            if (!$stmt) return [];
            mysqli_stmt_bind_param($stmt, 'ssi', $like, $like, $limit);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $rows = [];
            while ($result && ($row = mysqli_fetch_assoc($result))) $rows[] = $row;
            mysqli_stmt_close($stmt);
            return $rows;
        };
        $pages = $fetch('SELECT page_id AS id, page_name AS username, page_title AS name, avatar FROM ' . T_PAGES . ' WHERE page_name LIKE ? OR page_title LIKE ? ORDER BY page_id DESC LIMIT ?', $like, $limit);
        $groups = $fetch('SELECT id, group_name AS username, group_title AS name, avatar FROM ' . T_GROUPS . ' WHERE group_name LIKE ? OR group_title LIKE ? ORDER BY id DESC LIMIT ?', $like, $limit);
        foreach ($pages as &$item) $item['avatar'] = $this->mediaUrl((string)($item['avatar'] ?? ''));
        foreach ($groups as &$item) $item['avatar'] = $this->mediaUrl((string)($item['avatar'] ?? ''));
        return ['pages' => $pages, 'groups' => $groups];
    }

    public function updateProfile(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_profile_update', 20, 3600);
        $update = [];
        foreach ([
            'first_name' => 60,
            'last_name' => 60,
            'about' => 500,
            'address' => 255,
            'phone_number' => 32,
            'website' => 255,
            'working' => 150,
            'school' => 150,
            'facebook' => 255,
            'twitter' => 255,
            'google' => 255,
            'vk' => 255,
            'linkedin' => 255,
            'instagram' => 255,
            'youtube' => 255,
        ] as $field => $max) {
            if (array_key_exists($field, $body)) {
                $value = trim((string) $body[$field]);
                if (mb_strlen($value) > $max) {
                    throw new ApiException(422, 'VALIDATION_FAILED', 'This value is too long.', $field);
                }
                $update[$field] = \Wo_Secure($value);
            }
        }
        if (array_key_exists('relationship', $body)) {
            $relationship = filter_var($body['relationship'], FILTER_VALIDATE_INT);
            if ($relationship === false || $relationship < 0 || $relationship > 4) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid relationship status.', 'relationship');
            }
            $update['relationship_id'] = (string) $relationship;
        }
        if (empty($update)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Nothing to update.');
        }
        \Wo_UpdateUserData($viewerId, $update);
        $user = \Wo_UserData($viewerId);
        return $this->presentProfile($user, $viewerId);
    }

    public function updateProfileMedia(string $accessToken, string $clientId, string $type, ?array $file): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_profile_media', 12, 3600);
        $type = strtolower(trim($type));
        if (!in_array($type, ['avatar', 'cover'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose avatar or cover.', 'type');
        }
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose an image to upload.', 'image');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 10 * 1024 * 1024) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'The image must be smaller than 10 MB.', 'image');
        }
        $temporary = (string) ($file['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The uploaded image is invalid.', 'image');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Only JPG, PNG, WebP or GIF images are allowed.', 'image');
        }
        $uploaded = \Wo_UploadImage(
            $temporary,
            basename((string) ($file['name'] ?? 'profile.jpg')),
            $type,
            $mime,
            $viewerId
        );
        if ($uploaded !== true) {
            throw new ApiException(500, 'UPLOAD_FAILED', 'The image could not be uploaded.');
        }
        return $this->presentProfile(\Wo_UserData($viewerId), $viewerId);
    }

    public function resetProfileAvatar(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_profile_media', 12, 3600);
        $user = \Wo_UserData($viewerId);
        $avatar = (string) ($user['gender'] ?? '') === 'female'
            ? 'upload/photos/f-avatar.jpg'
            : 'upload/photos/d-avatar.jpg';
        \Wo_UpdateUserData($viewerId, ['avatar' => $avatar]);
        return $this->presentProfile(\Wo_UserData($viewerId), $viewerId);
    }

    // ---- Follow -------------------------------------------------------------

    /** Hashtag autocomplete (`#tag`) from Wo_Hashtags, trending first. */
    /** Top trending hashtags by usage (Wo_Hashtags.trend_use_num). */
    public function trendingHashtags(string $accessToken, string $clientId, int $limit): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_search', 60, 60);
        global $sqlConnect;
        if (!($sqlConnect instanceof \mysqli)) {
            return [];
        }
        $limit = max(1, min(30, $limit));
        $items = [];
        $stmt = $sqlConnect->prepare(
            'SELECT tag, trend_use_num FROM Wo_Hashtags ORDER BY trend_use_num DESC LIMIT ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $items[] = ['tag' => (string) $row['tag'], 'count' => (int) $row['trend_use_num']];
        }
        $stmt->close();
        return $items;
    }

    /** Paid members shown by Xamarin's Trending ProUsersAdapter. */
    public function trendingProUsers(string $accessToken, string $clientId, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_trending', 60, 60);
        global $sqlConnect;
        if (!($sqlConnect instanceof \mysqli)) {
            return [];
        }
        $limit = max(1, min(30, $limit));
        $items = [];
        $stmt = $sqlConnect->prepare(
            "SELECT user_id FROM Wo_Users WHERE user_id <> ? AND active = '1' " .
            "AND is_pro = '1' ORDER BY pro_time DESC, user_id DESC LIMIT ?"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $viewerId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $user = \Wo_UserData((int) $row['user_id']);
            if (!is_array($user) || empty($user['user_id'])) {
                continue;
            }
            $items[] = [
                'id' => (int) $user['user_id'],
                'username' => (string) ($user['username'] ?? ''),
                'name' => $this->displayName($user),
                'avatar' => $this->mediaUrl((string) ($user['avatar'] ?? '')),
                'verified' => (int) ($user['verified'] ?? 0) === 1,
                'is_pro' => true,
                'pro_type' => (string) ($user['pro_type'] ?? '1'),
            ];
        }
        $stmt->close();
        return $items;
    }

    /** Remaining Xamarin Trending modules, composed server-side. */
    public function trendingModules(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_trending', 30, 60);
        global $sqlConnect;
        if (!($sqlConnect instanceof \mysqli)) return [];
        $out = ['promoted_pages' => [], 'shortcuts' => [], 'articles' => [], 'weather' => null,
            'currency' => null, 'admob' => ['enabled' => true, 'banner_unit_id' => 'ca-app-pub-1087068317191585/3391566198']];

        $res = $sqlConnect->query("SELECT page_id,page_name,page_title,avatar FROM Wo_Pages WHERE boosted='1' AND active='1' ORDER BY page_id DESC LIMIT 12");
        while ($res && ($r = $res->fetch_assoc())) $out['promoted_pages'][] = ['id'=>(int)$r['page_id'],'name'=>(string)($r['page_title'] ?: $r['page_name']),'avatar'=>$this->mediaUrl((string)$r['avatar'])];

        $stmt = $sqlConnect->prepare("SELECT page_id,page_name,page_title,avatar FROM Wo_Pages WHERE user_id=? ORDER BY page_id DESC LIMIT 10");
        if ($stmt) { $stmt->bind_param('i',$viewerId); $stmt->execute(); $res=$stmt->get_result(); while($res&&($r=$res->fetch_assoc())) $out['shortcuts'][]=['id'=>(int)$r['page_id'],'type'=>'Page','name'=>(string)($r['page_title']?:$r['page_name']),'avatar'=>$this->mediaUrl((string)$r['avatar'])]; $stmt->close(); }
        $stmt = $sqlConnect->prepare("SELECT id,group_name,group_title,avatar FROM Wo_Groups WHERE user_id=? ORDER BY id DESC LIMIT 10");
        if ($stmt) { $stmt->bind_param('i',$viewerId); $stmt->execute(); $res=$stmt->get_result(); while($res&&($r=$res->fetch_assoc())) $out['shortcuts'][]=['id'=>(int)$r['id'],'type'=>'Group','name'=>(string)($r['group_title']?:$r['group_name']),'avatar'=>$this->mediaUrl((string)$r['avatar'])]; $stmt->close(); }

        $res=$sqlConnect->query("SELECT id,title,category,thumbnail,posted FROM Wo_Blog WHERE active='1' AND posted>0 ORDER BY id DESC LIMIT 3");
        while($res&&($r=$res->fetch_assoc())) $out['articles'][]=['id'=>(int)$r['id'],'title'=>html_entity_decode((string)$r['title'],ENT_QUOTES|ENT_HTML5,'UTF-8'),'category'=>(string)$r['category'],'thumbnail'=>$this->mediaUrl((string)$r['thumbnail']),'posted'=>(int)$r['posted']];

        $stmt=$sqlConnect->prepare("SELECT city FROM Wo_Users WHERE user_id=? LIMIT 1"); $city='';
        if($stmt){$stmt->bind_param('i',$viewerId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$city=trim((string)($row['city']??''));$stmt->close();}
        if($city!=='') $out['weather']=$this->fetchJson('https://api.weatherapi.com/v1/forecast.json?key=a413d0bf31a44369a16140106221804&q='.rawurlencode($city).'&days=1');
        $out['currency']=$this->fetchJson('https://openexchangerates.org/api/latest.json?app_id=644761ef2ba94ea5aa84767109d6cf7b&base=USD&symbols=EUR,GBP,TRY');
        return $out;
    }

    private function fetchJson(string $url): ?array
    {
        $context=stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $raw=@file_get_contents($url,false,$context);
        if(!is_string($raw)||$raw==='') return null;
        $data=json_decode($raw,true);
        return is_array($data)?$data:null;
    }

    public function searchHashtags(string $accessToken, string $clientId, string $query, int $limit): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_search', 60, 60);
        $query = ltrim(trim($query), '#');
        if ($query === '') {
            return [];
        }
        global $sqlConnect;
        if (!($sqlConnect instanceof \mysqli)) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $like = '%' . $query . '%';
        $prefix = $query . '%';
        $items = [];
        $stmt = $sqlConnect->prepare(
            'SELECT tag, trend_use_num FROM Wo_Hashtags WHERE tag LIKE ? ' .
            'ORDER BY (tag LIKE ?) DESC, trend_use_num DESC LIMIT ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ssi', $like, $prefix, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $items[] = ['tag' => (string) $row['tag'], 'count' => (int) $row['trend_use_num']];
        }
        $stmt->close();
        return $items;
    }

    /**
     * People-to-follow for the post-registration setup step: newest active
     * members excluding the viewer. Serialized like searchUsers().
     */
    public function suggestedUsers(string $accessToken, string $clientId, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_suggested', 60, 60);
        $limit = max(1, min(30, $limit));
        global $sqlConnect, $wo;
        if (!($sqlConnect instanceof \mysqli)) {
            return [];
        }
        $items = [];
        $friendSystem = (int)($wo['config']['connectivitySystem'] ?? 0) === 1;
        $relationshipFilter = $friendSystem
            ? ' AND user_id NOT IN (SELECT following_id FROM ' . T_FOLLOWERS . ' WHERE follower_id = ?)' .
              ' AND user_id NOT IN (SELECT follower_id FROM ' . T_FOLLOWERS . ' WHERE following_id = ?)'
            : ' AND user_id NOT IN (SELECT following_id FROM ' . T_FOLLOWERS . ' WHERE follower_id = ?)';
        $stmt = $sqlConnect->prepare(
            "SELECT user_id FROM " . T_USERS . " WHERE user_id <> ? AND active = '1'" .
            $relationshipFilter . " ORDER BY user_id DESC LIMIT ?"
        );
        if ($stmt === false) {
            return [];
        }
        if ($friendSystem) {
            $stmt->bind_param('iiii', $viewerId, $viewerId, $viewerId, $limit);
        } else {
            $stmt->bind_param('iii', $viewerId, $viewerId, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $user = \Wo_UserData((int) $row['user_id']);
            if (!is_array($user) || empty($user['user_id'])) {
                continue;
            }
            $items[] = [
                'id' => (int) $user['user_id'],
                'username' => (string) ($user['username'] ?? ''),
                'name' => $this->displayName($user),
                'avatar' => $this->mediaUrl((string) ($user['avatar'] ?? '')),
                'verified' => (int) ($user['verified'] ?? 0) === 1,
                'is_own' => false,
                'relation' => 'none',
            ];
        }
        $stmt->close();
        return $items;
    }

    /**
     * Xamarin PeopleNearByActivity / "Find Friends" data source.
     * Wo_GetNearbyUsers applies the server's distance, gender, status and
     * relationship filters against the viewer's saved coordinates.
     */
    public function nearbyUsers(string $accessToken, string $clientId, int $limit, int $offset, array $filters = []): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_nearby_users', 60, 60);
        $limit = max(1, min(35, $limit));
        $offset = max(0, $offset);
        $options = [
            'limit' => $limit,
            'offset' => $offset,
            'gender' => (string)($filters['gender'] ?? ''),
            'status' => (string)($filters['status'] ?? ''),
            'distance' => (string)($filters['distance'] ?? ''),
            'relship' => (string)($filters['relship'] ?? ''),
            'name' => trim((string)($filters['keyword'] ?? '')),
        ];
        $rows = function_exists('Wo_GetNearbyUsers') ? \Wo_GetNearbyUsers($options) : [];
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $user = is_array($row['user_data'] ?? null)
                ? $row['user_data']
                : (isset($row['user_id']) ? \Wo_UserData((int)$row['user_id']) : null);
            if (!is_array($user) || (int)($user['user_id'] ?? 0) < 1) {
                continue;
            }
            $id = (int)$user['user_id'];
            if ($id === $viewerId) {
                continue;
            }
            $following = function_exists('Wo_IsFollowing') && \Wo_IsFollowing($id, $viewerId);
            $requested = function_exists('Wo_IsFollowRequested') && \Wo_IsFollowRequested($id, $viewerId);
            $lastSeen = (int)($user['lastseen'] ?? 0);
            $items[] = [
                'id' => $id,
                'username' => (string)($user['username'] ?? ''),
                'name' => $this->displayName($user),
                'avatar' => $this->mediaUrl((string)($user['avatar'] ?? '')),
                'verified' => (int)($user['verified'] ?? 0) === 1,
                'is_pro' => (int)($user['is_pro'] ?? 0) === 1,
                'is_own' => false,
                'relation' => $following ? 'following' : ($requested ? 'requested' : 'none'),
                'last_seen_label' => $lastSeen > (time() - 60)
                    ? 'Online'
                    : (function_exists('Wo_Time_Elapsed_String')
                        ? (string)\Wo_Time_Elapsed_String($lastSeen)
                        : ''),
                'distance' => (string)($row['distance'] ?? ''),
            ];
        }
        return $items;
    }

    public function suggestedPages(string $accessToken, string $clientId, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_page_suggested', 60, 60);
        global $sqlConnect;
        if (!($sqlConnect instanceof \mysqli)) return [];
        $limit = max(1, min(12, $limit));
        $sql = 'SELECT page_id FROM ' . T_PAGES . " WHERE active='1' AND user_id<>?" .
            ' AND page_id NOT IN (SELECT page_id FROM ' . T_PAGES_LIKES . ' WHERE user_id=? AND active=\'1\')' .
            ' ORDER BY page_id DESC LIMIT ?';
        $stmt = $sqlConnect->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('iii', $viewerId, $viewerId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $page = \Wo_PageData((int)$row['page_id']);
            if (!is_array($page) || empty($page['page_id'])) continue;
            $items[] = [
                'id' => (int)$page['page_id'],
                'name' => $this->displayName([
                    'name' => (string)($page['page_title'] ?? $page['page_name'] ?? ''),
                ]),
                'avatar' => $this->mediaUrl((string)($page['avatar'] ?? '')),
                'cover' => $this->mediaUrl((string)($page['cover'] ?? '')),
                'likes' => function_exists('Wo_CountPageLikes') ? (int)\Wo_CountPageLikes((int)$page['page_id']) : 0,
            ];
        }
        $stmt->close();
        return $items;
    }

    public function togglePageLike(string $accessToken, string $clientId, int $pageId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_page_like', 60, 60);
        if ($pageId < 1 || !is_array(\Wo_PageData($pageId))) {
            throw new ApiException(404, 'PAGE_NOT_FOUND', 'This page is not available.');
        }
        $liked = function_exists('Wo_IsPageLiked') && \Wo_IsPageLiked($pageId, $viewerId);
        if ($liked) {
            \Wo_DeletePageLike($pageId, $viewerId);
        } else {
            \Wo_RegisterPageLike($pageId, $viewerId);
        }
        return ['liked' => !$liked];
    }

    public function suggestedEvents(string $accessToken, string $clientId, int $limit): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_event_suggested', 60, 60);
        $rows = function_exists('Wo_GetSuggestedEvents')
            ? \Wo_GetSuggestedEvents(['limit' => max(1, min(12, $limit))])
            : [];
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $event) {
            if (!is_array($event)) continue;
            $items[] = [
                'id' => (int)($event['id'] ?? 0),
                'name' => html_entity_decode(strip_tags((string)($event['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'cover' => $this->mediaUrl((string)($event['cover'] ?? '')),
                'start_date' => (string)($event['start_date'] ?? ''),
                'location' => html_entity_decode(strip_tags((string)($event['location'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
        return $items;
    }

    public function toggleEventInterested(string $accessToken, string $clientId, int $eventId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_event_interested', 60, 60);
        if ($eventId < 1) throw new ApiException(422, 'VALIDATION_FAILED', 'A valid event id is required.', 'id');
        $interested = function_exists('Wo_EventInterestedExists') && \Wo_EventInterestedExists($eventId);
        if ($interested) {
            \Wo_UnsetEventInterestedUsers($eventId);
        } else {
            \Wo_AddEventInterestedUsers($eventId);
        }
        return ['interested' => !$interested];
    }

    public function follow(string $accessToken, string $clientId, int $userId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_follow', 60, 60);
        if ($userId < 1 || $userId === $viewerId) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid user id is required.', 'id');
        }
        $target = \Wo_UserData($userId);
        if (!is_array($target) || empty($target['user_id'])) {
            throw new ApiException(404, 'USER_NOT_FOUND', 'This member is not available.');
        }
        // In follow mode this is an immediate follow; in friend mode it creates a
        // pending request (Wo_RegisterFollow stores active=0 when connectivitySystem=1).
        \Wo_RegisterFollow($userId, $viewerId);
        return $this->relation($viewerId, $userId);
    }

    public function unfollow(string $accessToken, string $clientId, int $userId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_follow', 60, 60);
        if ($userId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid user id is required.', 'id');
        }
        // Removes an active follow/friendship...
        \Wo_DeleteFollow($userId, $viewerId);
        // ...and cancels a still-pending outgoing request.
        if (function_exists('Wo_IsFollowRequested') && \Wo_IsFollowRequested($userId, $viewerId)) {
            \Wo_DeleteFollowRequest($viewerId, $userId);
        }
        return $this->relation($viewerId, $userId);
    }

    public function acceptRequest(string $accessToken, string $clientId, int $requesterId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_friend_request', 60, 60);
        if ($requesterId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid user id is required.', 'id');
        }
        // Call-site convention: Wo_AcceptFollowRequest(requester_id, viewer_id).
        \Wo_AcceptFollowRequest($requesterId, $viewerId);
        return $this->relation($viewerId, $requesterId);
    }

    public function declineRequest(string $accessToken, string $clientId, int $requesterId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_friend_request', 60, 60);
        if ($requesterId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid user id is required.', 'id');
        }
        \Wo_DeleteFollowRequest($requesterId, $viewerId);
        return $this->relation($viewerId, $requesterId);
    }

    public function friendRequests(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_friend_requests', 120, 60);
        $rows = function_exists('Wo_GetFollowRequests') ? \Wo_GetFollowRequests($viewerId) : [];
        if (!is_array($rows)) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $row) {
            $user = is_array($row) ? $row : (is_numeric($row) ? \Wo_UserData((int) $row) : null);
            if (!is_array($user) || empty($user['user_id'])) {
                continue;
            }
            $items[] = [
                'id' => (int) $user['user_id'],
                'username' => (string) ($user['username'] ?? ''),
                'name' => $this->displayName($user),
                'avatar' => $this->mediaUrl((string) ($user['avatar'] ?? '')),
                'verified' => (int) ($user['verified'] ?? 0) === 1,
            ];
        }
        return $items;
    }

    /** Xamarin MyContactsActivity: a profile's Followers/Following list. */
    public function following(
        string $accessToken,
        string $clientId,
        int $afterUserId,
        int $limit,
        int $targetUserId = 0,
        string $type = 'following'
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_following_read', 120, 60);
        $limit = max(1, min(30, $limit));
        $targetUserId = $targetUserId > 0 ? $targetUserId : $viewerId;
        $type = strtolower(trim($type)) === 'followers' ? 'followers' : 'following';
        $target = \Wo_UserData($targetUserId);
        if (!is_array($target) || empty($target['user_id'])) {
            throw new ApiException(404, 'USER_NOT_FOUND', 'This member is not available.');
        }
        if ($targetUserId !== $viewerId) {
            $privacy = (string) ($target['friend_privacy'] ?? '0');
            $viewerFollowsTarget = function_exists('Wo_IsFollowing')
                && (bool) \Wo_IsFollowing($targetUserId, $viewerId);
            if ($privacy !== '0' && $privacy !== '2' && !$viewerFollowsTarget) {
                throw new ApiException(403, 'CONNECTIONS_PRIVATE', 'This member\'s connections are private.');
            }
        }
        $rows = $type === 'followers'
            ? \Wo_GetFollowers($targetUserId, 'profile', $limit, max(0, $afterUserId))
            : \Wo_GetFollowing($targetUserId, 'profile', $limit, max(0, $afterUserId));
        if (!is_array($rows)) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['user_id'])) {
                continue;
            }
            $targetId = (int) $row['user_id'];
            $items[] = array_merge([
                'id' => $targetId,
                'username' => (string) ($row['username'] ?? ''),
                'name' => $this->displayName($row),
                'avatar' => $this->mediaUrl((string) ($row['avatar'] ?? '')),
                'verified' => (int) ($row['verified'] ?? 0) === 1,
                'is_pro' => (int) ($row['is_pro'] ?? 0) === 1,
                'pro_type' => (string) ($row['pro_type'] ?? '1'),
                'about' => $this->plainText((string) ($row['about'] ?? '')),
                'last_seen' => (int) ($row['lastseen'] ?? 0),
                'online' => (int) ($row['lastseen'] ?? 0) > time() - 60,
            ], $this->relation($viewerId, $targetId));
        }
        $lastId = empty($items) ? 0 : (int) $items[array_key_last($items)]['id'];
        return [
            'items' => $items,
            'next_after' => count($items) >= $limit && $lastId > 0 ? $lastId : null,
            'type' => $type,
            'user_id' => $targetUserId,
        ];
    }

    // ---- Create post --------------------------------------------------------

    public function createPost(
        string $accessToken,
        string $clientId,
        string $text,
        string $privacy = '0',
        int $colorId = 0,
        string $feelingType = '',
        string $feeling = '',
        string $map = '',
        array $pollOptions = [],
        array $taggedUserIds = [],
        int $groupId = 0,
        int $pageId = 0,
        bool $isReel = false,
        string $activityType = '',
        string $activity = ''
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_post_create', 20, 3600);
        $composer = $this->composerFlags();
        $text = trim($text);
        if (($feelingType !== '' || $feeling !== '' || $activityType !== '' || $activity !== '') && !$composer['feeling']) {
            throw new ApiException(403, 'POST_TYPE_DISABLED', 'Feeling posts are disabled.', 'feeling');
        }
        if ($colorId > 0 && !$composer['background']) {
            throw new ApiException(403, 'POST_TYPE_DISABLED', 'Background posts are disabled.', 'color_id');
        }
        $tagPrefix = $this->tagPrefix($taggedUserIds, $viewerId);
        $extra = array_merge(
            $this->feelingFields($feelingType, $feeling),
            $this->feelingFields($activityType, $activity)
        );
        if (trim($map) !== '') {
            $extra['postMap'] = \Wo_Secure(trim($map));
        }
        // Poll: needs a question (postText) + 2+ non-empty options, registered
        // with poll_id=1 then Wo_AddOption per option after the post is saved.
        $validOptions = [];
        foreach ($pollOptions as $opt) {
            $opt = trim((string) $opt);
            if ($opt !== '') {
                $validOptions[] = $opt;
            }
        }
        $isPoll = count($validOptions) >= 2;
        if ($isPoll) {
            if (!$composer['poll']) {
                throw new ApiException(403, 'POST_TYPE_DISABLED', 'Poll posts are disabled.', 'poll_options');
            }
            if ($text === '') {
                throw new ApiException(422, 'VALIDATION_FAILED', 'A poll question is required.', 'text');
            }
            $extra['poll_id'] = 1;
        }
        if ($text === '' && empty($extra)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A post message is required.', 'text');
        }
        if (mb_strlen($text) > 60_000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The message is too long.', 'text');
        }
        // WoWonder postPrivacy: 0 Everyone, 1 People I Follow / Friends,
        // 2 People Follow Me, 3 Only Me, 4 Anonymous.
        $privacy = in_array($privacy, ['0', '1', '2', '3', '4'], true) ? $privacy : '0';
        if ($groupId > 0 && !\Wo_IsGroupOnwer($groupId) && !\Wo_IsGroupJoined($groupId, $viewerId)) {
            throw new ApiException(403, 'GROUP_POST_FORBIDDEN', 'Join this group before posting.');
        }
        if ($pageId > 0 && !\Wo_IsPageOnwer($pageId)) {
            throw new ApiException(403, 'PAGE_POST_FORBIDDEN', 'Only a page owner can publish as this page.');
        }
        // recipient_id is left unset (0) so the post lands on the author's own
        // timeline — Wo_RegisterPost rejects recipient_id == user_id.
        $postId = \Wo_RegisterPost(array_merge([
            'user_id' => $viewerId,
            'postText' => \Wo_Secure($tagPrefix . $text),
            'time' => time(),
            'postType' => 'post',
            'postPrivacy' => $privacy,
            'color_id' => max(0, $colorId),
            'group_id' => max(0, $groupId),
            'page_id' => max(0, $pageId),
        ], $extra));
        if (!is_numeric($postId) || (int) $postId < 1) {
            throw new ApiException(422, 'POST_FAILED', 'The post could not be created.');
        }
        if ($isPoll) {
            foreach ($validOptions as $opt) {
                \Wo_AddOption((int) $postId, $opt);
            }
        }
        $raw = \Wo_PostData((int) $postId);
        if ($groupId > 0 && (!is_array($raw) || (int) ($raw['group_id'] ?? 0) !== $groupId)) {
            // Never let a group composer silently publish to the member's
            // personal timeline if group context is lost at any lower layer.
            if (function_exists('Wo_DeletePost')) {
                \Wo_DeletePost((int) $postId);
            }
            throw new ApiException(500, 'GROUP_POST_CONTEXT_LOST', 'The group post could not be published. Please try again.');
        }
        if ($pageId > 0 && (!is_array($raw) || (int) ($raw['page_id'] ?? 0) !== $pageId)) {
            if (function_exists('Wo_DeletePost')) {
                \Wo_DeletePost((int) $postId);
            }
            throw new ApiException(500, 'PAGE_POST_CONTEXT_LOST', 'The page post could not be published. Please try again.');
        }
        $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
        if ($item === null) {
            throw new ApiException(500, 'POST_FAILED', 'The post was created but could not be loaded.');
        }
        return $item;
    }

    /**
     * Publishes a post with photos (multipart `photos[]`). A single photo uses
     * postFile; multiple become album media (Wo_RegisterAlbumMedia) so the feed
     * renders them as a grid. Mirrors api/v2 new_post.php.
     */
    public function createMediaPost(
        string $accessToken,
        string $clientId,
        string $text,
        string $privacy = '0',
        string $feelingType = '',
        string $feeling = '',
        string $map = '',
        array $taggedUserIds = [],
        int $groupId = 0,
        int $pageId = 0,
        bool $isReel = false,
        string $activityType = '',
        string $activity = ''
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_post_create', 20, 3600);
        $composer = $this->composerFlags();
        $text = trim($text);
        if (($feelingType !== '' || $feeling !== '' || $activityType !== '' || $activity !== '') && !$composer['feeling']) {
            throw new ApiException(403, 'POST_TYPE_DISABLED', 'Feeling posts are disabled.', 'feeling');
        }
        if ($isReel && !$composer['reel']) {
            throw new ApiException(403, 'POST_TYPE_DISABLED', 'Reel uploads are disabled.', 'is_reel');
        }
        if (isset($_FILES['postVideo']) && !$composer['video']) {
            throw new ApiException(403, 'POST_TYPE_DISABLED', 'Video uploads are disabled.', 'postVideo');
        }
        if (isset($_FILES['postFile']) && !$composer['file']) {
            throw new ApiException(403, 'POST_TYPE_DISABLED', 'File sharing is disabled.', 'postFile');
        }
        if (isset($_FILES['postMusic']) && !$composer['music']) {
            throw new ApiException(403, 'POST_TYPE_DISABLED', 'Audio uploads are disabled.', 'postMusic');
        }
        $tagPrefix = $this->tagPrefix($taggedUserIds, $viewerId);
        if (mb_strlen($text) > 60_000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The message is too long.', 'text');
        }
        $privacy = in_array($privacy, ['0', '1', '2', '3', '4'], true) ? $privacy : '0';
        if ($groupId > 0 && !\Wo_IsGroupOnwer($groupId) && !\Wo_IsGroupJoined($groupId, $viewerId)) {
            throw new ApiException(403, 'GROUP_POST_FORBIDDEN', 'Join this group before posting.');
        }
        if ($pageId > 0 && !\Wo_IsPageOnwer($pageId)) {
            throw new ApiException(403, 'PAGE_POST_FORBIDDEN', 'Only a page owner can publish as this page.');
        }
        $feelingFields = array_merge(
            $this->feelingFields($feelingType, $feeling),
            $this->feelingFields($activityType, $activity)
        );
        if (trim($map) !== '') {
            $feelingFields['postMap'] = \Wo_Secure(trim($map));
        }

        // Video post (single file) — mirrors the web new_post.php postVideo path.
        // Mutually exclusive with photos. Wo_ShareFile stores the video (and,
        // when ffmpeg is enabled, queues transcoding); the feed serializer
        // detects the video by extension and reads postFileThumb for the poster.
        [$videoFile, $videoName, $videoThumb] = $this->uploadVideo();

        // Single generic file (postFile, any type) then music (postMusic,
        // mp3/wav) — same order as the web; both land on postFile.
        $singleFile = null;
        $singleName = '';
        if ($videoFile === null) {
            [$singleFile, $singleName] = $this->uploadSingleFile('postFile', '');
            if ($singleFile === null) {
                [$singleFile, $singleName] = $this->uploadSingleFile('postMusic', 'mp3,wav');
            }
        }

        // Save each uploaded photo via WoWonder's media pipeline.
        $uploaded = [];
        $files = ($videoFile === null && $singleFile === null) ? ($_FILES['photos'] ?? null) : null;
        if (is_array($files) && isset($files['name']) && is_array($files['name'])) {
            $count = min(count($files['name']), 10);
            for ($i = 0; $i < $count; $i++) {
                if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (!is_uploaded_file((string) $files['tmp_name'][$i])) {
                    continue;
                }
                $shared = \Wo_ShareFile([
                    'file' => $files['tmp_name'][$i],
                    'name' => $files['name'][$i],
                    'size' => $files['size'][$i],
                    'type' => $files['type'][$i],
                    'types' => 'jpg,png,jpeg,gif',
                ], 1);
                if (is_array($shared) && !empty($shared['filename'])) {
                    $uploaded[] = [
                        'filename' => (string) $shared['filename'],
                        'name' => (string) ($shared['name'] ?? $files['name'][$i]),
                    ];
                }
            }
        }

        if ($text === '' && empty($uploaded) && $videoFile === null
            && $singleFile === null && empty($feelingFields)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Add a message, photo or video.', 'text');
        }

        $base = array_merge([
            'user_id' => $viewerId,
            'postText' => \Wo_Secure($tagPrefix . $text),
            'time' => time(),
            'postType' => 'post',
            'postPrivacy' => $privacy,
            'group_id' => max(0, $groupId),
            'page_id' => max(0, $pageId),
        ], $feelingFields);
        if ($videoFile !== null) {
            $base['postFile'] = \Wo_Secure($videoFile, 0);
            $base['postFileName'] = \Wo_Secure($videoName);
            if ($isReel) {
                $base['is_reel'] = 1;
            }
            if ($videoThumb !== '') {
                $base['postFileThumb'] = \Wo_Secure($videoThumb, 0);
            }
        } elseif ($singleFile !== null) {
            $base['postFile'] = \Wo_Secure($singleFile, 0);
            $base['postFileName'] = \Wo_Secure($singleName);
        } elseif (count($uploaded) === 1) {
            $base['postFile'] = \Wo_Secure($uploaded[0]['filename'], 0);
            $base['postFileName'] = \Wo_Secure($uploaded[0]['name']);
        } elseif (count($uploaded) > 1) {
            $base['multi_image'] = '1';
        }
        $postId = \Wo_RegisterPost($base);
        if (!is_numeric($postId) || (int) $postId < 1) {
            throw new ApiException(422, 'POST_FAILED', 'The post could not be created.');
        }
        $postId = (int) $postId;

        if (count($uploaded) > 1 && function_exists('Wo_RegisterAlbumMedia')) {
            foreach ($uploaded as $u) {
                \Wo_RegisterAlbumMedia($postId, $u['filename']);
            }
        }

        $raw = \Wo_PostData($postId);
        if ($groupId > 0 && (!is_array($raw) || (int) ($raw['group_id'] ?? 0) !== $groupId)) {
            // Keep media posts subject to the same invariant as text posts.
            if (function_exists('Wo_DeletePost')) {
                \Wo_DeletePost($postId);
            }
            throw new ApiException(500, 'GROUP_POST_CONTEXT_LOST', 'The group post could not be published. Please try again.');
        }
        if ($pageId > 0 && (!is_array($raw) || (int) ($raw['page_id'] ?? 0) !== $pageId)) {
            if (function_exists('Wo_DeletePost')) {
                \Wo_DeletePost($postId);
            }
            throw new ApiException(500, 'PAGE_POST_CONTEXT_LOST', 'The page post could not be published. Please try again.');
        }
        $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
        if ($item === null) {
            throw new ApiException(500, 'POST_FAILED', 'The post was created but could not be loaded.');
        }
        return $item;
    }

    /**
     * Casts a vote for a poll option (Wo_VoteUp enforces one vote per post and
     * per option) and returns the refreshed post with updated percentages.
     */
    public function vote(string $accessToken, string $clientId, int $postId, int $optionId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_post_vote', 60, 60);
        if ($optionId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid option id is required.', 'option_id');
        }
        $this->assertPostVisible($postId);
        if (function_exists('Wo_VoteUp')) {
            \Wo_VoteUp($optionId, $viewerId);
        }
        $raw = \Wo_PostData($postId);
        $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
        if ($item === null) {
            throw new ApiException(404, 'POST_NOT_FOUND', 'This post is not available.');
        }
        return $item;
    }

    // ---- Internals ----------------------------------------------------------

    // ---- Pages (Xamarin PagesActivity / PageProfileActivity parity) --------

    public function pagesOverview(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_pages', 60, 60);
        global $sqlConnect, $wo;
        if (!($sqlConnect instanceof \mysqli)) return ['managed'=>[],'liked'=>[],'suggested'=>[],'categories'=>[]];
        $collect = function (string $sql) use ($sqlConnect, $viewerId): array {
            $items = [];
            $result = $sqlConnect->query($sql);
            while ($result && ($row = $result->fetch_assoc())) {
                $page = \Wo_PageData((int)($row['page_id'] ?? 0));
                if (is_array($page)) $items[] = $this->presentPage($page, $viewerId);
            }
            return $items;
        };
        $managed = $collect('SELECT page_id FROM '.T_PAGES.' WHERE active=\'1\' AND (user_id='.$viewerId.' OR page_id IN (SELECT page_id FROM '.T_PAGE_ADMINS.' WHERE user_id='.$viewerId.')) ORDER BY page_id DESC LIMIT 30');
        $liked = $collect('SELECT page_id FROM '.T_PAGES_LIKES.' WHERE user_id='.$viewerId.' AND active=\'1\' AND page_id NOT IN (SELECT page_id FROM '.T_PAGES.' WHERE user_id='.$viewerId.') ORDER BY id DESC LIMIT 30');
        $suggested = $collect('SELECT page_id FROM '.T_PAGES.' WHERE active=\'1\' AND user_id<>'.$viewerId.' AND page_id NOT IN (SELECT page_id FROM '.T_PAGES_LIKES.' WHERE user_id='.$viewerId.' AND active=\'1\') ORDER BY page_id DESC LIMIT 12');
        $categories = [];
        foreach ((array)($wo['page_categories'] ?? []) as $id => $label) {
            $categories[(string)$id] = html_entity_decode((string)$label, ENT_QUOTES | ENT_HTML5);
        }
        return ['managed'=>$managed,'liked'=>$liked,'suggested'=>$suggested,'categories'=>$categories];
    }

    public function searchPages(string $accessToken, string $clientId, string $query, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_page_search', 60, 60);
        global $sqlConnect;
        if (!($sqlConnect instanceof \mysqli)) return [];
        $query = trim($query);
        if ($query === '') return [];
        $limit = max(1,min(30,$limit));
        $like = '%'.$query.'%';
        $stmt = $sqlConnect->prepare('SELECT page_id FROM '.T_PAGES.' WHERE active=\'1\' AND (page_title LIKE ? OR page_name LIKE ?) ORDER BY page_id DESC LIMIT ?');
        if (!$stmt) return [];
        $stmt->bind_param('ssi',$like,$like,$limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($result && ($row=$result->fetch_assoc())) {
            $page = \Wo_PageData((int)$row['page_id']);
            if (is_array($page)) $items[]=$this->presentPage($page,$viewerId);
        }
        $stmt->close();
        return $items;
    }

    public function createPage(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_page_create', 10, 3600);
        global $sqlConnect;
        $title = trim((string)($body['page_title'] ?? ''));
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]/','',(string)($body['page_name'] ?? '')));
        $description = trim((string)($body['page_description'] ?? ''));
        $category = max(1,(int)($body['page_category'] ?? 1));
        if ($title === '' || mb_strlen($title)>32) throw new ApiException(422,'VALIDATION_FAILED','Enter a page title of up to 32 characters.','page_title');
        if (mb_strlen($name)<5 || mb_strlen($name)>32) throw new ApiException(422,'VALIDATION_FAILED','The page username must contain 5 to 32 letters, numbers or underscores.','page_name');
        $stmt = $sqlConnect->prepare('SELECT page_id FROM '.T_PAGES.' WHERE page_name=? LIMIT 1');
        $stmt->bind_param('s',$name); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); throw new ApiException(409,'PAGE_NAME_TAKEN','This page username is already used.','page_name'); }
        $stmt->close();
        $ok = \Wo_RegisterPage([
            'user_id'=>$viewerId,'page_name'=>\Wo_Secure($name),'page_title'=>\Wo_Secure($title),
            'page_description'=>\Wo_Secure($description),'page_category'=>$category,
            'active'=>'1','time'=>time(),
        ]);
        $id = $ok ? (int)$sqlConnect->insert_id : 0;
        $page = $id>0 ? \Wo_PageData($id) : null;
        if (!is_array($page)) throw new ApiException(422,'PAGE_CREATE_FAILED','The page could not be created.');
        return $this->presentPage($page,$viewerId);
    }

    public function page(string $accessToken, string $clientId, int $pageId): array
    {
        $viewerId = $this->authenticate($accessToken,$clientId,'mobile_page_view',120,60);
        $page = \Wo_PageData($pageId);
        if (!is_array($page) || empty($page['page_id'])) throw new ApiException(404,'PAGE_NOT_FOUND','This page is not available.');
        return $this->presentPage($page,$viewerId);
    }

    public function pagePosts(string $accessToken,string $clientId,int $pageId,int $afterId,int $limit): array
    {
        $viewerId=$this->authenticate($accessToken,$clientId,'mobile_page_posts',120,60);
        if (!is_array(\Wo_PageData($pageId))) throw new ApiException(404,'PAGE_NOT_FOUND','This page is not available.');
        $rows=\Wo_GetPosts(['limit'=>max(1,min(20,$limit)),'page_id'=>$pageId,'after_post_id'=>max(0,$afterId),'publisher_id'=>0]);
        $items=$this->feed->presentMany(is_array($rows)?$rows:[],$viewerId);
        $last=empty($items)?0:(int)$items[array_key_last($items)]['id'];
        return ['items'=>$items,'next_after'=>count($items)>=$limit&&$last>0?$last:null];
    }

    public function updatePage(string $accessToken,string $clientId,int $pageId,array $body): array
    {
        $viewerId=$this->authenticate($accessToken,$clientId,'mobile_page_update',30,3600);
        if (!\Wo_IsPageOnwer($pageId)) throw new ApiException(403,'PAGE_OWNER_REQUIRED','Only a page owner can update this page.');
        $allowed=['page_title','page_name','page_description','page_category','website','company','phone','address','facebook','twitter','linkedin','youtube','instgram','users_post','call_action_type','call_action_type_url'];
        $update=[];
        foreach($allowed as $field) if(array_key_exists($field,$body)) $update[$field]=is_string($body[$field])?trim($body[$field]):$body[$field];
        if ($update===[] || !\Wo_UpdatePageData($pageId,$update)) throw new ApiException(422,'PAGE_UPDATE_FAILED','The page could not be updated.');
        return $this->presentPage(\Wo_PageData($pageId),$viewerId);
    }

    public function updatePageMedia(string $accessToken,string $clientId,int $pageId,string $type,?array $image): array
    {
        $viewerId=$this->authenticate($accessToken,$clientId,'mobile_page_media',20,3600);
        if (!\Wo_IsPageOnwer($pageId)) throw new ApiException(403,'PAGE_OWNER_REQUIRED','Only a page owner can change images.');
        if (!in_array($type,['avatar','cover'],true)||!is_array($image)||(int)($image['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new ApiException(422,'VALIDATION_FAILED','Select a valid image.','image');
        if (!\Wo_UploadImage((string)$image['tmp_name'],(string)$image['name'],$type,(string)$image['type'],$pageId,'page')) throw new ApiException(422,'PAGE_MEDIA_FAILED','The image could not be uploaded.');
        return $this->presentPage(\Wo_PageData($pageId),$viewerId);
    }

    public function deletePage(string $accessToken,string $clientId,int $pageId): array
    {
        $this->authenticate($accessToken,$clientId,'mobile_page_delete',5,3600);
        if (!\Wo_IsPageOnwer($pageId)||!\Wo_DeletePage($pageId)) throw new ApiException(403,'PAGE_DELETE_FORBIDDEN','The page could not be deleted.');
        return ['deleted'=>true];
    }

    public function pageAdmins(string $accessToken,string $clientId,int $pageId): array
    {
        $this->authenticate($accessToken,$clientId,'mobile_page_admins',60,60);
        if (!\Wo_IsPageOnwer($pageId)) throw new ApiException(403,'PAGE_OWNER_REQUIRED','Only a page owner can manage administrators.');
        $rows=\Wo_GetPageAdmins($pageId);
        $items=[];
        foreach(is_array($rows)?$rows:[] as $user) if(is_array($user)) $items[]=[
            'id'=>(int)($user['user_id']??0),'username'=>(string)($user['username']??''),
            'name'=>$this->displayName($user),'avatar'=>$this->mediaUrl((string)($user['avatar']??'')),
            'verified'=>(int)($user['verified']??0)===1,'is_admin'=>true,
        ];
        return $items;
    }

    public function togglePageAdmin(string $accessToken,string $clientId,int $pageId,int $userId): array
    {
        $this->authenticate($accessToken,$clientId,'mobile_page_admin_update',30,3600);
        if (!\Wo_IsPageOnwer($pageId,false)) throw new ApiException(403,'PAGE_OWNER_REQUIRED','Only the page owner can manage administrators.');
        if ($userId<1 || !is_array(\Wo_UserData($userId))) throw new ApiException(404,'USER_NOT_FOUND','This user is not available.');
        $was=\Wo_IsPageAdminExists($userId,$pageId);
        \Wo_AddPageAdmin($userId,$pageId);
        return ['is_admin'=>!$was];
    }

    public function pageReviews(string $accessToken,string $clientId,int $pageId): array
    {
        $viewerId=$this->authenticate($accessToken,$clientId,'mobile_page_reviews',60,60);
        $rows=\Wo_GetPageReviews($pageId,false,30);
        $items=[];
        foreach(is_array($rows)?$rows:[] as $row) {
            $user=is_array($row['user_data']??null)?$row['user_data']:[];
            $items[]=['id'=>(int)($row['id']??0),'rating'=>(int)($row['valuation']??0),'review'=>html_entity_decode((string)($row['review']??''),ENT_QUOTES|ENT_HTML5),
                'user'=>['id'=>(int)($user['user_id']??0),'name'=>$this->displayName($user),'username'=>(string)($user['username']??''),'avatar'=>$this->mediaUrl((string)($user['avatar']??''))],
                'is_own'=>(int)($row['user_id']??0)===$viewerId];
        }
        return $items;
    }

    public function ratePage(string $accessToken,string $clientId,int $pageId,int $rating,string $review): array
    {
        $this->authenticate($accessToken,$clientId,'mobile_page_rate',10,3600);
        if ($rating<1||$rating>5) throw new ApiException(422,'VALIDATION_FAILED','Choose a rating from 1 to 5.','rating');
        if (!\Wo_RatePage($pageId,$rating,trim($review))) throw new ApiException(422,'PAGE_ALREADY_RATED','You have already reviewed this page.');
        return ['rated'=>true];
    }

    private function presentPage(array $page,int $viewerId): array
    {
        $id=(int)($page['page_id']??0);
        global $wo;
        $categoryId=(int)($page['page_category']??0);
        return [
            'id'=>$id,'username'=>(string)($page['page_name']??''),'title'=>html_entity_decode((string)($page['page_title']??''),ENT_QUOTES|ENT_HTML5),
            'description'=>html_entity_decode((string)($page['page_description']??''),ENT_QUOTES|ENT_HTML5),
            'avatar'=>$this->mediaUrl((string)($page['avatar']??'')),'cover'=>$this->mediaUrl((string)($page['cover']??'')),
            'category_id'=>$categoryId,'category'=>(string)(($wo['page_categories'][$categoryId]??'')),
            'likes'=>function_exists('Wo_CountPageLikes')?(int)\Wo_CountPageLikes($id):0,
            'is_owner'=>\Wo_IsPageOnwer($id),'is_liked'=>function_exists('Wo_IsPageLiked')&&\Wo_IsPageLiked($id,$viewerId),
            'verified'=>(int)($page['verified']??0)===1,'website'=>(string)($page['website']??''),
            'company'=>(string)($page['company']??''),'phone'=>(string)($page['phone']??''),'address'=>(string)($page['address']??''),
            'facebook'=>(string)($page['facebook']??''),'twitter'=>(string)($page['twitter']??''),'linkedin'=>(string)($page['linkedin']??''),
            'youtube'=>(string)($page['youtube']??''),'instagram'=>(string)($page['instgram']??''),
            'users_post'=>(int)($page['users_post']??0)===1,'url'=>(string)($page['url']??''),
        ];
    }

    // ---- Groups (Xamarin GroupsActivity / GroupProfileActivity parity) ----

    public function groupsOverview(string $accessToken, string $clientId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_groups_read', 120, 60);
        global $sqlConnect, $wo;
        $managedRaw = \Wo_GetMyGroupsAPI(7, 0, 'DESC');
        $joinedRaw = \Wo_GetUsersGroupsAPI($viewerId, 10, 0);
        $managed = [];
        foreach (array_reverse(is_array($managedRaw) ? $managedRaw : []) as $row) {
            if (is_array($row)) $managed[] = $this->presentGroup($row, $viewerId);
        }
        $joined = [];
        foreach (is_array($joinedRaw) ? $joinedRaw : [] as $row) {
            if (!is_array($row) || \Wo_IsGroupOnwer((int)($row['id'] ?? $row['group_id'] ?? 0))) continue;
            $joined[] = $this->presentGroup($row, $viewerId);
        }
        $discover = [];
        $sql = 'SELECT id FROM ' . T_GROUPS . " WHERE active='1' AND user_id<>" . $viewerId
            . ' AND id NOT IN (SELECT group_id FROM ' . T_GROUP_MEMBERS . ' WHERE user_id=' . $viewerId . ') ORDER BY id DESC LIMIT 10';
        $result = mysqli_query($sqlConnect, $sql);
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $raw = \Wo_GroupData((int)$row['id']);
            if (is_array($raw)) $discover[] = $this->presentGroup($raw, $viewerId);
        }
        $categories = [];
        foreach ((array)($wo['group_categories'] ?? []) as $id => $name) {
            $categories[(string)$id] = html_entity_decode(strip_tags((string)$name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $subcategories = [];
        foreach ((array)($wo['group_sub_categories'] ?? []) as $categoryId => $items) {
            $subcategories[(string)$categoryId] = [];
            foreach ((array)$items as $item) {
                if (!is_array($item)) continue;
                $name = (string)($item['lang'] ?? $item['name'] ?? '');
                if (isset($wo['lang'][$name])) $name = (string)$wo['lang'][$name];
                $subcategories[(string)$categoryId][] = [
                    'id'=>(int)($item['id'] ?? 0),
                    'name'=>html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
        }
        $customFields = [];
        if (function_exists('Wo_GetCustomFields')) {
            foreach ((array)\Wo_GetCustomFields('group') as $field) {
                if (!is_array($field)) continue;
                $customFields[] = [
                    'fid'=>(string)($field['fid'] ?? ''),
                    'name'=>html_entity_decode(strip_tags((string)($field['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'description'=>html_entity_decode(strip_tags((string)($field['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'type'=>(string)($field['type'] ?? 'textbox'),
                    'options'=>html_entity_decode(strip_tags((string)($field['options'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
        }
        return ['managed'=>$managed, 'joined'=>$joined, 'discover'=>$discover, 'categories'=>$categories, 'subcategories'=>$subcategories, 'custom_fields'=>$customFields];
    }

    public function searchGroups(string $accessToken, string $clientId, string $query, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_groups_search', 120, 60);
        global $sqlConnect;
        $query = trim($query);
        if ($query === '') return [];
        $limit = max(1, min(30, $limit));
        $like = '%' . $query . '%';
        $stmt = $sqlConnect->prepare('SELECT id FROM ' . T_GROUPS . " WHERE active='1' AND (group_name LIKE ? OR group_title LIKE ?) ORDER BY id DESC LIMIT ?");
        if (!$stmt) return [];
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $raw = \Wo_GroupData((int)$row['id']);
            if (is_array($raw)) $items[] = $this->presentGroup($raw, $viewerId);
        }
        $stmt->close();
        return $items;
    }

    public function group(string $accessToken, string $clientId, int $groupId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_read', 120, 60);
        $raw = \Wo_GroupData($groupId);
        if (!is_array($raw) || empty($raw['id'])) throw new ApiException(404, 'GROUP_NOT_FOUND', 'This group is not available.');
        return $this->presentGroup($raw, $viewerId);
    }

    public function createGroup(string $accessToken, string $clientId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_create', 10, 3600);
        global $wo;
        $title = trim((string)($body['group_title'] ?? ''));
        $name = trim((string)($body['group_name'] ?? ''));
        $about = trim((string)($body['about'] ?? ''));
        $category = (int)($body['category'] ?? 0);
        $privacy = (int)($body['privacy'] ?? 1) === 2 ? 2 : 1;
        if ($title === '') throw new ApiException(422, 'VALIDATION_FAILED', 'Group title is required.', 'group_title');
        if (strlen($name) < 5 || strlen($name) > 32 || preg_match('/^[\w]+$/', $name) !== 1) throw new ApiException(422, 'VALIDATION_FAILED', 'Group username must be 5–32 letters, numbers, or underscores.', 'group_name');
        if ($about === '') throw new ApiException(422, 'VALIDATION_FAILED', 'Group description is required.', 'about');
        if (!array_key_exists($category, (array)($wo['group_categories'] ?? []))) throw new ApiException(422, 'VALIDATION_FAILED', 'Select a valid category.', 'category');
        $exists = \Wo_IsNameExist($name, 0);
        if ((is_array($exists) && in_array(true, $exists, true)) || in_array($name, (array)($wo['site_pages'] ?? []), true)) throw new ApiException(409, 'GROUP_NAME_TAKEN', 'Group username already exists.', 'group_name');
        $ok = \Wo_RegisterGroup(['group_name'=>\Wo_Secure($name), 'user_id'=>$viewerId, 'group_title'=>\Wo_Secure($title), 'about'=>\Wo_Secure($about), 'category'=>$category, 'privacy'=>$privacy, 'active'=>1]);
        if (!$ok) throw new ApiException(422, 'GROUP_CREATE_FAILED', 'The group could not be created.');
        $id = (int)\Wo_GroupIdFromGroupname($name);
        $raw = \Wo_GroupData($id);
        if (!is_array($raw)) throw new ApiException(500, 'GROUP_CREATE_FAILED', 'The group was created but could not be loaded.');
        return $this->presentGroup($raw, $viewerId);
    }

    public function toggleGroupJoin(string $accessToken, string $clientId, int $groupId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_join', 30, 60);
        $raw = \Wo_GroupData($groupId);
        if (!is_array($raw)) throw new ApiException(404, 'GROUP_NOT_FOUND', 'This group is not available.');
        if (\Wo_IsGroupOnwer($groupId)) return ['status'=>'owner', 'group'=>$this->presentGroup($raw, $viewerId)];
        if (\Wo_IsGroupJoined($groupId, $viewerId) || \Wo_IsJoinRequested($groupId, $viewerId)) {
            if (!\Wo_LeaveGroup($groupId, $viewerId)) throw new ApiException(422, 'GROUP_LEAVE_FAILED', 'Could not leave this group.');
            return ['status'=>'left', 'group'=>$this->presentGroup(\Wo_GroupData($groupId), $viewerId)];
        }
        if (!\Wo_RegisterGroupJoin($groupId, $viewerId)) throw new ApiException(422, 'GROUP_JOIN_FAILED', 'Could not join this group.');
        $status = (int)($raw['join_privacy'] ?? 1) === 2 ? 'requested' : 'joined';
        return ['status'=>$status, 'group'=>$this->presentGroup(\Wo_GroupData($groupId), $viewerId)];
    }

    public function groupPosts(string $accessToken, string $clientId, int $groupId, int $afterId, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_posts', 120, 60);
        $group = \Wo_GroupData($groupId);
        if (!is_array($group)) throw new ApiException(404, 'GROUP_NOT_FOUND', 'This group is not available.');
        $allowed = (int)($group['privacy'] ?? 1) === 1 || \Wo_IsGroupOnwer($groupId) || \Wo_IsGroupJoined($groupId, $viewerId);
        if (!$allowed) throw new ApiException(403, 'GROUP_PRIVATE', 'Join this group to see its posts.');
        $limit = max(1, min(20, $limit));
        $rows = \Wo_GetPosts(['limit'=>$limit, 'group_id'=>$groupId, 'after_post_id'=>max(0,$afterId), 'placement'=>'multi_image_post', 'is_reel'=>'disable']);
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $raw) {
            $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
            if ($item !== null) $items[] = $item;
        }
        $next = count($items) >= $limit ? (int)$items[array_key_last($items)]['id'] : 0;
        return ['items'=>$items, 'next_after'=>$next];
    }

    public function groupMembers(string $accessToken, string $clientId, int $groupId, int $offset, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_members', 120, 60);
        if (!is_array(\Wo_GroupData($groupId))) throw new ApiException(404, 'GROUP_NOT_FOUND', 'This group is not available.');
        $rows = \Wo_GetGroupSettingMembers($groupId, max(1,min(50,$limit)), max(0,$offset));
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $user) {
            if (!is_array($user)) continue;
            $uid = (int)($user['user_id'] ?? 0);
            $lastSeen = (int)($user['lastseen'] ?? 0);
            $items[] = ['id'=>$uid, 'username'=>(string)($user['username'] ?? ''), 'name'=>$this->displayName($user), 'avatar'=>$this->mediaUrl((string)($user['avatar'] ?? '')), 'verified'=>(int)($user['verified'] ?? 0)===1, 'is_admin'=>(bool)\Wo_GetGroupAdminInfo($uid,$groupId) || (int)(\Wo_GroupData($groupId)['user_id'] ?? 0)===$uid, 'last_seen'=>$lastSeen, 'online'=>$lastSeen > time() - 60, 'is_self'=>$uid === $viewerId];
        }
        return $items;
    }

    public function groupInviteCandidates(string $accessToken, string $clientId, int $groupId, int $offset, int $limit): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_invites', 60, 60);
        if (!\Wo_IsGroupOnwer($groupId) && !\Wo_IsGroupJoined($groupId, $viewerId)) throw new ApiException(403, 'GROUP_MEMBER_REQUIRED', 'Join this group to invite members.');
        $rows = \Wo_GetGroupsNotMember($groupId);
        $items = [];
        $rows = array_slice(is_array($rows) ? $rows : [], max(0,$offset), max(1,min(50,$limit)));
        foreach ($rows as $user) if (is_array($user)) $items[] = $this->presentGroupUser($user);
        return $items;
    }

    public function inviteGroupMember(string $accessToken, string $clientId, int $groupId, int $userId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_invite', 60, 60);
        if ((!\Wo_IsGroupOnwer($groupId) && !\Wo_IsGroupJoined($groupId, $viewerId)) || !\Wo_RegsiterGroupAdd($userId,$groupId)) throw new ApiException(422, 'GROUP_INVITE_FAILED', 'The member could not be invited.');
        return ['invited'=>true];
    }

    public function removeGroupMember(string $accessToken, string $clientId, int $groupId, int $userId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_group_member_remove', 60, 60);
        if (!\Wo_IsGroupOnwer($groupId) || !\Wo_LeaveGroup($groupId,$userId)) throw new ApiException(422, 'GROUP_MEMBER_REMOVE_FAILED', 'The member could not be removed.');
        return ['removed'=>true];
    }

    public function toggleGroupAdmin(string $accessToken, string $clientId, int $groupId, int $userId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_group_member_admin', 60, 60);
        if (!\Wo_IsGroupOnwer($groupId)) throw new ApiException(403, 'GROUP_OWNER_REQUIRED', 'Only the group owner can manage administrators.');
        $raw = \Wo_GroupData($groupId);
        if (!is_array($raw) || (int)($raw['user_id'] ?? 0) === $userId) throw new ApiException(422, 'GROUP_ADMIN_INVALID', 'The group owner cannot be changed here.');
        $code = \Wo_AddGroupAdmin($userId, $groupId);
        if ($code === false) throw new ApiException(422, 'GROUP_ADMIN_FAILED', 'The administrator could not be updated.');
        return ['is_admin'=>(int)$code === 1];
    }

    public function blockGroupMember(string $accessToken, string $clientId, int $groupId, int $userId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_member_block', 30, 60);
        if ($userId < 1 || $userId === $viewerId) throw new ApiException(422, 'BLOCK_INVALID', 'This member cannot be blocked.');
        if (\Wo_IsGroupOnwer($groupId)) \Wo_LeaveGroup($groupId, $userId);
        if (!\Wo_IsBlocked($userId) && !\Wo_RegisterBlock($userId)) throw new ApiException(422, 'BLOCK_FAILED', 'The member could not be blocked.');
        return ['blocked'=>true];
    }

    public function groupRequests(string $accessToken, string $clientId, int $groupId, int $offset, int $limit): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_group_requests', 60, 60);
        if (!\Wo_IsGroupOnwer($groupId)) throw new ApiException(403, 'GROUP_OWNER_REQUIRED', 'Only a group owner can view requests.');
        $rows = \Wo_GetGroupRequestsWithOffset(['group_id'=>$groupId,'limit'=>max(1,min(50,$limit)),'offset'=>max(0,$offset)]);
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $user = is_array($row['user_data'] ?? null) ? $row['user_data'] : $row;
            if (is_array($user)) $items[] = $this->presentGroupUser($user);
        }
        return $items;
    }

    public function decideGroupRequest(string $accessToken, string $clientId, int $groupId, int $userId, string $action): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_group_request_action', 60, 60);
        if (!\Wo_IsGroupOnwer($groupId)) throw new ApiException(403, 'GROUP_OWNER_REQUIRED', 'Only a group owner can manage requests.');
        $ok = $action === 'accept' ? \Wo_AcceptJoinRequest($userId,$groupId) : \Wo_DeleteJoinRequest($userId,$groupId);
        if (!$ok) throw new ApiException(422, 'GROUP_REQUEST_FAILED', 'The request could not be updated.');
        return ['accepted'=>$action === 'accept'];
    }

    public function updateGroupMedia(string $accessToken, string $clientId, int $groupId, string $type, ?array $image): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_media', 20, 3600);
        if (!\Wo_IsGroupOnwer($groupId)) throw new ApiException(403, 'GROUP_OWNER_REQUIRED', 'Only a group owner can change images.');
        if (!in_array($type,['avatar','cover'],true) || !is_array($image) || (int)($image['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new ApiException(422, 'VALIDATION_FAILED', 'Select a valid image.', 'image');
        $ok = \Wo_UploadImage((string)$image['tmp_name'],(string)$image['name'],$type,(string)$image['type'],$groupId,'group');
        if (!$ok) throw new ApiException(422, 'GROUP_MEDIA_FAILED', 'The image could not be uploaded.');
        // WoWonder creates activity posts for user avatar/cover changes, but
        // its group branch only updates the table. Register the equivalent
        // group timeline activity so home/group feeds and Xamarin-style
        // recipient headers stay in sync.
        global $sqlConnect;
        if ($sqlConnect instanceof \mysqli) {
            $column = $type === 'avatar' ? 'avatar' : 'cover';
            $file = '';
            $stmt = $sqlConnect->prepare("SELECT {$column} FROM " . T_GROUPS . " WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $groupId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $file = (string)($row[$column] ?? '');
                $stmt->close();
            }
            if ($file !== '' && function_exists('Wo_RegisterPost')) {
                \Wo_RegisterPost([
                    'user_id' => $viewerId,
                    'group_id' => $groupId,
                    'postFile' => \Wo_Secure($file, 0),
                    'time' => time(),
                    'postType' => $type === 'avatar' ? 'group_picture' : 'group_cover_picture',
                    'postPrivacy' => '0',
                ]);
            }
        }
        return $this->presentGroup(\Wo_GroupData($groupId),$viewerId);
    }

    private function presentGroupUser(array $user): array
    {
        global $wo;
        $uid = (int)($user['user_id'] ?? 0);
        $lastSeen = (int)($user['lastseen'] ?? 0);
        return ['id'=>$uid,'username'=>(string)($user['username'] ?? ''),'name'=>$this->displayName($user),'avatar'=>$this->mediaUrl((string)($user['avatar'] ?? '')),'verified'=>(int)($user['verified'] ?? 0)===1,'is_admin'=>false,'last_seen'=>$lastSeen,'online'=>$lastSeen > time() - 60,'is_self'=>(int)($wo['user']['id'] ?? 0)===$uid];
    }

    public function reportGroup(string $accessToken, string $clientId, int $groupId, string $text): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_group_report', 20, 3600);
        $result = \Wo_ReportGroup($groupId, trim($text));
        if ($result === false || $result === null) throw new ApiException(422, 'GROUP_REPORT_FAILED', 'The report could not be updated.');
        return ['reported'=>(int)$result === 1];
    }

    public function updateGroup(string $accessToken, string $clientId, int $groupId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_update', 30, 3600);
        if (!\Wo_IsGroupOnwer($groupId)) throw new ApiException(403, 'GROUP_UPDATE_FORBIDDEN', 'Only a group owner can change these settings.');
        $allowed = ['group_title','group_name','about','category','sub_category','privacy','join_privacy'];
        if (function_exists('Wo_GetCustomFields')) {
            foreach ((array)\Wo_GetCustomFields('group') as $field) {
                if (is_array($field) && !empty($field['fid'])) $allowed[] = (string)$field['fid'];
            }
        }
        $update = [];
        foreach ($allowed as $key) if (array_key_exists($key,$body)) $update[$key]=\Wo_Secure((string)$body[$key]);
        if ($update === [] || !\Wo_UpdateGroupData($groupId,$update)) throw new ApiException(422, 'GROUP_UPDATE_FAILED', 'The group could not be updated.');
        return $this->presentGroup(\Wo_GroupData($groupId), $viewerId);
    }

    public function deleteGroup(string $accessToken, string $clientId, int $groupId): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_group_delete', 10, 3600);
        if (!\Wo_IsGroupOnwer($groupId) || !\Wo_DeleteGroup($groupId)) throw new ApiException(403, 'GROUP_DELETE_FORBIDDEN', 'The group could not be deleted.');
        return ['deleted'=>true];
    }

    public function deleteGroupConfirmed(string $accessToken, string $clientId, int $groupId, array $body): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_group_delete', 3, 86400);
        if (($body['confirmed'] ?? false) !== true) throw new ApiException(422, 'CONFIRMATION_REQUIRED', 'Confirm that you want to delete this group.', 'confirmed');
        $password = (string)($body['password'] ?? '');
        $user = \Wo_UserData($viewerId);
        if (!is_array($user) || !\Wo_Login((string)$user['username'], $password)) {
            throw new ApiException(422, 'CURRENT_PASSWORD_INVALID', 'Please confirm your password.', 'password');
        }
        if (!\Wo_IsGroupOnwer($groupId) || !\Wo_DeleteGroup($groupId)) throw new ApiException(403, 'GROUP_DELETE_FORBIDDEN', 'The group could not be deleted.');
        return ['deleted'=>true];
    }

    private function presentGroup(array $group, int $viewerId): array
    {
        global $wo;
        $id = (int)($group['id'] ?? $group['group_id'] ?? 0);
        // Wo_GroupData replaces `category` with its translated label and keeps
        // the database id in `category_id`.
        $category = (int)($group['category_id'] ?? $group['category'] ?? 0);
        $subCategory = (int)($group['sub_category'] ?? 0);
        $owner = \Wo_IsGroupOnwer($id);
        return [
            'id'=>$id, 'username'=>(string)($group['group_name'] ?? ''), 'title'=>html_entity_decode(strip_tags((string)($group['group_title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'about'=>html_entity_decode(strip_tags((string)($group['about'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'avatar'=>$this->mediaUrl((string)($group['avatar'] ?? '')), 'cover'=>$this->mediaUrl((string)($group['cover'] ?? '')),
            'category'=>(string)$category, 'category_id'=>$category, 'category_name'=>html_entity_decode(strip_tags((string)(($wo['group_categories'][$category] ?? $group['category'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'sub_category'=>$subCategory,
            'sub_category_name'=>html_entity_decode(strip_tags((string)($group['group_sub_category'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'custom_fields'=>$this->presentGroupCustomValues($group),
            'members'=>(int)\Wo_CountGroupMembers($id), 'posts'=>function_exists('Wo_CountGroupPosts') ? (int)\Wo_CountGroupPosts($id) : 0,
            'privacy'=>(int)($group['privacy'] ?? 1), 'join_privacy'=>(int)($group['join_privacy'] ?? 1),
            'is_owner'=>(bool)$owner, 'is_joined'=>(bool)\Wo_IsGroupJoined($id,$viewerId), 'is_requested'=>(bool)\Wo_IsJoinRequested($id,$viewerId),
            'is_reported'=>function_exists('Wo_IsReportExists') ? (bool)\Wo_IsReportExists($id,'group') : false,
            'url'=>(string)($group['url'] ?? \Wo_SeoLink('index.php?link1=timeline&u=' . ($group['group_name'] ?? ''))),
        ];
    }

    private function presentGroupCustomValues(array $group): array
    {
        $values = [];
        if (!function_exists('Wo_GetCustomFields')) return $values;
        foreach ((array)\Wo_GetCustomFields('group') as $field) {
            if (!is_array($field) || empty($field['fid'])) continue;
            $fid = (string)$field['fid'];
            $values[$fid] = html_entity_decode(strip_tags((string)($group[$fid] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $values;
    }

    private function authenticate(
        string $accessToken,
        string $clientId,
        string $bucket,
        int $limit,
        int $window
    ): int {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $userId = (int) $session['user_id'];
        $this->rateLimiter->enforce($bucket, (string) $userId, $limit, $window);
        $this->feed->bootstrapWebContext($userId);
        return $userId;
    }

    private function invitationLinksPayload(int $userId): array
    {
        global $wo;
        if ((int)($wo['config']['invite_links_system'] ?? 0) !== 1) {
            throw new ApiException(403, 'INVITATION_LINKS_DISABLED', 'Invitation links are not available.');
        }

        $records = function_exists('Wo_GetMyInvitaionCodes')
            ? (array)\Wo_GetMyInvitaionCodes($userId)
            : [];
        $siteUrl = rtrim((string)($wo['config']['site_url'] ?? ''), '/');
        $items = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $invitedId = (int)($record['invited_id'] ?? 0);
            $items[] = [
                'id' => (int)($record['id'] ?? 0),
                'link' => $siteUrl . '/register?invite=' . rawurlencode((string)($record['code'] ?? '')),
                'time' => (int)($record['time'] ?? 0),
                'invited_user' => $invitedId > 0 ? [
                    'id' => $invitedId,
                    'name' => html_entity_decode(
                        strip_tags((string)($record['user_name'] ?? '')),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    ),
                    'url' => (string)($record['user_url'] ?? ''),
                ] : null,
            ];
        }

        $available = function_exists('Wo_GetAvailableLinks')
            ? \Wo_GetAvailableLinks($userId)
            : 0;
        $generated = function_exists('Wo_GetGeneratedLinks')
            ? \Wo_GetGeneratedLinks($userId)
            : count($items);
        $used = function_exists('Wo_GetUsedLinks')
            ? \Wo_GetUsedLinks($userId)
            : count(array_filter($items, static fn(array $item): bool => $item['invited_user'] !== null));

        return [
            'available_links' => $available === false ? 0 : (string)$available,
            'generated_links' => (int)$generated,
            'used_links' => (int)$used,
            'can_generate' => function_exists('Wo_IfCanGenerateLink')
                ? (bool)\Wo_IfCanGenerateLink($userId)
                : true,
            'items' => $items,
        ];
    }

    private function addressValues(array $body): array
    {
        $values = [];
        foreach (['name', 'phone', 'country', 'city', 'zip', 'address'] as $field) {
            $value = trim((string)($body[$field] ?? ''));
            $maximum = $field === 'address' ? 500 : 150;
            if ($value === '' || mb_strlen($value) > $maximum) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Please enter your address details.', $field);
            }
            $values[$field] = \Wo_Secure($value);
        }
        return $values;
    }

    private function presentAddress(array $address): array
    {
        return [
            'id' => (int)($address['id'] ?? 0),
            'name' => html_entity_decode((string)($address['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'phone' => html_entity_decode((string)($address['phone'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'country' => html_entity_decode((string)($address['country'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'city' => html_entity_decode((string)($address['city'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'zip' => html_entity_decode((string)($address['zip'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'address' => html_entity_decode((string)($address['address'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'time' => (int)($address['time'] ?? 0),
        ];
    }

    private function earningsPayload(int $userId): array
    {
        global $wo;
        $user = \Wo_UserData($userId);
        if (!is_array($user)) {
            throw new ApiException(404, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $currencyCode = SystemCurrency::code();
        $currency = SystemCurrency::symbol($currencyCode);
        $countries = array_values(array_map(
            static fn($name): string => html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            (array)($wo['countries_name'] ?? [])
        ));
        $affiliateType = (int)($wo['config']['affiliate_type'] ?? 0);
        $withdrawalsEnabled =
            (int)($wo['config']['affiliate_system'] ?? 0) === 1
            || (int)($wo['config']['point_allow_withdrawal'] ?? 0) === 1
            || (int)($wo['config']['funding_system'] ?? 0) === 1
            || (string)($wo['config']['store_system'] ?? '') === 'on';
        return [
            'profile' => $this->presentProfile($user, $userId),
            'balance' => (float)($user['balance'] ?? 0),
            'wallet' => (float)($user['wallet'] ?? 0),
            'points' => (int)($user['points'] ?? 0),
            'paypal_email' => (string)($user['paypal_email'] ?? ''),
            'currency' => $currency,
            'currency_code' => $currencyCode,
            'minimum_withdrawal' => (float)($wo['config']['m_withdrawal'] ?? 0),
            'bank_withdrawal_enabled' => (int)($wo['config']['bank_withdrawal_system'] ?? 0) === 1,
            'countries' => $countries,
            'features' => [
                'affiliates' => (int)($wo['config']['affiliate_system'] ?? 0) === 1,
                'wallet' => true,
                'points' => (int)($wo['config']['point_level_system'] ?? 0) === 1,
                'withdrawals' => $withdrawalsEnabled,
                'invite_friends' => (int)($wo['config']['invite_links_system'] ?? 0) === 1,
                'share' => true,
            ],
            'affiliate' => [
                'link' => rtrim((string)($wo['config']['site_url'] ?? ''), '/')
                    . '/register?ref=' . rawurlencode((string)($user['username'] ?? '')),
                'type' => $affiliateType === 1 ? 'percentage' : 'fixed',
                'amount' => $affiliateType === 1
                    ? (float)($wo['config']['amount_percent_ref'] ?? 0)
                    : (float)($wo['config']['amount_ref'] ?? 0),
            ],
            'point_rewards' => [
                'comment' => (float)($wo['config']['comments_point'] ?? 0),
                'post' => (float)($wo['config']['createpost_point'] ?? 0),
                'reaction' => (float)($wo['config']['reaction_point']
                    ?? $wo['config']['likes_point']
                    ?? $wo['config']['wonders_point']
                    ?? 0),
                'blog' => (float)($wo['config']['createblog_point'] ?? 0),
            ],
            'site_url' => (string)($wo['config']['site_url'] ?? ''),
        ];
    }

    /**
     * Maps a feeling/activity selection to the post-row fields, mirroring the
     * web new_post.php. `feeling` must be a valid feelingIcons key; the activity
     * types (traveling/watching/playing/listening) store free text.
     */
    private function feelingFields(string $feelingType, string $feeling): array
    {
        global $wo;
        $feeling = trim($feeling);
        if ($feeling === '' || $feelingType === '') {
            return [];
        }
        return match ($feelingType) {
            'feelings' => (is_array($wo['feelingIcons'] ?? null)
                    && array_key_exists($feeling, $wo['feelingIcons']))
                ? ['postFeeling' => \Wo_Secure($feeling)]
                : [],
            'traveling' => ['postTraveling' => \Wo_Secure($feeling)],
            'watching' => ['postWatching' => \Wo_Secure($feeling)],
            'playing' => ['postPlaying' => \Wo_Secure($feeling)],
            'listening' => ['postListening' => \Wo_Secure($feeling)],
            default => [],
        };
    }

    /**
     * Handles a single uploaded file field (postFile any-type / postMusic
     * mp3,wav) via Wo_ShareFile, like the web new_post.php. Returns
     * [filename|null, name].
     */
    private function uploadSingleFile(string $field, string $types): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || empty($f['name']) || is_array($f['name'])
            || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) $f['tmp_name'])) {
            return [null, ''];
        }
        $info = [
            'file' => $f['tmp_name'],
            'name' => $f['name'],
            'size' => $f['size'],
            'type' => $f['type'],
        ];
        if ($types !== '') {
            $info['types'] = $types;
        }
        $shared = \Wo_ShareFile($info);
        if (!is_array($shared) || empty($shared['filename'])) {
            throw new ApiException(422, 'POST_FAILED', 'The file could not be uploaded.', $field);
        }
        return [(string) $shared['filename'], (string) ($shared['name'] ?? $f['name'])];
    }

    /**
     * Handles a single `postVideo` upload (+ optional `video_thumb` poster)
     * exactly like the web new_post.php. Returns [filename|null, name, thumb].
     */
    private function uploadVideo(): array
    {
        $video = $_FILES['postVideo'] ?? null;
        if (!is_array($video) || empty($video['name'])
            || (int) ($video['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) $video['tmp_name'])) {
            return [null, '', ''];
        }

        $mime = @mime_content_type((string) $video['tmp_name']) ?: (string) ($video['type'] ?? '');
        $isVideo = str_starts_with($mime, 'video/');
        if (!$isVideo
            || !\Wo_IsFfmpegFileAllowed((string) $video['name'])
            || \Wo_IsVideoNotAllowedMime((string) ($video['type'] ?? ''))) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'This video type is not allowed.', 'video');
        }

        $ffmpegReady = function_exists('Ramza_FfmpegEnabled') && \Ramza_FfmpegEnabled();
        $fileInfo = [
            'file' => $video['tmp_name'],
            'name' => $video['name'],
            'size' => $video['size'],
            'type' => $video['type'],
        ];
        $snapshot = null;
        if ($ffmpegReady) {
            $fileInfo['is_video'] = 1;
            if (function_exists('Wo_SuspendRemoteStorage')) {
                $snapshot = \Wo_SuspendRemoteStorage();
            }
        } else {
            $fileInfo['types'] = 'mp4,m4v,webm,flv,mov,mpeg,mkv';
        }
        $shared = \Wo_ShareFile($fileInfo);
        if ($snapshot !== null && function_exists('Wo_RestoreRemoteStorage')) {
            \Wo_RestoreRemoteStorage($snapshot);
        }
        if (!is_array($shared) || empty($shared['filename'])) {
            throw new ApiException(422, 'POST_FAILED', 'The video could not be uploaded.', 'video');
        }
        $filename = (string) $shared['filename'];
        $name = (string) ($shared['name'] ?? $video['name']);

        // Optional client-supplied poster frame (cropped like the web).
        $thumb = $_FILES['video_thumb'] ?? null;
        $thumbFile = '';
        if (is_array($thumb) && !empty($thumb['name'])
            && (int) ($thumb['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string) $thumb['tmp_name'])
            && in_array((string) ($thumb['type'] ?? ''), ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'], true)) {
            $sharedThumb = \Wo_ShareFile([
                'file' => $thumb['tmp_name'],
                'name' => $thumb['name'],
                'size' => $thumb['size'],
                'type' => $thumb['type'],
                'types' => 'jpeg,png,jpg,gif',
                'crop' => ['width' => 525, 'height' => 295],
            ]);
            if (is_array($sharedThumb) && !empty($sharedThumb['filename'])) {
                $thumbFile = (string) $sharedThumb['filename'];
            }
        }

        return [$filename, $name, $thumbFile];
    }

    private function assertPostVisible(int $postId): void
    {
        if ($postId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'A valid post id is required.', 'id');
        }
        $post = \Wo_PostData($postId);
        if (!is_array($post) || empty($post['post_id'] ?? $post['id'] ?? 0)) {
            throw new ApiException(404, 'POST_NOT_FOUND', 'This post is not available.');
        }
    }

    private function reactionSummary(int $postId): array
    {
        $reactions = function_exists('Wo_GetPostReactionsTypes')
            ? \Wo_GetPostReactionsTypes($postId)
            : [];
        if (!is_array($reactions)) {
            $reactions = [];
        }
        $types = [];
        for ($type = 1; $type <= 6; $type++) {
            if (isset($reactions[$type]) || isset($reactions[(string) $type])) {
                $types[(string) $type] = 1;
            }
        }
        return [
            'count' => (int) ($reactions['count'] ?? 0),
            'selected' => !empty($reactions['is_reacted']) ? (string) ($reactions['type'] ?? '') : null,
            'types' => $types,
        ];
    }

    private function tagPrefix(array $ids, int $viewerId): string
    {
        $parts = [];
        foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 20) as $id) {
            if ($id < 1 || $id === $viewerId) continue;
            $user = \Wo_UserData($id);
            if (is_array($user) && !empty($user['username'])) {
                $parts[] = '@[' . $id . ']';
            }
        }
        return empty($parts) ? '' : implode(' ', $parts) . ' ';
    }

    private function presentComment(array $comment, int $viewerId): array
    {
        $publisher = is_array($comment['publisher'] ?? null) ? $comment['publisher'] : [];
        $authorId = (int) ($publisher['user_id'] ?? $comment['user_id'] ?? 0);
        $rankScore = (int)($comment['comment_likes'] ?? 0) * 3
            + (int)($comment['replies_count'] ?? 0) * 2;
        if ($authorId === $viewerId) {
            $rankScore += 10000;
        } elseif ($authorId > 0 && function_exists('Wo_IsFollowing') && \Wo_IsFollowing($authorId, $viewerId)) {
            $rankScore += 1000;
        }
        return [
            'id' => (int) ($comment['id'] ?? 0),
            'text' => $this->plainText((string) ($comment['Orginaltext'] ?? $comment['text'] ?? '')),
            'created_at' => (int) ($comment['time'] ?? 0),
            'author' => [
                'id' => $authorId,
                'username' => (string) ($publisher['username'] ?? ''),
                'name' => $this->displayName($publisher),
                'avatar' => $this->mediaUrl((string) ($publisher['avatar'] ?? '')),
                'verified' => (int) ($publisher['verified'] ?? 0) === 1,
            ],
            'likes_count' => (int) ($comment['comment_likes'] ?? 0),
            'is_liked' => !empty($comment['is_comment_liked']),
            'replies_count' => (int) ($comment['replies_count'] ?? 0),
            'can_delete' => $authorId === $viewerId || !empty($comment['post_onwer']),
            'can_edit' => $authorId === $viewerId,
            'is_edited' => $this->commentEditedAt((int)($comment['id'] ?? 0)) > 0,
            'rank_score' => $rankScore,
        ];
    }

    private function ensureCommentEditsTable(): void
    {
        global $sqlConnect;
        static $ready = false;
        if ($ready) return;
        mysqli_query($sqlConnect, "CREATE TABLE IF NOT EXISTS `Wo_RamzaCommentEdits` (
            `comment_id` INT UNSIGNED NOT NULL,
            `edited_at` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`comment_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    }

    private function commentEditedAt(int $commentId): int
    {
        global $sqlConnect;
        if ($commentId < 1) return 0;
        $this->ensureCommentEditsTable();
        $query = mysqli_query($sqlConnect, "SELECT `edited_at` FROM `Wo_RamzaCommentEdits` WHERE `comment_id` = " . $commentId . " LIMIT 1");
        $row = $query ? mysqli_fetch_assoc($query) : null;
        return is_array($row) ? (int)($row['edited_at'] ?? 0) : 0;
    }

    private function presentReply(array $reply, int $viewerId): array
    {
        $publisher = is_array($reply['publisher'] ?? null) ? $reply['publisher'] : [];
        $authorId = (int)($publisher['user_id'] ?? $reply['user_id'] ?? 0);
        return [
            'id' => (int)($reply['id'] ?? 0),
            'text' => $this->plainText((string)($reply['Orginaltext'] ?? $reply['text'] ?? '')),
            'created_at' => (int)($reply['time'] ?? 0),
            'author' => [
                'id' => $authorId,
                'username' => (string)($publisher['username'] ?? ''),
                'name' => $this->displayName($publisher),
                'avatar' => $this->mediaUrl((string)($publisher['avatar'] ?? '')),
                'verified' => (int)($publisher['verified'] ?? 0) === 1,
            ],
            'likes_count' => (int)($reply['comment_likes'] ?? 0),
            'is_liked' => !empty($reply['is_comment_liked']),
            'replies_count' => 0,
            'can_delete' => $authorId === $viewerId || !empty($reply['post_onwer']),
        ];
    }

    private function presentProfile(array $user, int $viewerId): array
    {
        global $sqlConnect, $wo;
        $targetId = (int) $user['user_id'];
        $isSelf = $targetId === $viewerId;
        $following = function_exists('Wo_IsFollowing') && (bool) \Wo_IsFollowing($targetId, $viewerId);
        $friendPrivacy = (string) ($user['friend_privacy'] ?? '0');
        $canSeeConnections = $isSelf || $friendPrivacy === '0' || $friendPrivacy === '2' || $following;

        $followingCards = [];
        if ($canSeeConnections && function_exists('Wo_GetFollowing')) {
            $rows = \Wo_GetFollowing($targetId, 'profile', 6);
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row) || empty($row['user_id'])) continue;
                $followingCards[] = [
                    'id' => (int) $row['user_id'],
                    'username' => (string) ($row['username'] ?? ''),
                    'name' => $this->displayName($row),
                    'avatar' => $this->mediaUrl((string) ($row['avatar'] ?? '')),
                    'verified' => (int) ($row['verified'] ?? 0) === 1,
                ];
            }
        }

        $pages = [];
        if ($canSeeConnections && defined('T_PAGES_LIKES') && defined('T_PAGES')) {
            $result = mysqli_query(
                $sqlConnect,
                'SELECT `page_id` FROM ' . T_PAGES_LIKES
                . " WHERE `user_id`={$targetId} AND `active`='1' ORDER BY `id` DESC LIMIT 12"
            );
            while ($result && ($pageRow = mysqli_fetch_assoc($result))) {
                $page = \Wo_PageData((int) $pageRow['page_id']);
                if (!is_array($page)) continue;
                $pages[] = [
                    'id' => (int) ($page['page_id'] ?? 0),
                    'name' => (string) ($page['page_title'] ?? $page['page_name'] ?? ''),
                    'avatar' => $this->mediaUrl((string) ($page['avatar'] ?? '')),
                ];
            }
        }

        $groups = [];
        if ($canSeeConnections && function_exists('Wo_GetUsersGroups')) {
            foreach ((array) \Wo_GetUsersGroups($targetId, 12) as $group) {
                if (!is_array($group) || empty($group['group_id'])) continue;
                $groups[] = [
                    'id' => (int) $group['group_id'],
                    'name' => (string) ($group['group_title'] ?? $group['group_name'] ?? ''),
                    'avatar' => $this->mediaUrl((string) ($group['avatar'] ?? '')),
                ];
            }
        }

        $photos = [];
        if (function_exists('Wo_GetPosts')) {
            $photoRows = \Wo_GetPosts([
                'filter_by' => 'photos',
                'publisher_id' => $targetId,
                'limit' => 9,
                'after_post_id' => 0,
                'placement' => 'multi_image_post',
                // Wo_GetPosts defaults to reels-only in this script version.
                // Xamarin's GetPostByType("photos") requests normal profile
                // media, so opt out of that default explicitly.
                'is_reel' => 'disable',
            ]);
            foreach (is_array($photoRows) ? $photoRows : [] as $post) {
                if (!is_array($post)) continue;
                $file = '';
                foreach (['postFile_full', 'postFileFull', 'postFile'] as $key) {
                    $candidate = trim((string) ($post[$key] ?? ''));
                    if ($candidate !== '') {
                        $file = $candidate;
                        break;
                    }
                }
                // Album and multi-image parent posts may not have postFile,
                // but Xamarin still uses their first returned image as the
                // profile-media tile.
                if ($file === '') {
                    $album = is_array($post['photo_album'] ?? null)
                        ? $post['photo_album']
                        : (is_array($post['photo_multi'] ?? null) ? $post['photo_multi'] : []);
                    $first = reset($album);
                    if (is_array($first)) {
                        $file = (string) ($first['image'] ?? '');
                    }
                }
                if ($file === '') continue;
                $thumbnail = '';
                foreach (['postFileThumb', 'postFile_thumb'] as $key) {
                    $candidate = trim((string) ($post[$key] ?? ''));
                    if ($candidate !== '') {
                        $thumbnail = $candidate;
                        break;
                    }
                }
                if ($thumbnail === '') $thumbnail = $file;
                $photos[] = [
                    'id' => (int) ($post['id'] ?? 0),
                    'url' => $this->mediaUrl($file),
                    'thumbnail' => $this->mediaUrl($thumbnail),
                ];
            }
        }

        $countryId = (string) ($user['country_id'] ?? '0');
        $countryName = is_array($wo['countries_name'] ?? null)
            ? (string) ($wo['countries_name'][$countryId] ?? '')
            : '';
        $relationshipId = (int) ($user['relationship_id'] ?? 0);
        $relationship = [1 => 'Single', 2 => 'In a relationship', 3 => 'Married', 4 => 'Engaged'][$relationshipId] ?? '';
        $reported = function_exists('Wo_IsReportExists') && (bool) \Wo_IsReportExists($targetId, 'user');
        $poked = function_exists('Wo_IsPoked') && (bool) \Wo_IsPoked($targetId, $viewerId);
        $hasStory = false;
        if (defined('T_USER_STORY')) {
            $storyResult = mysqli_query(
                $sqlConnect,
                'SELECT `id` FROM ' . T_USER_STORY . " WHERE `user_id`={$targetId} AND `expire`>" . time() . ' LIMIT 1'
            );
            $hasStory = $storyResult && mysqli_num_rows($storyResult) > 0;
        }
        return array_merge([
            'id' => $targetId,
            'username' => (string) ($user['username'] ?? ''),
            'name' => $this->displayName($user),
            'first_name' => (string) ($user['first_name'] ?? ''),
            'last_name' => (string) ($user['last_name'] ?? ''),
            'avatar' => $this->mediaUrl((string) ($user['avatar'] ?? '')),
            'cover' => $this->mediaUrl((string) ($user['cover'] ?? '')),
            'verified' => (int) ($user['verified'] ?? 0) === 1,
            'is_pro' => (int) ($user['is_pro'] ?? 0) === 1,
            'pro_type' => (string) ($user['pro_type'] ?? '1'),
            'about' => $this->plainText((string) ($user['about'] ?? '')),
            // Xamarin refreshes these through Wo_UpdateUserDetails, whose
            // values come from the live count functions. Reading the cached
            // `details` JSON here made the count disagree with the preview
            // list until the web profile cache happened to be refreshed.
            'followers_count' => function_exists('Wo_CountFollowers')
                ? (int) \Wo_CountFollowers($targetId)
                : 0,
            'following_count' => function_exists('Wo_CountFollowing')
                ? (int) \Wo_CountFollowing($targetId)
                : 0,
            'posts_count' => function_exists('Wo_CountUserPosts')
                ? (int) \Wo_CountUserPosts($targetId)
                : 0,
            'likes_count' => function_exists('Wo_CountUserLikes')
                ? (int) \Wo_CountUserLikes($targetId)
                : 0,
            'points' => (int) ($user['points'] ?? 0),
            'last_seen' => (int) ($user['lastseen'] ?? 0),
            'url' => (string) ($user['url'] ?? ''),
            'website' => (string) ($user['website'] ?? ''),
            'address' => (string) ($user['address'] ?? ''),
            'phone_number' => $isSelf ? (string) ($user['phone_number'] ?? '') : '',
            'gender' => (string) ($user['gender'] ?? ''),
            'gender_text' => (string) ($user['gender_text'] ?? $user['gender'] ?? ''),
            'birthday' => (string) ($user['birth_privacy'] ?? '0') === '2' ? '' : (string) ($user['birthday'] ?? ''),
            'working' => (string) ($user['working'] ?? ''),
            'country_name' => $countryName,
            'school' => (string) ($user['school'] ?? ''),
            'relationship' => $relationship,
            'relationship_id' => $relationshipId,
            'social_links' => [
                'facebook' => (string) ($user['facebook'] ?? ''),
                'instagram' => (string) ($user['instagram'] ?? ''),
                'twitter' => (string) ($user['twitter'] ?? ''),
                'google' => (string) ($user['google'] ?? ''),
                'vk' => (string) ($user['vk'] ?? ''),
                'linkedin' => (string) ($user['linkedin'] ?? ''),
                'youtube' => (string) ($user['youtube'] ?? ''),
            ],
            'following_preview' => $followingCards,
            'photos_preview' => $photos,
            'pages_preview' => $pages,
            'groups_preview' => $groups,
            'is_reported' => $reported,
            'is_poked' => $poked,
            'has_story' => $hasStory,
            'is_self' => $isSelf,
        ], $this->relation($viewerId, $targetId));
    }

    private function presentAlbum(int $albumId, int $viewerId): ?array
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare(
            'SELECT id,user_id,album_name,time,postPrivacy,active FROM ' . T_POSTS
            . " WHERE id=? AND album_name<>'' AND active='1' LIMIT 1"
        );
        $stmt->bind_param('i', $albumId);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($post)) return null;
        $ownerId = (int) $post['user_id'];
        if ($ownerId !== $viewerId && (string) $post['postPrivacy'] !== '0') return null;
        $media = $sqlConnect->prepare(
            'SELECT id,post_id,image FROM ' . T_ALBUMS_MEDIA . ' WHERE post_id=? ORDER BY id DESC'
        );
        $media->bind_param('i', $albumId);
        $media->execute();
        $rows = $media->get_result()->fetch_all(MYSQLI_ASSOC);
        $media->close();
        $photos = [];
        foreach ($rows as $row) {
            $photos[] = [
                'id' => (int) $row['id'],
                'post_id' => (int) $row['post_id'],
                'image' => $this->mediaUrl((string) $row['image']),
            ];
        }
        if ($photos === []) return null;
        return [
            'id' => $albumId,
            'user_id' => $ownerId,
            'name' => html_entity_decode((string) $post['album_name'], ENT_QUOTES | ENT_HTML5),
            'time' => (int) $post['time'],
            'is_owner' => $ownerId === $viewerId,
            'cover' => (string) $photos[0]['image'],
            'photos' => $photos,
        ];
    }

    /** Private self-only extension of the public profile payload. */
    private function privateAccount(array $user, int $viewerId): array
    {
        global $wo;
        $countries = [];
        foreach ((array)($wo['countries_name'] ?? []) as $id => $name) {
            $countries[] = ['id' => (int)$id, 'name' => html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5)];
        }
        return array_merge($this->presentProfile($user, $viewerId), [
            'email' => (string)($user['email'] ?? ''),
            'birthday' => (string)($user['birthday'] ?? ''),
            'gender' => (string)($user['gender'] ?? ''),
            'gender_text' => (string)($user['gender_text'] ?? $user['gender'] ?? ''),
            'country_id' => (int)($user['country_id'] ?? 0),
            'two_factor' => (int)($user['two_factor'] ?? 0) === 1,
            'two_factor_verified' => (int)($user['two_factor_verified'] ?? 0) === 1,
            'two_factor_method' => (string)($user['two_factor_method'] ?? 'two_factor'),
            'countries' => $countries,
        ]);
    }

    private function privacySettings(array $user): array
    {
        $postPrivacy = (string)($user['post_privacy'] ?? 'everyone');
        if (!in_array($postPrivacy, ['everyone', 'ifollow', 'nobody'], true)) {
            $postPrivacy = 'everyone';
        }
        return [
            'follow_privacy' => in_array((string)($user['follow_privacy'] ?? '0'), ['0', '1'], true)
                ? (string)$user['follow_privacy'] : '0',
            'message_privacy' => in_array((string)($user['message_privacy'] ?? '0'), ['0', '1', '2'], true)
                ? (string)$user['message_privacy'] : '0',
            'friend_privacy' => in_array((string)($user['friend_privacy'] ?? '0'), ['0', '1', '2', '3'], true)
                ? (string)$user['friend_privacy'] : '0',
            'post_privacy' => $postPrivacy,
            'birth_privacy' => in_array((string)($user['birth_privacy'] ?? '0'), ['0', '1', '2'], true)
                ? (string)$user['birth_privacy'] : '0',
            'confirm_followers' => (string)($user['confirm_followers'] ?? '0') === '1',
            'show_activities_privacy' => (string)($user['show_activities_privacy'] ?? '1') === '1',
            // Xamarin treats status=0 as visible online and status=1 as hidden.
            'show_online_users' => (string)($user['status'] ?? '0') === '0',
            'share_my_location' => (string)($user['share_my_location'] ?? '1') === '1',
        ];
    }

    private function notificationSettings(array $user): array
    {
        $fields = [
            'e_liked', 'e_commented', 'e_shared', 'e_followed', 'e_liked_page',
            'e_visited', 'e_mentioned', 'e_joined_group', 'e_accepted',
            'e_profile_wall_post', 'e_memory',
        ];
        $source = $user['API_notification_settings'] ?? $user['notification_settings'] ?? [];
        if (is_string($source)) {
            $decoded = json_decode(html_entity_decode($source, ENT_QUOTES | ENT_HTML5), true);
            $source = is_array($decoded) ? $decoded : [];
        } elseif (is_object($source)) {
            $source = (array)$source;
        }
        if (!is_array($source)) {
            $source = [];
        }
        $settings = [];
        foreach ($fields as $field) {
            $fallback = $field !== 'e_memory' ? ($user[$field] ?? '1') : '1';
            $settings[$field] = (string)($source[$field] ?? $fallback) === '1';
        }
        return $settings;
    }

    /** Normalizes PHP's photos[] upload shape and validates image content. */
    private function albumFiles(?array $uploads): array
    {
        if (!is_array($uploads) || !isset($uploads['name'])) return [];
        $names = is_array($uploads['name']) ? $uploads['name'] : [$uploads['name']];
        $tmp = is_array($uploads['tmp_name'] ?? null) ? $uploads['tmp_name'] : [$uploads['tmp_name'] ?? ''];
        $sizes = is_array($uploads['size'] ?? null) ? $uploads['size'] : [$uploads['size'] ?? 0];
        $errors = is_array($uploads['error'] ?? null) ? $uploads['error'] : [$uploads['error'] ?? UPLOAD_ERR_NO_FILE];
        $files = [];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($names as $index => $name) {
            $path = (string) ($tmp[$index] ?? '');
            if ((int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($path)) continue;
            $mime = (string) ($finfo->file($path) ?: '');
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
                throw new ApiException(422, 'ALBUM_MEDIA_INVALID', 'Only JPG, PNG and GIF images are supported.', 'photos');
            }
            if ((int) ($sizes[$index] ?? 0) > 15 * 1024 * 1024) {
                throw new ApiException(413, 'ALBUM_MEDIA_TOO_LARGE', 'An album image is too large.', 'photos');
            }
            $files[] = ['tmp_name' => $path, 'name' => (string) $name, 'size' => (int) $sizes[$index], 'type' => $mime];
        }
        return $files;
    }

    private function storeAlbumFiles(int $postId, array $files): int
    {
        $created = 0;
        foreach ($files as $file) {
            $shared = \Wo_ShareFile([
                'file' => $file['tmp_name'], 'name' => $file['name'],
                'size' => $file['size'], 'type' => $file['type'],
                'types' => 'jpg,png,jpeg,gif',
            ], 1);
            if (is_array($shared) && !empty($shared['filename'])
                && \Wo_RegisterAlbumMedia($postId, (string) $shared['filename'])) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * Relationship of the viewer toward the target, valid in both follow and
     * friend connectivity modes.
     *   relation: none | requested | following   (UI maps 'following' → 'Friends' in friend mode)
     */
    private function relation(int $viewerId, int $targetId): array
    {
        if ($targetId === $viewerId || $targetId < 1) {
            return [
                'relation' => 'self',
                'is_following' => false,
                'is_requested' => false,
                'follows_you' => false,
                'incoming_request' => false,
            ];
        }
        $following = function_exists('Wo_IsFollowing') && (bool) \Wo_IsFollowing($targetId, $viewerId);
        $requested = function_exists('Wo_IsFollowRequested') && (bool) \Wo_IsFollowRequested($targetId, $viewerId);
        $followsYou = function_exists('Wo_IsFollowing') && (bool) \Wo_IsFollowing($viewerId, $targetId);
        $incoming = function_exists('Wo_IsFollowRequested') && (bool) \Wo_IsFollowRequested($viewerId, $targetId);

        $relation = $following ? 'following' : ($requested ? 'requested' : 'none');
        return [
            'relation' => $relation,
            'is_following' => $following,
            'is_requested' => $requested,
            'follows_you' => $followsYou,
            'incoming_request' => $incoming,
        ];
    }

    private function displayName(array $entity): string
    {
        $name = trim((string) ($entity['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = trim((string) ($entity['first_name'] ?? '') . ' ' . (string) ($entity['last_name'] ?? ''));
        return $name !== '' ? $name : (string) ($entity['username'] ?? '');
    }

    private function plainText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function mediaUrl(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return function_exists('Wo_GetMedia') ? (string) \Wo_GetMedia($value) : $value;
    }

    private function encodeCursor(int $userId, int $afterId, string $scope): string
    {
        $json = json_encode([
            'v' => 1,
            'u' => $userId,
            'a' => $afterId,
            's' => $scope,
            'e' => time() + self::CURSOR_TTL,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $encoded . '.' . $this->security->hash('interaction-cursor:' . $encoded);
    }

    private function decodeCursor(?string $cursor, int $userId, string $scope): int
    {
        if ($cursor === null || trim($cursor) === '') {
            return 0;
        }
        if (strlen($cursor) > 500 ||
            preg_match('/^(?<data>[A-Za-z0-9_-]+)\.(?<mac>[a-f0-9]{64})$/', $cursor, $parts) !== 1) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The cursor is invalid.', 'cursor');
        }
        if (!hash_equals($this->security->hash('interaction-cursor:' . $parts['data']), $parts['mac'])) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The cursor is invalid.', 'cursor');
        }
        $padded = $parts['data'] . str_repeat('=', (4 - strlen($parts['data']) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload)
            || (int) ($payload['v'] ?? 0) !== 1
            || (int) ($payload['u'] ?? 0) !== $userId
            || (string) ($payload['s'] ?? '') !== $scope
            || (int) ($payload['e'] ?? 0) < time()
            || (int) ($payload['a'] ?? 0) < 1) {
            throw new ApiException(422, 'CURSOR_INVALID', 'The cursor is invalid.', 'cursor');
        }
        return (int) $payload['a'];
    }
}
