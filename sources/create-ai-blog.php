<?php
if (Wo_CanBlog() == false || $wo['config']['ai_blog_system'] != 1) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'create-ai-blog';
$wo['title']       = $wo['lang']['create_new_article'];
$wo['content']     = Wo_LoadPage('blog/create-blog');