<?php

if (!$wo['config']['directory_system'] || $wo['config']['groups'] != 1) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'groups';
$wo['title'] = $wo['config']['siteTitle'];

$wo['content'] = Wo_LoadPage('directory/groups');
