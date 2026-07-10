<?php
session_unset();
if (!empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = '';
    $query               = mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `session_id` = '" . Wo_Secure($_SESSION['user_id']) . "'");
}
session_destroy();
if (isset($_COOKIE['user_id'])) {
    $query              = mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `session_id` = '" . Wo_Secure($_COOKIE['user_id']) . "'");
    $_COOKIE['user_id'] = '';
    unset($_COOKIE['user_id']);
    setcookie('user_id', '', -1);
    setcookie('user_id', '', -1, '/');
}
if(isset($_COOKIE['switched_accounts']) && $_COOKIE['switched_accounts'] !== '') {
    $_COOKIE['switched_accounts'] = '';
    unset($_COOKIE['switched_accounts']);
    setcookie('switched_accounts', '', -1);
    setcookie('switched_accounts', '', time() - 3600, '/');
setcookie('switched_accounts', '', -1,'/');
}
$_SESSION = array();
unset($_SESSION);
header("Location: " . $wo['config']['site_url'] . "/?cache=" . time());
exit();
