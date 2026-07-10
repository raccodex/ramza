<?php
if (!$wo['loggedin'] || empty($_GET['session'])) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}
if ($wo['config']['switch_account'] != '1') {
    header("Location: " . $wo['config']['site_url']);
    exit();
}
$session_id = Wo_Secure($_GET['session']);
$wo['user_session'] = Wo_GetUserFromSessionID($session_id);
if (!empty($wo['user_session']) && is_numeric($wo['user_session']) && $wo['user_session'] > 0) {
    $user = Wo_UserData($wo['user_session']);
    if (!empty($user)) {
        $add = true;
        if (!empty($wo['switched_accounts'])) {
            foreach ($wo['switched_accounts'] as $key => $value) {
                if ($value['user_id'] == $wo['user']['user_id']) {
                    $add = false;
                    unset($wo['switched_accounts'][$key]);
                }
                if ($user['user_id'] == $value['user_id']) {
                    unset($wo['switched_accounts'][$key]);
                }
            }
        }
        if ($add == true) {
            $session_user = '';
            if (!empty($_SESSION['user_id'])) {
                $session_user = $_SESSION['user_id'];
            }
            if (!empty($_COOKIE['user_id'])) {
                $session_user = $_COOKIE['user_id'];
            }
            $info = array('email' => $wo['user']['email'],
                'name' => $wo['user']['name'],
                'avatar' => $wo['user']['avatar'],
                'session' => $session_user,
                'user_id' => $wo['user']['user_id']);
            $wo['switched_accounts'][] = $info;
        }
        setcookie("switched_accounts", json_encode($wo['switched_accounts']), time() + (10 * 365 * 24 * 60 * 60));
        session_unset();
        $_SESSION['user_id'] = '';
        session_destroy();
        $_SESSION = array();
        unset($_SESSION);
        if (isset($_COOKIE['user_id'])) {
            $_COOKIE['user_id'] = '';
            unset($_COOKIE['user_id']);
            setcookie('user_id', '', -1);
            setcookie('user_id', '', -1, '/');
        }
        $session = Wo_CreateLoginSession($user['user_id']);
        $_SESSION['user_id'] = $session;
        setcookie("user_id", $session, time() + (10 * 365 * 24 * 60 * 60));
    }
}


header("Location: " . $wo['config']['site_url']);
exit();

