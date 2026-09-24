<?php
declare(strict_types=1);

function Ramza_WebRtcReady(): bool
{
    global $wo;
    return (string) ($wo['config']['ramza_webrtc_system'] ?? '0') === '1'
        && function_exists('Ramza_AddonLicensed')
        && Ramza_AddonLicensed('webrtc_pro');
}

function Ramza_AgoraCallReady(): bool
{
    global $wo;
    return (string) ($wo['config']['agora_chat_video'] ?? '0') === '1'
        && trim((string) ($wo['config']['agora_chat_app_id'] ?? '')) !== '';
}

function Ramza_TwilioCallReady(): bool
{
    global $wo;
    return (string) ($wo['config']['twilio_video_chat'] ?? '0') === '1'
        && trim((string) ($wo['config']['video_accountSid'] ?? '')) !== ''
        && trim((string) ($wo['config']['video_apiKeySid'] ?? '')) !== ''
        && trim((string) ($wo['config']['video_apiKeySecret'] ?? '')) !== '';
}

function Ramza_WebRtcActive(): bool
{
    return Ramza_CallProvider() === 'native_webrtc';
}

function Ramza_WebRtcLiveActive(): bool
{
    return Ramza_LiveProvider() === 'native_webrtc';
}

function Ramza_LiveProvider(): string
{
    global $wo;
    $requested = (string) ($wo['config']['ramza_live_provider'] ?? 'agora');
    $nativeReady = Ramza_WebRtcReady();
    $agoraReady = (string) ($wo['config']['agora_live_video'] ?? '0') === '1'
        && trim((string) ($wo['config']['agora_app_id'] ?? '')) !== '';
    $millicastReady = (string) ($wo['config']['millicast_live_video'] ?? '0') === '1'
        && trim((string) ($wo['config']['live_token'] ?? '')) !== ''
        && trim((string) ($wo['config']['live_account_id'] ?? '')) !== '';
    foreach (array_values(array_unique([$requested, 'native_webrtc', 'agora', 'millicast'])) as $candidate) {
        if ($candidate === 'native_webrtc' && $nativeReady) {
            return 'native_webrtc';
        }
        if ($candidate === 'agora' && $agoraReady) {
            return 'agora';
        }
        if ($candidate === 'millicast' && $millicastReady) {
            return 'millicast';
        }
    }
    return 'disabled';
}

function Ramza_CallProvider(): string
{
    global $wo;
    $requested = (string) ($wo['config']['ramza_call_provider'] ?? 'twilio');
    foreach (array_values(array_unique([$requested, 'native_webrtc', 'agora', 'twilio'])) as $candidate) {
        if ($candidate === 'native_webrtc' && Ramza_WebRtcReady()) {
            return 'native_webrtc';
        }
        if ($candidate === 'agora' && Ramza_AgoraCallReady()) {
            return 'agora';
        }
        if ($candidate === 'twilio' && Ramza_TwilioCallReady()) {
            return 'twilio';
        }
    }
    return 'disabled';
}

