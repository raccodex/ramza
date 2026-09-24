<?php
if ($f === 'ramza_update') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $requestHash = isset($_POST['hash_id']) && !is_array($_POST['hash_id'])
        ? (string) $_POST['hash_id']
        : '';
    if (empty($wo['loggedin']) || !Wo_IsAdmin() || Wo_CheckSession($requestHash) !== true) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Administrator authorization is required.']);
        exit;
    }
    if ($s === 'check') {
        echo json_encode(Ramza_UpdateCheck(), JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($s === 'install') {
        @set_time_limit(300);
        $check = Ramza_UpdateCheck();
        if (($check['ok'] ?? false) !== true || empty($check['available']) || !is_array($check['release'] ?? null)) {
            echo json_encode(($check['ok'] ?? false) === true ? ['ok' => false, 'message' => 'No update is available.'] : $check, JSON_UNESCAPED_SLASHES);
            exit;
        }
        $expectedVersion = isset($_POST['expected_version']) && is_string($_POST['expected_version']) ? trim($_POST['expected_version']) : '';
        if ($expectedVersion !== '' && !hash_equals((string) ($check['release']['version'] ?? ''), $expectedVersion)) {
            echo json_encode(['ok' => false, 'message' => 'The release list changed. Check for updates again before installing.']);
            exit;
        }
        if (!empty($check['release']['is_local']) && !empty($check['release']['path']) && is_file((string) $check['release']['path'])) {
            $download = ['ok' => true, 'path' => (string) $check['release']['path']];
        } else {
            $download = Ramza_UpdateDownload($check['release']);
        }
        $result = ($download['ok'] ?? false) === true ? Ramza_UpdateApply((string) $download['path']) : $download;
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($s === 'manual') {
        @set_time_limit(300);
        $upload = $_FILES['update_package'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Choose a valid update ZIP.']);
            exit;
        }
        if ((int) ($upload['size'] ?? 0) > 314572800 || strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'The update must be a ZIP no larger than 300 MB.']);
            exit;
        }
        $directory = Ramza_UpdateDirectory() . DIRECTORY_SEPARATOR . 'manual';
        @mkdir($directory, 0750, true);
        $target = $directory . DIRECTORY_SEPARATOR . 'manual-' . gmdate('Ymd-His') . '.zip';
        if (!move_uploaded_file((string) $upload['tmp_name'], $target)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'The update upload could not be stored.']);
            exit;
        }
        echo json_encode(Ramza_UpdateApply($target), JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($s === 'rollback') {
        echo json_encode(Ramza_UpdateRollbackLatest(), JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Unknown update action.']);
    exit;
}
