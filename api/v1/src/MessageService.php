<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/**
 * Secure mobile facade over WoWonder's existing one-to-one chat engine.
 *
 * The legacy v2 messenger API uses a second access-token system.  Flutter
 * signs in through Mobile API v1, so this service deliberately reuses the
 * authenticated v1 session and then boots the normal WoWonder user context.
 */
final class MessageService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly FeedService $feed
    ) {
    }

    public function conversations(
        string $accessToken,
        string $clientId,
        int $beforeTime,
        int $limit,
        string $query = ''
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_chats_read', 120, 60);
        global $sqlConnect;

        $limit = max(1, min(40, $limit));
        $query = trim($query);
        $sql = 'SELECT c.id AS chat_id, c.conversation_user_id, c.time AS chat_time'
            . ' FROM ' . T_U_CHATS . ' c INNER JOIN ' . T_USERS . ' u'
            . ' ON u.user_id = c.conversation_user_id'
            . ' WHERE c.user_id = ? AND c.page_id = 0'
            . ' AND c.conversation_user_id NOT IN (SELECT blocked FROM ' . T_BLOCKS . ' WHERE blocker = ?)'
            . ' AND c.conversation_user_id NOT IN (SELECT blocker FROM ' . T_BLOCKS . ' WHERE blocked = ?)';
        $types = 'iii';
        $params = [$viewerId, $viewerId, $viewerId];
        if ($beforeTime > 0) {
            $sql .= ' AND c.time < ?';
            $types .= 'i';
            $params[] = $beforeTime;
        }
        if ($query !== '') {
            $sql .= " AND (u.username LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $types .= 'ss';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY c.time DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $sqlConnect->prepare($sql);
        if (!$stmt) {
            throw new ApiException(500, 'CHAT_LOAD_FAILED', 'Conversations could not be loaded.');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result();
        $items = [];
        while ($row = $rows->fetch_assoc()) {
            $peerId = (int) $row['conversation_user_id'];
            $user = \Wo_UserData($peerId);
            if (!is_array($user) || empty($user['user_id'])) {
                continue;
            }
            $last = $this->lastMessage($viewerId, $peerId);
            $items[] = [
                'chat_id' => (int) $row['chat_id'],
                'chat_time' => (int) $row['chat_time'],
                'peer' => $this->presentUser($user),
                'last_message' => $last === null ? null : $this->presentMessage($last, $viewerId),
                'unread_count' => $this->unreadCount($viewerId, $peerId),
            ];
        }
        $stmt->close();
        $next = count($items) >= $limit ? (int) $items[array_key_last($items)]['chat_time'] : null;
        return ['items' => $items, 'next_before' => $next];
    }

    public function messages(
        string $accessToken,
        string $clientId,
        int $peerId,
        int $beforeId,
        int $afterId,
        int $limit
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_messages_read', 180, 60);
        $peer = \Wo_UserData($peerId);
        if (!is_array($peer) || empty($peer['user_id']) || $peerId === $viewerId) {
            throw new ApiException(404, 'CHAT_NOT_FOUND', 'This conversation is not available.');
        }
        $limit = max(1, min(50, $limit));
        $raw = \Wo_GetMessagesAPPN([
            'user_id' => $viewerId,
            'recipient_id' => $peerId,
            'before_message_id' => $beforeId,
            'after_message_id' => $afterId,
        ], $limit);
        $items = [];
        foreach (is_array($raw) ? array_reverse($raw) : [] as $message) {
            if (is_array($message)) {
                $items[] = $this->presentMessage($message, $viewerId);
            }
        }
        return [
            'peer' => $this->presentUser($peer),
            'items' => $items,
            'typing' => (bool) \Wo_IsTyping($peerId),
            'next_before' => count($items) >= $limit ? (int) $items[0]['id'] : null,
        ];
    }

    public function send(
        string $accessToken,
        string $clientId,
        int $peerId,
        string $text,
        int $replyId = 0
    ): array {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_message_send', 60, 60);
        $peer = \Wo_UserData($peerId);
        if (!is_array($peer) || empty($peer['user_id']) || $peerId === $viewerId) {
            throw new ApiException(404, 'CHAT_NOT_FOUND', 'This conversation is not available.');
        }
        $text = trim($text);
        $media = '';
        $mediaName = '';
        if (!empty($_FILES['file']['name']) && is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
            $upload = \Wo_ShareFile([
                'file' => $_FILES['file']['tmp_name'],
                'name' => $_FILES['file']['name'],
                'size' => $_FILES['file']['size'],
                'type' => $_FILES['file']['type'],
            ]);
            if (!is_array($upload) || empty($upload['filename'])) {
                throw new ApiException(422, 'MESSAGE_UPLOAD_FAILED', 'The attachment could not be uploaded.', 'file');
            }
            $media = (string) $upload['filename'];
            $mediaName = (string) $_FILES['file']['name'];
        }
        if ($text === '' && $media === '') {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Write a message or choose an attachment.', 'text');
        }
        if (mb_strlen($text) > 10_000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The message is too long.', 'text');
        }
        $data = [
            'from_id' => $viewerId,
            'to_id' => $peerId,
            'time' => time(),
            'text' => \Wo_Secure($text),
            'media' => \Wo_Secure($media),
            'mediaFileName' => \Wo_Secure($mediaName),
            'stickers' => '',
        ];
        if ($replyId > 0 && $this->ownsConversationMessage($replyId, $viewerId, $peerId)) {
            $data['reply_id'] = $replyId;
        }
        $id = \Wo_RegisterMessage($data);
        if (!is_numeric($id) || (int) $id < 1) {
            throw new ApiException(422, 'MESSAGE_SEND_FAILED', 'The message could not be sent.');
        }
        $message = \GetMessageById((int) $id);
        return $this->presentMessage(is_array($message) ? $message : $data + ['id' => (int) $id], $viewerId);
    }

    public function setTyping(string $accessToken, string $clientId, int $peerId, string $status): array
    {
        $this->authenticate($accessToken, $clientId, 'mobile_typing', 120, 60);
        if (!in_array($status, ['idle', 'typing', 'recording'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Invalid typing status.', 'status');
        }
        $value = $status === 'recording' ? 2 : ($status === 'typing' ? 1 : 0);
        // WoWonder stores typing state on a follower relationship. A valid
        // conversation may exist without one (for example an admin/support
        // chat), so typing is best-effort and must not break messaging.
        $updated = (bool)\Wo_RegisterTyping($peerId, $value);
        return ['status' => $status, 'updated' => $updated];
    }

    public function react(string $accessToken, string $clientId, int $messageId, int $reaction): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_message_react', 90, 60);
        if ($reaction < 1 || $reaction > 6 || !$this->messageVisible($messageId, $viewerId)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The message reaction is invalid.');
        }
        global $db;
        $db->where('user_id', $viewerId)->where('message_id', $messageId)->delete(T_REACTIONS);
        $db->insert(T_REACTIONS, ['user_id' => $viewerId, 'message_id' => $messageId, 'reaction' => $reaction]);
        return ['reaction' => $reaction];
    }

    public function delete(string $accessToken, string $clientId, int $messageId): array
    {
        $viewerId = $this->authenticate($accessToken, $clientId, 'mobile_message_delete', 40, 60);
        if (!$this->messageVisible($messageId, $viewerId) || !\Wo_DeleteMessage($messageId)) {
            throw new ApiException(403, 'MESSAGE_DELETE_DENIED', 'This message cannot be deleted.');
        }
        return ['deleted' => true, 'id' => $messageId];
    }

    private function authenticate(string $token, string $clientId, string $bucket, int $limit, int $window): int
    {
        $session = $this->tokens->authenticate($token, $clientId);
        $userId = (int) $session['user_id'];
        $this->rateLimiter->enforce($bucket, (string) $userId, $limit, $window);
        $this->feed->bootstrapWebContext($userId);
        return $userId;
    }

    private function lastMessage(int $viewerId, int $peerId): ?array
    {
        global $sqlConnect;
        $sql = 'SELECT * FROM ' . T_MESSAGES
            . ' WHERE page_id = 0 AND ((from_id = ? AND to_id = ? AND deleted_two = 0)'
            . ' OR (from_id = ? AND to_id = ? AND deleted_one = 0)) ORDER BY id DESC LIMIT 1';
        $stmt = $sqlConnect->prepare($sql);
        $stmt->bind_param('iiii', $peerId, $viewerId, $viewerId, $peerId);
        $stmt->execute();
        $message = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $message;
    }

    private function unreadCount(int $viewerId, int $peerId): int
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT COUNT(*) n FROM ' . T_MESSAGES
            . ' WHERE from_id = ? AND to_id = ? AND seen = 0 AND deleted_two = 0 AND page_id = 0');
        $stmt->bind_param('ii', $peerId, $viewerId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $count;
    }

    private function messageVisible(int $messageId, int $viewerId): bool
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT id FROM ' . T_MESSAGES
            . ' WHERE id = ? AND (from_id = ? OR to_id = ?) LIMIT 1');
        $stmt->bind_param('iii', $messageId, $viewerId, $viewerId);
        $stmt->execute();
        $visible = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $visible;
    }

    private function ownsConversationMessage(int $messageId, int $viewerId, int $peerId): bool
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT id FROM ' . T_MESSAGES
            . ' WHERE id = ? AND ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)) LIMIT 1');
        $stmt->bind_param('iiiii', $messageId, $viewerId, $peerId, $peerId, $viewerId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }

    private function presentUser(array $user): array
    {
        return [
            'id' => (int) ($user['user_id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['name'] ?? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))),
            'avatar' => $this->mediaUrl((string) ($user['avatar'] ?? '')),
            'verified' => (int) ($user['verified'] ?? 0) === 1,
            'last_seen' => (int) ($user['lastseen'] ?? 0),
            'online' => (int) ($user['lastseen'] ?? 0) >= time() - 60,
        ];
    }

    private function presentMessage(array $message, int $viewerId): array
    {
        $reply = is_array($message['reply'] ?? null) && !empty($message['reply'])
            ? $this->presentMessage($message['reply'], $viewerId)
            : null;
        $media = (string) ($message['media'] ?? '');
        return [
            'id' => (int) ($message['id'] ?? 0),
            'from_id' => (int) ($message['from_id'] ?? 0),
            'to_id' => (int) ($message['to_id'] ?? 0),
            'mine' => (int) ($message['from_id'] ?? 0) === $viewerId,
            'text' => $this->plainText((string) ($message['or_text'] ?? $message['text'] ?? '')),
            'media' => $this->mediaUrl($media),
            'media_name' => (string) ($message['mediaFileName'] ?? ''),
            'media_type' => $this->mediaType($media),
            'sticker' => $this->mediaUrl((string) ($message['stickers'] ?? '')),
            'time' => (int) ($message['time'] ?? time()),
            'seen_at' => (int) ($message['seen'] ?? 0),
            'reply' => $reply,
            'reaction' => $message['reaction'] ?? [],
            'pinned' => ($message['pin'] ?? 'no') === 'yes',
            'favorite' => ($message['fav'] ?? 'no') === 'yes',
        ];
    }

    private function plainText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * The legacy phone/v2 APIs define Wo_GetFilePosition() in entry-point
     * specific files which are intentionally not loaded by Mobile API v1.
     * Keeping the classifier here prevents a media message from terminating
     * the complete conversation response with an undefined-function error.
     */
    private function mediaType(string $media): string
    {
        if ($media === '') {
            return 'text';
        }
        $path = (string)(parse_url($media, PHP_URL_PATH) ?: $media);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return str_contains(strtolower($media), 'sticker') ? 'sticker' : 'image';
        }
        if (in_array($extension, ['mp4', 'mkv', 'avi', 'mov', 'webm', 'm4v'], true)) {
            return 'video';
        }
        if (in_array($extension, ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'opus'], true)) {
            return 'audio';
        }
        return 'file';
    }

    private function mediaUrl(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://')
            ? $value
            : (string) \Wo_GetMedia($value);
    }
}
