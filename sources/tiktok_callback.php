<?php


if (!empty($_GET['code']) && !empty($_GET['state'])) {
	$site_url_login = $config['site_url'];
	if(substr($site_url_login, -1) == '/') {
	    $site_url_login = substr($site_url_login, 0, -1);
	}
	$site_url_login = $site_url_login. '/login-with.php?provider=TikTok&code=' . $_GET['code'] . '&state=' . $_GET['state'];
	header("Location: " . $site_url_login);
  	exit();
}
else{
	header("Location: " . $wo['config']['site_url']);
    exit();
}