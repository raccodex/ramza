<?php
$redirect_home = false;

if ($wo['loggedin'] == true) {
    $redirect_home = true;
    if (isset($_GET['type']) && $_GET['type'] == 'add_account') {
        $redirect_home = false;
    }
}

if($redirect_home) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}
$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'welcome';
$wo['title']       = $wo['config']['siteTitle'];
$wo['content']     = Wo_LoadPage('welcome/content');
