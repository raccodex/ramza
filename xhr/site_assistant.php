<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($f !== 'site_assistant') {
    return;
}

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    $payload['status'] = $status;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

if ($s === 'activate') {
    if (Wo_IsAdmin() === false || Wo_CheckSession($hash_id) === false) {
        $respond(['ok' => false, 'message' => 'Administrator verification failed.'], 403);
        return;
    }
    $code = trim((string) ($_POST['activation_code'] ?? ''));
    if ($code === '') {
        $respond(['ok' => false, 'message' => 'Enter the assistant purchase code.'], 422);
        return;
    }
    $result = Ramza_LicenseActivateFeature('site_assistant', $code);
    $respond([
        'ok' => !empty($result['ok']),
        'message' => (string) ($result['message'] ?? (!empty($result['ok']) ? 'Assistant license activated.' : 'Activation failed.')),
        'reload' => !empty($result['ok']),
    ], !empty($result['ok']) ? 200 : 422);
    return;
}

if (Wo_CheckSession($hash_id) === false) {
    $respond(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
    return;
}

if (!Wo_SiteAssistantAvailable()) {
    $respond(['ok' => false, 'message' => 'The site assistant is not available.'], 403);
    return;
}

if (!Wo_SiteAssistantRateAllowed()) {
    $respond(['ok' => false, 'message' => 'Too many requests. Try again in a few minutes.'], 429);
    return;
}

try {
    if ($s === 'plan') {
        $command = trim((string) ($_POST['command'] ?? ''));
        if ($command === '') {
            $respond(['ok' => false, 'message' => 'Enter a request.'], 422);
            return;
        }
        $respond(['ok' => true, 'data' => Wo_SiteAssistantPlan($command)]);
        return;
    }

    if ($s === 'prepare') {
        $intent = trim((string) ($_POST['intent'] ?? ''));
        $allowed = ['latest_post', 'follow', 'reaction', 'comment'];
        if (!in_array($intent, $allowed, true) || empty($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
            $respond(['ok' => false, 'message' => 'That request is not valid.'], 422);
            return;
        }
        $respond([
            'ok' => true,
            'data' => Wo_SiteAssistantPrepare(
                $intent,
                (int) $_POST['user_id'],
                (string) ($_POST['text'] ?? ''),
                (string) ($_POST['reaction'] ?? '1')
            ),
        ]);
        return;
    }

    if ($s === 'execute') {
        if ((string) ($wo['config']['site_assistant_actions'] ?? '0') !== '1') {
            $respond(['ok' => false, 'message' => 'Assistant actions are disabled.'], 403);
            return;
        }
        $token = trim((string) ($_POST['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            $respond(['ok' => false, 'message' => 'That confirmation is not valid.'], 422);
            return;
        }
        $result = Wo_SiteAssistantExecute($token);
        $respond([
            'ok' => !empty($result['ok']),
            'message' => (string) ($result['message'] ?? 'The action could not be completed.'),
            'location' => (string) ($result['location'] ?? ''),
        ], !empty($result['ok']) ? 200 : 422);
        return;
    }

    $respond(['ok' => false, 'message' => 'Unknown assistant request.'], 404);
} catch (Throwable $exception) {
    error_log('Ramza site assistant: ' . $exception->getMessage());
    $respond(['ok' => false, 'message' => 'The assistant could not complete that request.'], 500);
}
