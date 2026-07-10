<?php
if ($wo['config']['watch_page'] == 0) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}

if (!empty($_GET['id'])) {
    $story = $db->where('id', $_GET['id'])->where('is_reel', 0)->get(T_POSTS);

    if (!$story  || count($story) == 0) {
        header("Location: " . $wo['config']['site_url']);
        exit();
    }
    $wo['single_story'] = $story[0];
    if (isset($wo['single_story']->postFile) && strpos($wo['single_story']->postFile, 'videos') !== false) {
        header("Location: " . $wo['config']['site_url'] . '/post/' . $_GET['id']);
    }
}

$wo['videos_plays_in_lightbox'] = true;
$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'watch';
$wo['title'] = $wo['lang']['watch'];
$wo['content'] = Wo_LoadPage('watch/content');