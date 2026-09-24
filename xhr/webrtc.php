<?php
if ($f === 'webrtc') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$wo['loggedin'] || Wo_CheckMainSession($hash_id) === false || !Ramza_WebRtcActive()) {
        http_response_code(403);
        echo json_encode(['status' => 403, 'message' => 'WebRTC is not available.']);
        exit;
    }
    if (!Ramza_WebRtcEnsureTable()) {
        http_response_code(503);
        echo json_encode(['status' => 503, 'message' => 'WebRTC signaling storage is unavailable.']);
        exit;
    }
    $type = isset($_POST['call_type']) && !is_array($_POST['call_type']) ? strtolower((string) $_POST['call_type']) : '';
    $callId = isset($_POST['call_id']) && is_numeric($_POST['call_id']) ? (int) $_POST['call_id'] : 0;
    $userId = (int) $wo['user']['user_id'];
    $call = $type === 'live'
        ? Ramza_WebRtcLiveParticipant($callId, $userId)
        : Ramza_WebRtcParticipant($type, $callId, $userId);
    if ($call === []) {
        http_response_code(403);
        echo json_encode(['status' => 403, 'message' => 'Call access denied.']);
        exit;
    }
    if ($s === 'push') {
        $messageType = isset($_POST['message_type']) && !is_array($_POST['message_type']) ? strtolower((string) $_POST['message_type']) : '';
        $payload = isset($_POST['payload']) && !is_array($_POST['payload']) ? (string) $_POST['payload'] : '';
        $allowedTypes = $type === 'live'
            ? ['join', 'offer', 'answer', 'candidate', 'hangup']
            : ['offer', 'answer', 'candidate', 'hangup'];
        if (!in_array($messageType, $allowedTypes, true) || strlen($payload) > 32768 || json_decode($payload, true) === null) {
            http_response_code(422);
            echo json_encode(['status' => 422, 'message' => 'Invalid signal.']);
            exit;
        }
        $payloadSql = mysqli_real_escape_string($sqlConnect, $payload);
        $messageSql = mysqli_real_escape_string($sqlConnect, $messageType);
        $typeSql = mysqli_real_escape_string($sqlConnect, $type);
        $peerId = (int) ($call['peer_id'] ?? 0);
        if ($type === 'live') {
            $isBroadcaster = !empty($call['is_broadcaster']);
            if ($messageType === 'join') {
                if ($isBroadcaster) {
                    http_response_code(422);
                    echo json_encode(['status' => 422, 'message' => 'Invalid live join.']);
                    exit;
                }
                $maxPeers = max(1, min(12, (int) ($wo['config']['ramza_webrtc_max_live_peers'] ?? 6)));
                $active = (int) $db->where('post_id', $callId)->where('time', time() - 10, '>=')->getValue(T_LIVE_SUB, 'COUNT(*)');
                $alreadyWatching = (int) $db->where('post_id', $callId)->where('user_id', $userId)->getValue(T_LIVE_SUB, 'COUNT(*)');
                if ($active >= $maxPeers && $alreadyWatching < 1) {
                    http_response_code(429);
                    echo json_encode(['status' => 429, 'message' => 'This native live session is full.']);
                    exit;
                }
                $peerId = (int) $call['publisher_id'];
            } elseif ($isBroadcaster) {
                $peerId = isset($_POST['peer_id']) && is_numeric($_POST['peer_id']) ? (int) $_POST['peer_id'] : 0;
                if ($peerId < 1 || $peerId === $userId) {
                    http_response_code(422);
                    echo json_encode(['status' => 422, 'message' => 'Invalid live peer.']);
                    exit;
                }
            } else {
                $peerId = (int) $call['publisher_id'];
                if ($messageType === 'offer') {
                    http_response_code(422);
                    echo json_encode(['status' => 422, 'message' => 'Viewer cannot publish an offer.']);
                    exit;
                }
            }
        }
        $ok = @mysqli_query($sqlConnect, "INSERT INTO `" . T_RAMZA_WEBRTC_SIGNALS . "` (`call_type`,`call_id`,`sender_id`,`recipient_id`,`message_type`,`payload`,`created_at`,`delivered_at`) VALUES ('{$typeSql}',{$callId},{$userId},{$peerId},'{$messageSql}','{$payloadSql}'," . time() . ",0)");
        echo json_encode(['status' => $ok ? 200 : 500]);
        exit;
    }
    if ($s === 'pull') {
        $typeSql = mysqli_real_escape_string($sqlConnect, $type);
        $result = @mysqli_query($sqlConnect, "SELECT `id`,`sender_id`,`message_type`,`payload` FROM `" . T_RAMZA_WEBRTC_SIGNALS . "` WHERE `recipient_id`={$userId} AND `call_type`='{$typeSql}' AND `call_id`={$callId} AND `delivered_at`=0 ORDER BY `id` ASC LIMIT 50");
        $signals = [];
        $ids = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $ids[] = (int) $row['id'];
            $signals[] = ['senderId' => (int) $row['sender_id'], 'type' => (string) $row['message_type'], 'payload' => json_decode((string) $row['payload'], true)];
        }
        if ($ids !== []) {
            @mysqli_query($sqlConnect, "UPDATE `" . T_RAMZA_WEBRTC_SIGNALS . "` SET `delivered_at`=" . time() . " WHERE `id` IN (" . implode(',', $ids) . ") AND `recipient_id`={$userId}");
        }
        if (random_int(1, 40) === 1) {
            @mysqli_query($sqlConnect, "DELETE FROM `" . T_RAMZA_WEBRTC_SIGNALS . "` WHERE `created_at` < " . (time() - 86400));
        }
        echo json_encode(['status' => 200, 'signals' => $signals], JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(404);
    echo json_encode(['status' => 404, 'message' => 'Unknown signaling action.']);
    exit;
}
