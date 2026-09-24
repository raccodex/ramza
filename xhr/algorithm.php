<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$respond = static function ($payload, $status = 200) {
    http_response_code((int)$status);
    $payload['status'] = (int)$status;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

if (empty($wo['loggedin']) || Wo_CheckSession($hash_id) === false) {
    $respond(array('ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'), 403);
    return;
}

if (in_array($s, array('status','debug_user','run_maintenance','save_topic','set_topic_status','save_relation','delete_relation','save_boost','delete_boost'), true)) {
    if (Wo_IsAdmin() === false) {
        $respond(array('ok' => false, 'message' => 'Administrator verification failed.'), 403);
        return;
    }
    if ($s === 'status') {
        $respond(array('ok' => true, 'data' => Wo_RamzaAlgorithmStatus()));
        return;
    }
    if ($s === 'debug_user') {
        $user_id = isset($_POST['user_id']) && is_numeric($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        if ($user_id < 1 || empty(Wo_UserData($user_id))) {
            $respond(array('ok' => false, 'message' => 'Choose a valid user.'), 422);
            return;
        }
        $respond(array('ok' => true, 'data' => Wo_RamzaAlgorithmDebugUser($user_id, 16)));
        return;
    }
    if ($s === 'run_maintenance') {
        $respond(array('ok' => true, 'data' => Wo_RamzaAlgorithmCronRun()));
        return;
    }
    if ($s === 'save_topic') {
        $result = Wo_RamzaAlgorithmSaveTopic($_POST);
    } else if ($s === 'set_topic_status') {
        $result = array('ok' => Wo_RamzaAlgorithmSetTopicStatus($_POST['topic_id'] ?? 0, $_POST['enabled'] ?? 0), 'message' => 'Topic status updated.');
    } else if ($s === 'save_relation') {
        $result = Wo_RamzaAlgorithmSaveRelation($_POST);
    } else if ($s === 'delete_relation') {
        $result = array('ok' => Wo_RamzaAlgorithmDeleteRelation($_POST['relation_id'] ?? 0), 'message' => 'Relationship removed.');
    } else if ($s === 'save_boost') {
        $result = Wo_RamzaAlgorithmSaveBoost($_POST, (int)$wo['user']['user_id']);
    } else {
        $result = array('ok' => Wo_RamzaAlgorithmDeleteBoost($_POST['boost_id'] ?? 0), 'message' => 'Editorial boost removed.');
    }
    if (empty($result['ok'])) {
        $respond(array('ok' => false, 'message' => $result['message'] ?? 'The algorithm action failed.'), 422);
        return;
    }
    $respond(array('ok' => true, 'message' => $result['message'] ?? 'Algorithm data updated.', 'data' => $result));
    return;
}

if ($s !== 'events') {
    $respond(array('ok' => false, 'message' => 'Unknown algorithm action.'), 404);
    return;
}

if (!Wo_RamzaAlgorithmConfigSwitch('algorithm_system', '0')) {
    $respond(array('ok' => false, 'message' => 'Algorithm tracking is disabled.'), 409);
    return;
}

$now = time();
$rate_key = 'ramza_algorithm_event_rate';
$rate = isset($_SESSION[$rate_key]) && is_array($_SESSION[$rate_key]) ? $_SESSION[$rate_key] : array('window' => $now, 'count' => 0);
if ($now - (int)$rate['window'] >= 60) {
    $rate = array('window' => $now, 'count' => 0);
}
$rate['count']++;
$_SESSION[$rate_key] = $rate;
if ($rate['count'] > 18) {
    $respond(array('ok' => false, 'message' => 'Too many behavior batches. Try again shortly.'), 429);
    return;
}

$events = json_decode((string)($_POST['events'] ?? ''), true);
if (!is_array($events)) {
    $respond(array('ok' => false, 'message' => 'The behavior batch is not valid.'), 422);
    return;
}
$session_key = strtolower(trim((string)($_POST['session_key'] ?? '')));
$result = Wo_RamzaAlgorithmRecordEvents((int)$wo['user']['user_id'], $events, $session_key);
$respond(array('ok' => true, 'data' => $result));