function Ramza_WebRtcEnsureTable(): bool
{
    global $sqlConnect;
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $table = T_RAMZA_WEBRTC_SIGNALS;
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `call_type` enum('audio','video','live') NOT NULL,
        `call_id` bigint unsigned NOT NULL,
        `sender_id` int unsigned NOT NULL,
        `recipient_id` int unsigned NOT NULL,
        `message_type` enum('join','offer','answer','candidate','hangup') NOT NULL,
        `payload` text NOT NULL,
        `created_at` int unsigned NOT NULL,
        `delivered_at` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `recipient_call` (`recipient_id`,`call_type`,`call_id`,`delivered_at`,`id`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $ready = (bool) @mysqli_query($sqlConnect, $sql);
    if ($ready) {
        $column = @mysqli_query($sqlConnect, "SHOW COLUMNS FROM `{$table}` LIKE 'message_type'");
        $definition = $column ? mysqli_fetch_assoc($column) : null;
        if (is_array($definition) && stripos((string) ($definition['Type'] ?? ''), "'join'") === false) {
            $ready = (bool) @mysqli_query($sqlConnect, "ALTER TABLE `{$table}` MODIFY `message_type` enum('join','offer','answer','candidate','hangup') NOT NULL");
        }
    }
    return $ready;
}

function Ramza_WebRtcIceServers(): array
{
    global $wo;
    $split = static function (string $value): array {
        $items = preg_split('/[\r\n,]+/', $value) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => preg_match('#^(stun|turn|turns):[^\s]{3,500}$#i', $item) === 1));
    };
    $servers = [];
    $stun = $split((string) ($wo['config']['ramza_webrtc_stun_urls'] ?? 'stun:stun.cloudflare.com:3478'));
    if ($stun !== []) {
        $servers[] = ['urls' => $stun];
    }
    $turnMode = strtolower(trim((string) ($wo['config']['ramza_webrtc_turn_mode'] ?? 'self_hosted')));
    if ($turnMode === 'self_hosted') {
        $host = trim((string) ($wo['config']['ramza_webrtc_turn_host'] ?? ''));
        if ($host === '') {
            $host = (string) parse_url((string) ($wo['config']['site_url'] ?? ''), PHP_URL_HOST);
        }
        $host = preg_replace('/[^a-z0-9.:-]/i', '', $host) ?? '';
        $secret = trim((string) ($wo['config']['ramza_webrtc_turn_secret'] ?? ''));
        if ($host !== '' && $secret !== '') {
            $port = max(1, min(65535, (int) ($wo['config']['ramza_webrtc_turn_port'] ?? 3478)));
            $tlsPort = max(1, min(65535, (int) ($wo['config']['ramza_webrtc_turn_tls_port'] ?? 5349)));
            $expires = time() + max(300, min(86400, (int) ($wo['config']['ramza_webrtc_turn_ttl'] ?? 3600)));
            $identity = (int) ($wo['user']['user_id'] ?? 0);
            $username = $expires . ':' . ($identity > 0 ? $identity : 'ramza');
            $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));
            $urls = [
                'turn:' . $host . ':' . $port . '?transport=udp',
                'turn:' . $host . ':' . $port . '?transport=tcp',
            ];
            if ((string) ($wo['config']['ramza_webrtc_turn_tls'] ?? '0') === '1') {
                $urls[] = 'turns:' . $host . ':' . $tlsPort . '?transport=tcp';
            }
            $servers[] = ['urls' => $urls, 'username' => $username, 'credential' => $credential];
        }
    }
    $turn = $turnMode === 'custom' ? $split((string) ($wo['config']['ramza_webrtc_turn_urls'] ?? '')) : [];
    if ($turnMode === 'custom' && $turn !== []) {
        $servers[] = [
            'urls' => $turn,
            'username' => (string) ($wo['config']['ramza_webrtc_turn_username'] ?? ''),
            'credential' => (string) ($wo['config']['ramza_webrtc_turn_credential'] ?? ''),
        ];
    }
    return $servers;
}

function Ramza_WebRtcTurnStatus(): array
{
    global $wo;
    $mode = strtolower(trim((string) ($wo['config']['ramza_webrtc_turn_mode'] ?? 'self_hosted')));
    $host = trim((string) ($wo['config']['ramza_webrtc_turn_host'] ?? ''));
    if ($host === '') {
        $host = (string) parse_url((string) ($wo['config']['site_url'] ?? ''), PHP_URL_HOST);
    }
    $configured = $mode === 'custom'
        ? trim((string) ($wo['config']['ramza_webrtc_turn_urls'] ?? '')) !== ''
        : ($mode === 'self_hosted' && $host !== '' && trim((string) ($wo['config']['ramza_webrtc_turn_secret'] ?? '')) !== '');
    return [
        'mode' => in_array($mode, ['disabled', 'self_hosted', 'custom'], true) ? $mode : 'disabled',
        'host' => $host,
        'port' => max(1, min(65535, (int) ($wo['config']['ramza_webrtc_turn_port'] ?? 3478))),
        'configured' => $configured,
        'ice_servers' => count(Ramza_WebRtcIceServers()),
    ];
}

