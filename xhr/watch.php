<?php
if ($f == 'watch') {
    if ($s == 'load_more_posts') {

    	$filterData = [
    		'filter_by' => 'local_video',
            'not_monetization' => true,
    	];

    	if (isset($_GET['after_post_id']) && is_numeric($_GET['after_post_id']) && $_GET['after_post_id'] > 0) {
    		$filterData['after_post_id'] = Wo_Secure($_GET['after_post_id']);
        }

    	$stories = Wo_GetPosts($filterData);
        if (count($stories) > 0) {
        	foreach ($stories as $wo['story']) {
                echo Wo_LoadPage('story/content');
            }
        }

        exit();
    }
}