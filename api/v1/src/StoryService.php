<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/** Mobile story lifecycle matching the Xamarin client. */
final class StoryService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly FeedService $feed
    ) {
    }

    public function groups(string $token, string $clientId, int $userId = 0, int $limit = 30): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_stories_read', 120, 60);
        $limit = max(1, min(50, $limit));
        global $sqlConnect, $wo;
        $now = time();
        if ($userId > 0) {
            $sql = 'SELECT DISTINCT s.user_id FROM ' . T_USER_STORY . ' s JOIN ' . T_USERS
                . ' u ON u.user_id=s.user_id WHERE s.user_id=? AND u.active=\'1\''
                . ' AND CAST(s.expire AS UNSIGNED)>? ORDER BY s.user_id DESC LIMIT ?';
            $stmt = $sqlConnect->prepare($sql);
            $stmt->bind_param('iii', $userId, $now, $limit);
        } elseif (!empty($wo['config']['connectivitySystem']) && (int) $wo['config']['connectivitySystem'] === 1) {
            $sql = 'SELECT DISTINCT s.user_id FROM ' . T_USER_STORY . ' s JOIN ' . T_USERS
                . ' u ON u.user_id=s.user_id WHERE (s.user_id=? OR s.user_id IN ('
                . 'SELECT following_id FROM ' . T_FOLLOWERS . ' WHERE follower_id=? AND active=1 UNION '
                . 'SELECT follower_id FROM ' . T_FOLLOWERS . ' WHERE following_id=? AND active=1'
                . ')) AND u.active=\'1\' AND CAST(s.expire AS UNSIGNED)>? GROUP BY s.user_id'
                . ' ORDER BY (s.user_id=?) DESC, MAX(s.id) DESC LIMIT ?';
            $stmt = $sqlConnect->prepare($sql);
            $stmt->bind_param('iiiiii', $viewerId, $viewerId, $viewerId, $now, $viewerId, $limit);
        } else {
            $sql = 'SELECT DISTINCT s.user_id FROM ' . T_USER_STORY . ' s JOIN ' . T_USERS
                . ' u ON u.user_id=s.user_id WHERE (s.user_id=? OR s.user_id IN (SELECT following_id FROM '
                . T_FOLLOWERS . ' WHERE follower_id=? AND active=1)) AND u.active=\'1\''
                . ' AND CAST(s.expire AS UNSIGNED)>? GROUP BY s.user_id'
                . ' ORDER BY (s.user_id=?) DESC, MAX(s.id) DESC LIMIT ?';
            $stmt = $sqlConnect->prepare($sql);
            $stmt->bind_param('iiiii', $viewerId, $viewerId, $now, $viewerId, $limit);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $groups = [];
        foreach ($rows as $row) {
            $group = $this->group((int) $row['user_id'], $viewerId, $now);
            if ($group !== null && $group['stories'] !== []) {
                $groups[] = $group;
            }
        }
        return $groups;
    }

    public function create(
        string $token,
        string $clientId,
        string $type,
        string $caption,
        ?array $file,
        ?array $cover
    ): array {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_story_create', 12, 3600);
        $type = strtolower(trim($type));
        $caption = trim($caption);
        if (!in_array($type, ['image', 'video'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a photo or video.', 'file_type');
        }
        if (mb_strlen($caption) > 300) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The caption is too long.', 'caption');
        }
        if (!$this->validUpload($file)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The story file is required.', 'file');
        }
        $allowed = $type === 'video'
            ? ['video/mp4', 'video/quicktime', 'video/webm']
            : ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: '';
        if (!in_array($mime, $allowed, true)) {
            throw new ApiException(422, 'STORY_MEDIA_INVALID', 'This story file type is not supported.', 'file');
        }
        $max = $type === 'video' ? 100 * 1024 * 1024 : 15 * 1024 * 1024;
        if ((int) $file['size'] > $max) {
            throw new ApiException(413, 'STORY_MEDIA_TOO_LARGE', 'The story file is too large.', 'file');
        }
        $now = time();
        $storyId = \Wo_InsertUserStory([
            'user_id' => $viewerId,
            'posted' => $now,
            'expire' => $now + 86400,
            'title' => '',
            'description' => \Wo_Secure($caption),
        ]);
        if (!is_numeric($storyId) || (int) $storyId < 1) {
            throw new ApiException(422, 'STORY_CREATE_FAILED', 'The story could not be created.');
        }
        $storyId = (int) $storyId;
        try {
            $media = \Wo_ShareFile([
                'file' => $file['tmp_name'], 'name' => $file['name'],
                'size' => $file['size'], 'type' => $mime,
                'types' => 'jpg,png,gif,jpeg,webp,mp4,mov,webm',
            ]);
            if (!is_array($media) || empty($media['filename'])) {
                throw new \RuntimeException('upload');
            }
            if (!\Wo_InsertUserStoryMedia([
                'story_id' => $storyId, 'type' => $type,
                'filename' => $media['filename'], 'expire' => $now + 86400,
            ])) {
                throw new \RuntimeException('media');
            }
            $thumbnail = $type === 'image' ? (string) $media['filename'] : '';
            if ($type === 'video' && $this->validUpload($cover)) {
                $coverMedia = \Wo_ShareFile([
                    'file' => $cover['tmp_name'], 'name' => $cover['name'],
                    'size' => $cover['size'], 'type' => $cover['type'],
                    'types' => 'jpg,png,jpeg,webp',
                ]);
                if (is_array($coverMedia) && !empty($coverMedia['filename'])) {
                    $thumbnail = (string) $coverMedia['filename'];
                }
            }
            if ($thumbnail !== '') {
                global $sqlConnect;
                $stmt = $sqlConnect->prepare('UPDATE ' . T_USER_STORY . ' SET thumbnail=? WHERE id=?');
                $stmt->bind_param('si', $thumbnail, $storyId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable) {
            \Wo_DeleteStatus($storyId);
            throw new ApiException(422, 'STORY_UPLOAD_FAILED', 'The story media could not be uploaded.');
        }
        $story = $this->story($storyId, $viewerId);
        if ($story === null) {
            throw new ApiException(500, 'STORY_CREATE_FAILED', 'The story was created but could not be loaded.');
        }
        return $story;
    }

    public function seen(string $token, string $clientId, int $storyId): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_story_seen', 180, 60);
        $story = $this->storyRow($storyId);
        if ($story === null) throw new ApiException(404, 'STORY_NOT_FOUND', 'This story is no longer available.');
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('INSERT IGNORE INTO ' . T_STORY_SEEN . ' (story_id,user_id,time) VALUES (?,?,?)');
        $now = time();
        $stmt->bind_param('iii', $storyId, $viewerId, $now);
        $stmt->execute();
        $stmt->close();
        return ['view_count' => $this->viewCount($storyId, (int) $story['user_id'])];
    }

    public function react(string $token, string $clientId, int $storyId, int $reaction): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_story_react', 90, 60);
        $story = $this->storyRow($storyId);
        if ($reaction < 1 || $reaction > 6 || $story === null) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The story reaction is invalid.', 'reaction');
        }
        global $db;
        $db->where('user_id', $viewerId)->where('story_id', $storyId)->delete(T_REACTIONS);
        $db->insert(T_REACTIONS, ['user_id' => $viewerId, 'story_id' => $storyId, 'reaction' => $reaction]);
        $ownerId = (int) $story['user_id'];
        if ($ownerId !== $viewerId && function_exists('Wo_RegisterNotification')) {
            \Wo_RegisterNotification([
                'recipient_id' => $ownerId,
                'story_id' => $storyId,
                'type' => 'reaction',
                'text' => 'story',
                'type2' => 'story',
                'url' => 'index.php?link1=story&id=' . $storyId,
            ]);
        }
        return ['reaction' => $reaction];
    }

    public function reply(string $token, string $clientId, int $storyId, string $text): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_story_reply', 60, 60);
        $story = $this->storyRow($storyId);
        $text = trim($text);
        if ($story === null) throw new ApiException(404, 'STORY_NOT_FOUND', 'This story is no longer available.');
        if ($text === '' || mb_strlen($text) > 10000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Write a message.', 'text');
        }
        $ownerId = (int) $story['user_id'];
        if ($ownerId === $viewerId) throw new ApiException(422, 'STORY_REPLY_SELF', 'You cannot reply to your own story.');
        $id = \Wo_RegisterMessage([
            'from_id' => $viewerId, 'to_id' => $ownerId, 'time' => time(),
            'text' => \Wo_Secure($text), 'media' => '', 'mediaFileName' => '',
            'stickers' => '', 'story_id' => $storyId,
        ]);
        if (!is_numeric($id) || (int) $id < 1) {
            throw new ApiException(422, 'STORY_REPLY_FAILED', 'The reply could not be sent.');
        }
        return ['message_id' => (int) $id, 'story_id' => $storyId];
    }

    public function views(string $token, string $clientId, int $storyId, int $limit): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_story_views', 90, 60);
        $story = $this->storyRow($storyId);
        if ($story === null || (int) $story['user_id'] !== $viewerId) {
            throw new ApiException(403, 'STORY_VIEWS_DENIED', 'Only the story owner can view this list.');
        }
        global $sqlConnect;
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT u.* FROM ' . T_STORY_SEEN . ' v JOIN ' . T_USERS
            . ' u ON u.user_id=v.user_id WHERE v.story_id=? AND v.user_id<>? ORDER BY v.id DESC LIMIT ?';
        $stmt = $sqlConnect->prepare($sql);
        $stmt->bind_param('iii', $storyId, $viewerId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(fn(array $u): array => $this->user($u), $rows);
    }

    public function delete(string $token, string $clientId, int $storyId): array
    {
        $this->authenticate($token, $clientId, 'mobile_story_delete', 30, 60);
        if (!\Wo_DeleteStatus($storyId)) {
            throw new ApiException(403, 'STORY_DELETE_DENIED', 'This story cannot be deleted.');
        }
        return ['deleted' => true, 'id' => $storyId];
    }

    private function group(int $userId, int $viewerId, int $now): ?array
    {
        $user = \Wo_UserData($userId);
        if (!is_array($user) || empty($user['user_id'])) return null;
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT id FROM ' . T_USER_STORY
            . ' WHERE user_id=? AND CAST(expire AS UNSIGNED)>? ORDER BY id ASC');
        $stmt->bind_param('ii', $userId, $now);
        $stmt->execute();
        $ids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $stories = [];
        foreach ($ids as $id) {
            $story = $this->story((int) $id['id'], $viewerId);
            if ($story !== null) $stories[] = $story;
        }
        return ['user' => $this->user($user), 'is_self' => $userId === $viewerId, 'stories' => $stories];
    }

    private function story(int $storyId, int $viewerId): ?array
    {
        $row = $this->storyRow($storyId);
        if ($row === null) return null;
        $media = array_merge((array) \Wo_GetStoryMedia($storyId, 'image'), (array) \Wo_GetStoryMedia($storyId, 'video'));
        usort($media, fn(array $a, array $b): int => ((int)$a['id']) <=> ((int)$b['id']));
        if ($media === []) return null;
        $m = $media[0];
        $ownerId = (int) $row['user_id'];
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT reaction FROM ' . T_REACTIONS . ' WHERE story_id=? AND user_id=? LIMIT 1');
        $stmt->bind_param('ii', $storyId, $viewerId);
        $stmt->execute();
        $reaction = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $seenStmt = $sqlConnect->prepare('SELECT id FROM ' . T_STORY_SEEN . ' WHERE story_id=? AND user_id=? LIMIT 1');
        $seenStmt->bind_param('ii', $storyId, $viewerId);
        $seenStmt->execute();
        $seen = (bool) $seenStmt->get_result()->fetch_assoc();
        $seenStmt->close();
        return [
            'id' => $storyId, 'user_id' => $ownerId,
            'caption' => html_entity_decode((string) $row['description'], ENT_QUOTES | ENT_HTML5),
            'type' => (string) $m['type'], 'media_url' => (string) $m['filename'],
            'thumbnail' => $this->media((string) ($row['thumbnail'] ?: ($m['type'] === 'image' ? $m['filename'] : ''))),
            'posted' => (int) $row['posted'], 'expire' => (int) $row['expire'],
            'is_owner' => $ownerId === $viewerId, 'is_viewed' => $seen,
            'view_count' => $this->viewCount($storyId, $ownerId),
            'reaction' => isset($reaction['reaction']) ? (int) $reaction['reaction'] : null,
        ];
    }

    private function storyRow(int $storyId): ?array
    {
        global $sqlConnect;
        $now = time();
        $stmt = $sqlConnect->prepare('SELECT * FROM ' . T_USER_STORY . ' WHERE id=? AND CAST(expire AS UNSIGNED)>? LIMIT 1');
        $stmt->bind_param('ii', $storyId, $now);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function viewCount(int $storyId, int $ownerId): int
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT COUNT(*) n FROM ' . T_STORY_SEEN . ' WHERE story_id=? AND user_id<>?');
        $stmt->bind_param('ii', $storyId, $ownerId);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    }

    private function user(array $u): array
    {
        return [
            'id' => (int) ($u['user_id'] ?? 0), 'username' => (string) ($u['username'] ?? ''),
            'name' => (string) ($u['name'] ?? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))),
            'avatar' => $this->media((string) ($u['avatar'] ?? '')),
            'verified' => (int) ($u['verified'] ?? 0) === 1, 'is_pro' => (int) ($u['is_pro'] ?? 0) === 1,
        ];
    }

    private function media(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) return $value;
        return (string) \Wo_GetMedia($value);
    }

    private function validUpload(?array $file): bool
    {
        return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string) ($file['tmp_name'] ?? ''));
    }

    private function authenticate(string $token, string $clientId, string $bucket, int $limit, int $window): int
    {
        $session = $this->tokens->authenticate($token, $clientId);
        $userId = (int) $session['user_id'];
        $this->rateLimiter->enforce($bucket, (string) $userId, $limit, $window);
        $this->feed->bootstrapWebContext($userId);
        return $userId;
    }
}
