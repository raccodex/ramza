<?php
// +------------------------------------------------------------------------+
// | @author RacCodex (RacCodex)
// | @author_url 1: http://www.ramza.com
// | @author_url 2: http://codecanyon.net/user/RacCodex
// | @author_email: ramzasocial@gmail.com
// +------------------------------------------------------------------------+
// | ramza - The Ultimate Social Networking Platform
// | Copyright (c) 2018 ramza. All rights reserved.
// +------------------------------------------------------------------------+
$response_data       = array(
    'api_status' => 400
);
$access_token        = Wo_Secure($_GET['access_token']);
$user_id             = $wo['user']['user_id'];
$remove_access_token = mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `session_id` = '{$access_token}'");
if ($remove_access_token) {
    //$update = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `device_id` = '' WHERE `user_id` = '{$user_id}'");
    $update  = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `android_m_device_id` = '' , `ios_m_device_id` = '' , `android_n_device_id` = '' , `ios_n_device_id` = '' WHERE `user_id` = '{$user_id}'");
    if ($update) {
        cache($user_id, 'users', 'delete');
        $response_data = array(
            'api_status' => 200
        );
    }
}