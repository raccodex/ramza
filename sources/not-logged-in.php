<?php
//if(!$wo['config']['directory']) {
//    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
//    exit();
//}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'home';
$wo['title']       = $wo['config']['siteTitle'];
$pg = 'home/not-logged-in';

$wo['content']     = Wo_LoadPage($pg);
