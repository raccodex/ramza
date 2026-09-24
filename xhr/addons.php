<?php
if ($f === 'addons') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$wo['loggedin'] || !Wo_IsAdmin() || Wo_CheckMainSession($hash_id) === false) {
        http_response_code(403);
        echo json_encode(['status' => 403, 'message' => 'Admin session required.']);
        exit;
    }
    if ($s !== 'activate') {
        http_response_code(404);
        echo json_encode(['status' => 404, 'message' => 'Unknown add-on action.']);
        exit;
    }
    $feature = isset($_POST['feature']) && !is_array($_POST['feature']) ? strtolower(trim((string) $_POST['feature'])) : '';
    $code = isset($_POST['activation_code']) && !is_array($_POST['activation_code']) ? trim((string) $_POST['activation_code']) : '';
    if (!function_exists('Ramza_AddonValidId') || !Ramza_AddonValidId($feature)) {
        http_response_code(400);
        echo json_encode(['status' => 400, 'message' => 'Select a valid add-on.']);
        exit;
    }
    $result = Ramza_LicenseActivateFeature($feature, $code);
    echo json_encode([
        'status' => !empty($result['ok']) ? 200 : 400,
        'message' => (string) ($result['message'] ?? 'Activation failed.'),
        'feature' => $feature,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

