<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
if ($wo['config']['live_video'] != 1 || !$wo['config']['can_use_live']) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
$liveProvider = function_exists('Ramza_LiveProvider') ? Ramza_LiveProvider() : 'disabled';
$nativeLive = $liveProvider === 'native_webrtc';
if ($liveProvider === 'disabled') {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
$if_live = $db->where('user_id', $wo['user']['id'])->where('stream_name', '', '!=')->where('live_time', time() - 5, '>=')->getValue(T_POSTS, 'COUNT(*)');
if ($if_live > 0) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
if (!$nativeLive) {
    include_once 'assets/libraries/AgoraDynamicKey/sample/RtcTokenBuilderSample.php';
}
$db->where('time', time() - 60, '<')->delete(T_LIVE_SUB);
$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'live';
$wo['title']       = $wo['lang']['live'];
$wo['content']     = Wo_LoadPage('live/content');
