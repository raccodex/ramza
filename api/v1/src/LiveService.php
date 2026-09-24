<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/**
 * Mobile live-video lifecycle matching Xamarin's LiveActivity and
 * LiveStreamingActivity. Agora credentials stay on the server; clients only
 * receive short-lived RTC tokens for their own uid and role.
 */
final class LiveService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly FeedService $feed
    ) {
    }

    /** @return array{user_id:int,config:array<string,mixed>} */
    private function viewer(string $accessToken, string $clientId): array
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $userId = (int)($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_live', (string)$userId, 240, 60);
        $this->feed->bootstrapWebContext($userId);

        global $wo;
        return [
            'user_id' => $userId,
            'config' => is_array($wo['config'] ?? null) ? $wo['config'] : [],
        ];
    }

    /** @return array{enabled:bool,configured:bool,provider:string,save_video:bool,reason:string} */
    public function configuration(string $accessToken, string $clientId): array
    {
        $context = $this->viewer($accessToken, $clientId);
        return $this->publicConfiguration($context['config']);
    }

    public function callConfiguration(string $accessToken, string $clientId): array
    {
        $context = $this->viewer($accessToken, $clientId);
        return $this->publicCallConfiguration($context['config']);
    }

    /** @param array<string,mixed> $config */
    private function publicCallConfiguration(array $config): array
    {
        $enabled = (int)($config['agora_chat_video'] ?? 0) === 1;
        $appId = trim((string)($config['agora_chat_app_id'] ?? ''));
        return [
            'enabled' => $enabled,
            'configured' => $enabled && $appId !== '',
            'audio_enabled' => $enabled,
            'video_enabled' => $enabled,
            'provider' => $enabled && $appId !== '' ? 'agora' : 'disabled',
            'reason' => !$enabled
                ? 'Calling is disabled in the admin panel.'
                : ($appId === '' ? 'Agora calling credentials are not configured.' : ''),
        ];
    }

    public function createCall(
        string $accessToken,
        string $clientId,
        int $peerId,
        string $type
    ): array {
        $context = $this->viewer($accessToken, $clientId);
        $config = $context['config'];
        $from = $context['user_id'];
        $type = $type === 'audio' ? 'audio' : 'video';
        $public = $this->publicCallConfiguration($config);
        if (!$public['configured']) {
            throw new ApiException(503, 'CALL_NOT_CONFIGURED', $public['reason']);
        }
        if ($peerId < 1 || $peerId === $from || !is_array(\Wo_UserData($peerId))) {
            throw new ApiException(422, 'INVALID_RECIPIENT', 'Choose a valid call recipient.');
        }
        $room = 'call_' . $from . '_' . $peerId . '_' . bin2hex(random_bytes(6));
        $token = $this->callRtcToken($config, $room);
        $id = \Wo_CreateNewAgoraCall([
            'from_id' => $from,
            'to_id' => $peerId,
            'room_name' => $room,
            'type' => $type,
            'status' => 'calling',
            'access_token' => $token,
            'active' => 0,
            'declined' => 0,
        ]);
        if (!is_numeric($id) || (int)$id < 1) {
            throw new ApiException(422, 'CALL_CREATE_FAILED', 'The call could not be started.');
        }
        $this->sendCallPush($from, $peerId, (int)$id, $type, $room);
        return $this->callPayload($config, $this->callRow((int)$id), $from);
    }

    public function call(
        string $accessToken,
        string $clientId,
        int $callId
    ): array {
        $context = $this->viewer($accessToken, $clientId);
        return $this->callPayload($context['config'], $this->callRow($callId), $context['user_id']);
    }

    public function incomingCall(string $accessToken, string $clientId): ?array
    {
        $context = $this->viewer($accessToken, $clientId);
        $viewerId = $context['user_id'];
        global $sqlConnect;
        $threshold = time() - 45;
        $stmt = $sqlConnect->prepare(
            'SELECT * FROM ' . T_AGORA
            . " WHERE to_id=? AND status='calling' AND declined='0' AND time>=? ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ii', $viewerId, $threshold);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $this->callPayload($context['config'], $row, $viewerId) : null;
    }

    public function answerCall(
        string $accessToken,
        string $clientId,
        int $callId,
        bool $accept
    ): array {
        $context = $this->viewer($accessToken, $clientId);
        $viewerId = $context['user_id'];
        $row = $this->callRow($callId);
        if ((int)$row['to_id'] !== $viewerId) {
            throw new ApiException(403, 'CALL_FORBIDDEN', 'Only the recipient can answer this call.');
        }
        global $sqlConnect;
        $active = $accept ? 1 : 0;
        $declined = $accept ? 0 : 1;
        $status = $accept ? 'answered' : 'declined';
        $stmt = $sqlConnect->prepare(
            'UPDATE ' . T_AGORA . ' SET active=?,declined=?,status=? WHERE id=? LIMIT 1'
        );
        $stmt->bind_param('iisi', $active, $declined, $status, $callId);
        $stmt->execute();
        $stmt->close();
        return $this->callPayload($context['config'], $this->callRow($callId), $viewerId);
    }

    public function endCall(string $accessToken, string $clientId, int $callId): array
    {
        $context = $this->viewer($accessToken, $clientId);
        $viewerId = $context['user_id'];
        $row = $this->callRow($callId);
        if (!in_array($viewerId, [(int)$row['from_id'], (int)$row['to_id']], true)) {
            throw new ApiException(403, 'CALL_FORBIDDEN', 'You are not a participant in this call.');
        }
        global $sqlConnect;
        $status = 'ended';
        $stmt = $sqlConnect->prepare(
            'UPDATE ' . T_AGORA . ' SET active=0,status=? WHERE id=? LIMIT 1'
        );
        $stmt->bind_param('si', $status, $callId);
        $stmt->execute();
        $stmt->close();
        return ['ended' => true];
    }

    /** @param array<string,mixed> $config */
    private function publicConfiguration(array $config): array
    {
        $entitled = !isset($config['can_use_live']) || (bool)$config['can_use_live'];
        $enabled = $entitled && (int)($config['live_video'] ?? 0) === 1;
        $agora = (int)($config['agora_live_video'] ?? 0) === 1
            && trim((string)($config['agora_app_id'] ?? '')) !== ''
            && trim((string)($config['agora_app_certificate'] ?? '')) !== '';
        $millicast = (int)($config['millicast_live_video'] ?? 0) === 1
            && trim((string)($config['live_account_id'] ?? '')) !== ''
            && trim((string)($config['live_token'] ?? '')) !== '';
        $provider = $agora ? 'agora' : ($millicast ? 'millicast' : 'none');
        $configured = $enabled && $provider !== 'none';

        $reason = '';
        if (!$entitled) {
            $reason = 'Live video is not available for this installation.';
        } elseif (!$enabled) {
            $reason = 'Live video is disabled in the admin panel.';
        } elseif (!$configured) {
            $reason = 'Configure and enable Agora or Millicast live-video credentials in the admin panel.';
        }

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'provider' => $provider,
            'save_video' => (int)($config['live_video_save'] ?? 0) === 1,
            'reason' => $reason,
        ];
    }

    /** @return array{items:array<int,array<string,mixed>>,next_after:int,configuration:array<string,mixed>} */
    public function list(
        string $accessToken,
        string $clientId,
        int $afterId,
        int $limit
    ): array {
        $context = $this->viewer($accessToken, $clientId);
        $viewerId = $context['user_id'];
        $limit = max(1, min(30, $limit));
        $threshold = time() - 10;

        global $sqlConnect;
        $sql = 'SELECT DISTINCT p.id FROM ' . T_POSTS . ' p'
            . ' LEFT JOIN ' . T_FOLLOWERS . ' f'
            . " ON f.following_id=p.user_id AND f.follower_id=? AND f.active='1'"
            . " WHERE p.postType='live' AND p.live_ended='0' AND p.live_time>=?"
            . ' AND (p.user_id=? OR f.id IS NOT NULL)';
        $types = 'iii';
        $params = [$viewerId, $threshold, $viewerId];
        if ($afterId > 0) {
            $sql .= ' AND p.id<?';
            $types .= 'i';
            $params[] = $afterId;
        }
        $sql .= ' ORDER BY p.id DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $statement = $sqlConnect->prepare($sql);
        $statement->bind_param($types, ...$params);
        $statement->execute();
        $result = $statement->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $raw = \Wo_PostData((int)$row['id']);
            $item = is_array($raw) ? $this->feed->present($raw, $viewerId) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }
        $statement->close();
        $next = count($items) >= $limit ? (int)$items[array_key_last($items)]['id'] : 0;
        return [
            'items' => $items,
            'next_after' => $next,
            'configuration' => $this->publicConfiguration($context['config']),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function create(
        string $accessToken,
        string $clientId,
        array $payload
    ): array {
        $context = $this->viewer($accessToken, $clientId);
        $this->assertAgora($context['config']);
        $userId = $context['user_id'];
        $privacy = (string)($payload['privacy'] ?? '0');
        if (!in_array($privacy, ['0', '1', '2', '3', '4'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a valid live-video privacy option.', 'privacy');
        }
        $streamName = 'stream_' . $userId . '_' . (int)round(microtime(true) * 1000);
        $token = $this->rtcToken($context['config'], $streamName, $userId, true);
        $now = time();

        global $sqlConnect;
        $type = 'live';
        $empty = '';
        $ended = 0;
        $statement = $sqlConnect->prepare(
            'INSERT INTO ' . T_POSTS
            . ' (`post_id`,`user_id`,`postText`,`postPrivacy`,`postType`,`stream_name`,`agora_token`,`time`,`live_time`,`live_ended`)'
            . ' VALUES (0,?,?,?,?,?,?,?,?,?)'
        );
        $statement->bind_param(
            'isssssiii',
            $userId,
            $empty,
            $privacy,
            $type,
            $streamName,
            $token,
            $now,
            $now,
            $ended
        );
        $statement->execute();
        $postId = (int)$statement->insert_id;
        $statement->close();
        if ($postId < 1) {
            throw new ApiException(422, 'LIVE_CREATE_FAILED', 'The live video could not be started.');
        }
        $update = $sqlConnect->prepare('UPDATE ' . T_POSTS . ' SET post_id=? WHERE id=?');
        $update->bind_param('ii', $postId, $postId);
        $update->execute();
        $update->close();

        if (function_exists('Wo_notifyUsersLive')) {
            \Wo_notifyUsersLive($postId);
        }
        $raw = \Wo_PostData($postId);
        $post = is_array($raw) ? $this->feed->present($raw, $userId) : null;
        if ($post === null) {
            \Wo_DeletePost($postId);
            throw new ApiException(500, 'LIVE_CREATE_FAILED', 'The live video was created but could not be loaded.');
        }
        return [
            'post' => $post,
            'session' => $this->sessionPayload($context['config'], $postId, $streamName, $userId, $token, true),
        ];
    }

    public function join(string $accessToken, string $clientId, int $postId): array
    {
        $context = $this->viewer($accessToken, $clientId);
        $this->assertAgora($context['config']);
        $row = $this->liveRow($postId);
        if ((int)$row['live_ended'] === 1 || (int)$row['live_time'] < time() - 10) {
            throw new ApiException(409, 'LIVE_ENDED', 'This live stream has ended.');
        }
        $token = $this->rtcToken(
            $context['config'],
            (string)$row['stream_name'],
            $context['user_id'],
            false
        );
        $raw = \Wo_PostData($postId);
        return [
            'post' => $this->feed->present(is_array($raw) ? $raw : $row, $context['user_id']),
            'session' => $this->sessionPayload(
                $context['config'],
                $postId,
                (string)$row['stream_name'],
                $context['user_id'],
                $token,
                false
            ),
        ];
    }

    public function heartbeat(
        string $accessToken,
        string $clientId,
        int $postId,
        bool $broadcaster
    ): array {
        $context = $this->viewer($accessToken, $clientId);
        $userId = $context['user_id'];
        $row = $this->liveRow($postId);
        if ((int)$row['live_ended'] === 1) {
            throw new ApiException(409, 'LIVE_ENDED', 'This live stream has ended.');
        }
        $now = time();
        global $sqlConnect;
        if ($broadcaster) {
            if ((int)$row['user_id'] !== $userId) {
                throw new ApiException(403, 'LIVE_OWNER_REQUIRED', 'Only the broadcaster can update this live stream.');
            }
            $update = $sqlConnect->prepare('UPDATE ' . T_POSTS . ' SET live_time=? WHERE id=? OR parent_id=?');
            $update->bind_param('iii', $now, $postId, $postId);
            $update->execute();
            $update->close();
        } else {
            if ((int)$row['live_time'] < $now - 10) {
                return ['still_live' => false, 'viewers' => 0];
            }
            $watching = 1;
            $existing = $sqlConnect->prepare(
                'SELECT id FROM ' . T_LIVE_SUB . ' WHERE user_id=? AND post_id=? LIMIT 1'
            );
            $existing->bind_param('ii', $userId, $postId);
            $existing->execute();
            $watcherId = (int)($existing->get_result()->fetch_assoc()['id'] ?? 0);
            $existing->close();
            if ($watcherId > 0) {
                $statement = $sqlConnect->prepare(
                    'UPDATE ' . T_LIVE_SUB . ' SET is_watching=?,time=? WHERE id=?'
                );
                $statement->bind_param('iii', $watching, $now, $watcherId);
                $statement->execute();
                $statement->close();
            } else {
                $statement = $sqlConnect->prepare(
                    'INSERT INTO ' . T_LIVE_SUB . ' (`user_id`,`post_id`,`is_watching`,`time`) VALUES (?,?,?,?)'
                );
                $statement->bind_param('iiii', $userId, $postId, $watching, $now);
                $statement->execute();
                $statement->close();
            }
        }
        $threshold = $now - 6;
        $count = $sqlConnect->prepare('SELECT COUNT(*) AS total FROM ' . T_LIVE_SUB . ' WHERE post_id=? AND time>=?');
        $count->bind_param('ii', $postId, $threshold);
        $count->execute();
        $viewers = (int)($count->get_result()->fetch_assoc()['total'] ?? 0);
        $count->close();
        return [
            'still_live' => $broadcaster || (int)$row['live_time'] >= $now - 10,
            'viewers' => $viewers,
        ];
    }

    public function end(string $accessToken, string $clientId, int $postId): array
    {
        $context = $this->viewer($accessToken, $clientId);
        $row = $this->liveRow($postId);
        if ((int)$row['user_id'] !== $context['user_id']) {
            throw new ApiException(403, 'LIVE_OWNER_REQUIRED', 'Only the broadcaster can end this live stream.');
        }
        global $sqlConnect;
        $ended = 1;
        $statement = $sqlConnect->prepare('UPDATE ' . T_POSTS . ' SET live_ended=? WHERE id=? OR parent_id=?');
        $statement->bind_param('iii', $ended, $postId, $postId);
        $statement->execute();
        $statement->close();

        // Xamarin deletes unsaved streams. This mobile endpoint does the same
        // unless a server recording has actually populated postFile.
        if ((int)($context['config']['live_video_save'] ?? 0) !== 1 || trim((string)$row['postFile']) === '') {
            \Wo_DeletePost($postId);
            return ['deleted' => true];
        }
        return ['deleted' => false];
    }

    /** @param array<string,mixed> $config */
    private function assertAgora(array $config): void
    {
        $public = $this->publicConfiguration($config);
        if (!$public['enabled'] || !$public['configured']) {
            throw new ApiException(503, 'LIVE_NOT_CONFIGURED', $public['reason']);
        }
        if ($public['provider'] !== 'agora') {
            throw new ApiException(503, 'LIVE_PROVIDER_UNSUPPORTED', 'This mobile build requires the configured Agora live-video provider.');
        }
    }

    /** @param array<string,mixed> $config */
    private function rtcToken(array $config, string $channel, int $uid, bool $publisher): string
    {
        $builder = dirname(__DIR__, 3) . '/assets/libraries/AgoraDynamicKey/src/RtcTokenBuilder.php';
        require_once $builder;
        $role = $publisher ? \RtcTokenBuilder::RolePublisher : \RtcTokenBuilder::RoleSubscriber;
        return \RtcTokenBuilder::buildTokenWithUid(
            trim((string)$config['agora_app_id']),
            trim((string)$config['agora_app_certificate']),
            $channel,
            $uid,
            $role,
            time() + 3600
        );
    }

    private function callRtcToken(array $config, string $channel): string
    {
        $certificate = trim((string)($config['agora_chat_app_certificate'] ?? ''));
        if ($certificate === '') return '';
        $builder = dirname(__DIR__, 3) . '/assets/libraries/AgoraDynamicKey/src/RtcTokenBuilder.php';
        require_once $builder;
        return \RtcTokenBuilder::buildTokenWithUid(
            trim((string)$config['agora_chat_app_id']),
            $certificate,
            $channel,
            0,
            \RtcTokenBuilder::RolePublisher,
            time() + 3600
        );
    }

    private function callRow(int $callId): array
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('SELECT * FROM ' . T_AGORA . ' WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $callId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new ApiException(404, 'CALL_NOT_FOUND', 'This call is no longer available.');
        }
        return $row;
    }

    private function callPayload(array $config, array $row, int $viewerId): array
    {
        if (!in_array($viewerId, [(int)$row['from_id'], (int)$row['to_id']], true)) {
            throw new ApiException(403, 'CALL_FORBIDDEN', 'You are not a participant in this call.');
        }
        $peerId = (int)$row['from_id'] === $viewerId ? (int)$row['to_id'] : (int)$row['from_id'];
        $peer = \Wo_UserData($peerId);
        return [
            'id' => (int)$row['id'],
            'type' => (string)($row['type'] ?? 'video'),
            'status' => (string)($row['status'] ?? 'calling'),
            'is_caller' => (int)$row['from_id'] === $viewerId,
            'app_id' => trim((string)($config['agora_chat_app_id'] ?? '')),
            'channel' => (string)($row['room_name'] ?? ''),
            'token' => (string)($row['access_token'] ?? ''),
            'uid' => 0,
            'peer' => [
                'id' => $peerId,
                'name' => (string)($peer['name'] ?? trim(($peer['first_name'] ?? '') . ' ' . ($peer['last_name'] ?? ''))),
                'avatar' => (string)($peer['avatar'] ?? ''),
            ],
        ];
    }

    private function sendCallPush(
        int $fromId,
        int $toId,
        int $callId,
        string $type,
        string $room
    ): void {
        global $wo;
        $caller = \Wo_UserData($fromId);
        $recipient = \Wo_UserData($toId);
        if (!is_array($caller) || !is_array($recipient)) return;
        $notification = [
            'notification_content' => 'is calling you',
            'notification_title' => (string)($caller['name'] ?? ''),
            'notification_image' => (string)($caller['avatar'] ?? ''),
            'notification_data' => [
                'call_type' => $type,
                'access_token_2' => '',
                'room_name' => $room,
                'call_id' => $callId,
                'caller_id' => $fromId,
            ],
        ];
        if (!empty($recipient['ios_m_device_id']) && (int)($wo['config']['ios_push_messages'] ?? 0) === 1) {
            \Wo_SendPushNotification([
                'send_to' => [(string)$recipient['ios_m_device_id']],
                'notification' => $notification,
            ], 'ios_messenger');
        }
        if (!empty($recipient['android_m_device_id']) && (int)($wo['config']['android_push_messages'] ?? 0) === 1) {
            \Wo_SendPushNotification([
                'send_to' => [(string)$recipient['android_m_device_id']],
                'notification' => $notification,
            ], 'android_messenger');
        }
    }

    /** @param array<string,mixed> $config */
    private function sessionPayload(
        array $config,
        int $postId,
        string $channel,
        int $uid,
        string $token,
        bool $broadcaster
    ): array {
        return [
            'post_id' => $postId,
            'provider' => 'agora',
            'app_id' => trim((string)$config['agora_app_id']),
            'channel' => $channel,
            'uid' => $uid,
            'token' => $token,
            'broadcaster' => $broadcaster,
        ];
    }

    /** @return array<string,mixed> */
    private function liveRow(int $postId): array
    {
        global $sqlConnect;
        $statement = $sqlConnect->prepare(
            'SELECT `id`,`post_id`,`user_id`,`stream_name`,`live_time`,`live_ended`,`postFile`'
            . ' FROM ' . T_POSTS . " WHERE id=? AND postType='live' LIMIT 1"
        );
        $statement->bind_param('i', $postId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($row)) {
            throw new ApiException(404, 'LIVE_NOT_FOUND', 'This live stream is unavailable.');
        }
        return $row;
    }
}
