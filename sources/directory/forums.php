<?php

if (!$wo['config']['directory_system'] || $wo['config']['forum'] != 1) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['title'] = $wo['config']['siteTitle'];
$wo['page']        = 'forum';
$wo['active']      = 'forums';
// $wo['sections']    = Wo_GetForumSec(array("forums" => true,"limit" => 20));
// $wo['f-total']     = Wo_GetTotalForums();
$wo['content'] = Wo_LoadPage('directory/forums');