function Ramza_WebRtcCall(string $type, int $callId): array
{
    global $sqlConnect;
    if (!in_array($type, ['audio', 'video'], true) || $callId < 1) {
        return [];
    }
    $table = $type === 'audio' ? T_AUDIO_CALLES : T_VIDEOS_CALLES;
    $query = @mysqli_query($sqlConnect, "SELECT `id`,`from_id`,`to_id`,`active`,`declined` FROM `{$table}` WHERE `id` = {$callId} LIMIT 1");
    $row = $query ? mysqli_fetch_assoc($query) : null;
    return is_array($row) ? $row : [];
}

function Ramza_WebRtcParticipant(string $type, int $callId, int $userId): array
{
    $call = Ramza_WebRtcCall($type, $callId);
    if ($call === [] || !in_array($userId, [(int) $call['from_id'], (int) $call['to_id']], true)) {
        return [];
    }
    $call['peer_id'] = (int) $call['from_id'] === $userId ? (int) $call['to_id'] : (int) $call['from_id'];
    $call['is_caller'] = (int) $call['from_id'] === $userId;
    return $call;
}

function Ramza_WebRtcLiveParticipant(int $postId, int $userId): array
{
    if ($postId < 1 || $userId < 1 || !Ramza_WebRtcLiveActive()) {
        return [];
    }
    $story = function_exists('Wo_PostData') ? Wo_PostData($postId) : false;
    if (!is_array($story)
        || (int) ($story['id'] ?? 0) !== $postId
        || (string) ($story['postType'] ?? '') !== 'live'
        || (int) ($story['live_ended'] ?? 1) !== 0
        || empty($story['stream_name'])) {
        return [];
    }
    $publisherId = (int) ($story['user_id'] ?? 0);
    if ($publisherId < 1) {
        return [];
    }
    return [
        'post_id' => $postId,
        'publisher_id' => $publisherId,
        'is_broadcaster' => $publisherId === $userId,
        'peer_id' => $publisherId === $userId ? 0 : $publisherId,
    ];
}

function Ramza_WebRtcLiveClientConfig(int $postId, int $userId): array
{
    global $wo;
    $live = Ramza_WebRtcLiveParticipant($postId, $userId);
    if ($live === []) {
        return [];
    }
    return [
        'postId' => $postId,
        'isBroadcaster' => !empty($live['is_broadcaster']),
        'iceServers' => Ramza_WebRtcIceServers(),
        'iceTimeout' => max(8, min(45, (int) ($wo['config']['ramza_webrtc_ice_timeout'] ?? 18))),
        'maxPeers' => max(1, min(12, (int) ($wo['config']['ramza_webrtc_max_live_peers'] ?? 6))),
        'hash' => Wo_CreateSession(),
        'endpoint' => Wo_Ajax_Requests_File() . '?f=webrtc',
    ];
}

function Ramza_WebRtcClientConfig(string $type, int $callId, int $userId): array
{
    $call = Ramza_WebRtcParticipant($type, $callId, $userId);
    if ($call === [] || !Ramza_WebRtcActive()) {
        return [];
    }
    global $wo;
    return [
        'callId' => $callId,
        'callType' => $type,
        'isCaller' => !empty($call['is_caller']),
        'iceServers' => Ramza_WebRtcIceServers(),
        'iceTimeout' => max(8, min(45, (int) ($wo['config']['ramza_webrtc_ice_timeout'] ?? 18))),
        'hash' => Wo_CreateSession(),
        'endpoint' => Wo_Ajax_Requests_File() . '?f=webrtc',
        'homeUrl' => (string) ($wo['config']['site_url'] ?? '/'),
    ];
}
