<?php
function Wo_SendPushNotification($data = array(), $push_type = 'chat') {
    global $sqlConnect, $wo;
    if (empty($data)) {
        return false;
    }
    if (empty($data['notification']['notification_content'])) {
        return false;
    }
    if (empty($data['send_to'])) {
        return false;
    }
    if ($wo['config']['push'] == 0) {
        return false;
    }
    $app_id  = '';
    $app_key = '';
    if ($push_type == 'android_messenger') {
        $app_id  = $wo['config']['android_m_push_id'];
        $app_key = $wo['config']['android_m_push_key'];
    } else if ($push_type == 'ios_messenger') {
        $app_id  = $wo['config']['ios_m_push_id'];
        $app_key = $wo['config']['ios_m_push_key'];
    } else if ($push_type == 'android_native') {
        $app_id  = $wo['config']['android_n_push_id'];
        $app_key = $wo['config']['android_n_push_key'];
    } else if ($push_type == 'ios_native') {
        $app_id  = $wo['config']['ios_n_push_id'];
        $app_key = $wo['config']['ios_n_push_key'];
    } else if ($push_type == 'web') {
        $app_id  = $wo['config']['web_push_id'];
        $app_key = $wo['config']['web_push_key'];
    }
    $data['notification']['notification_content'] = Wo_EmoPhone($data['notification']['notification_content']);
    $data['notification']['notification_content'] = Wo_EditMarkup($data['notification']['notification_content']);
    $final_request_data                           = array(
        'app_id' => $app_id,
        'include_subscription_ids' => array_values(array_unique($data['send_to'])),
        'target_channel' => 'push',
        'isChrome' => false,
        'contents' => array(
            'en' => $data['notification']['notification_content']
        ),
        'headings' => array(
            'en' => $data['notification']['notification_title']
        ),
        'android_led_color' => 'FF0000FF',
        'priority' => 10
    );
    if (!empty($data['notification']['notification_data'])) {
        $final_request_data['data'] = $data['notification']['notification_data'];
    }
    if (!empty($data['notification']['notification_image'])) {
        $final_request_data['large_icon'] = $data['notification']['notification_image'];
    }
    $fields = json_encode($final_request_data);
    $ch     = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.onesignal.com/notifications");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
        'Authorization: Key ' . $app_key
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = json_decode((string) $response);
    if ($http_code >= 200 && $http_code < 300 && !empty($response->id)) {
        return $response->id;
    }
    return false;
}
?>
