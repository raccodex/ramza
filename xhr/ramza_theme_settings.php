<?php

$data = array(
    'status' => 400,
    'error' => 'Unable to save the theme option. Please refresh and try again.'
);

header('Content-type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $data['error'] = 'This action requires a POST request.';
    echo json_encode($data);
    exit();
}

if (!Wo_IsAdmin()) {
    http_response_code(403);
    $data['error'] = 'Administrator access is required.';
    echo json_encode($data);
    exit();
}

if (!Wo_CheckSession($hash_id)) {
    http_response_code(403);
    $data['error'] = 'Your session expired. Refresh the page and try again.';
    echo json_encode($data);
    exit();
}

if ($s !== 'update') {
    http_response_code(400);
    $data['error'] = 'Unsupported theme settings action.';
    echo json_encode($data);
    exit();
}

$theme_settings_table = defined('T_THEME_SETTINGS') ? T_THEME_SETTINGS : 'Ramza_ThemeSettings';
$table_name = str_replace('`', '', (string)$theme_settings_table);
$table_result = mysqli_query($wo['sqlConnect'], "SHOW TABLES LIKE '" . mysqli_real_escape_string($wo['sqlConnect'], $table_name) . "'");
if (!$table_result || mysqli_num_rows($table_result) === 0) {
    http_response_code(409);
    $data['error'] = 'Theme settings are not installed. Re-run the Ramza database update first.';
    echo json_encode($data);
    exit();
}

$allowed_settings = array(
    'tag_header_layout',
    'tag_expand_search',
    'tag_anron_ico_head',
    'tag_prods_slider',
    'tag_prods_cat_slider',
    'tag_prods_autoload',
    'tag_send_comment',
    'tag_show_comments',
    'tag_profile_qr',
    'tag_welcome_layout',
    'tag_trend',
    'tag_hide_menu',
    'tag_show_side_trend',
    'tag_auto_dark'
);

$submitted = array();
foreach ($allowed_settings as $setting_name) {
    if (array_key_exists($setting_name, $_POST)) {
        $submitted[$setting_name] = ((string)$_POST[$setting_name] === '1') ? '1' : '0';
    }
}

if ($submitted === array()) {
    http_response_code(422);
    $data['error'] = 'No valid theme option was submitted.';
    echo json_encode($data);
    exit();
}

$connection = $wo['sqlConnect'];
$updated = array();
$failed = false;
mysqli_begin_transaction($connection);
foreach ($submitted as $setting_name => $setting_value) {
    $safe_name = mysqli_real_escape_string($connection, $setting_name);
    $safe_value = mysqli_real_escape_string($connection, $setting_value);
    $query = "UPDATE `{$table_name}` SET `value` = '{$safe_value}' WHERE `name` = '{$safe_name}' LIMIT 1";
    if (!mysqli_query($connection, $query)) {
        $failed = true;
        break;
    }
    $updated[$setting_name] = (int)$setting_value;
}

if ($failed) {
    mysqli_rollback($connection);
    http_response_code(500);
    $data['error'] = 'The theme option could not be saved. No settings were changed.';
} else {
    mysqli_commit($connection);
    $data = array(
        'status' => 200,
        'message' => 'Theme option saved.',
        'settings' => $updated
    );
}

echo json_encode($data);
exit();

