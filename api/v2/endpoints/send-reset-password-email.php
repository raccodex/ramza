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
$response_data = array(
    'api_status' => 400,
);
if (empty($_POST['email'])) {
    $error_code    = 3;
    $error_message = 'email (POST) is missing';
}
if (empty($error_code)) {
    if (Wo_EmailExists($_POST['email']) === false) {
        $error_code    = 6;
        $error_message = 'Email not found';
    } else {
    	$user_recover_data         = Wo_UserData(Wo_UserIdFromEmail($_POST['email']));
        $subject                   = $config['siteName'] . ' ' . $wo['lang']['password_rest_request'];
        try {
            $code = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $code = hash('sha256', uniqid((string)mt_rand(), true) . microtime(true));
        }
        $user_recover_data['link'] = Wo_Link('index.php?link1=reset-password&code=' . $user_recover_data['user_id'] . '_' . $code);
        $expires_at = time() + (60 * 60 * 12);
        mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `email_code` = '" . Wo_Secure($code) . "', `time_code_sent` = '" . (int)$expires_at . "' WHERE `user_id` = " . (int)$user_recover_data['user_id']);
        cache($user_recover_data['user_id'], 'users', 'delete');
        $wo['recover']             = $user_recover_data;
        $body                      = Wo_LoadPage('emails/recover');
        $send_message_data         = array(
            'from_email' => $wo['config']['siteEmail'],
            'from_name' => $wo['config']['siteName'],
            'to_email' => $_POST['email'],
            'to_name' => '',
            'subject' => $subject,
            'charSet' => 'utf-8',
            'message_body' => $body,
            'is_html' => true,
            'return' => 'error'
        );
        $send                      = Wo_SendMessage($send_message_data);
        if ($send) {
        	$response_data = array(
			    'api_status' => 200,
			);
        } else {
            mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `email_code` = '', `time_code_sent` = '0' WHERE `user_id` = " . (int)$user_recover_data['user_id']);
            cache($user_recover_data['user_id'], 'users', 'delete');
            error_log('[Ramza API password recovery] Mail delivery failed: ' . preg_replace('/[\r\n]+/', ' ', (string)$send));
        	$error_code    = 7;
            $error_message = 'Failed to send the email, please check your server email settings.';
        }
    }
}
