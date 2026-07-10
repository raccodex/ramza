<?php
if ($f == 'get_next_reel_video') {
    $wo['config']['second_post_button'] = 'disabled';
    $videos = [];
    $html = '';
    $postsData = array(
        'limit' => 3,
        'filter_by' => 'local_video',
        'order' => 'rand',
        'is_reel' => 'only',
    );
    $reelOwnerName = null;

    if (!empty($wo['watched_reels']) && is_array($wo['watched_reels'])) {
        // if (in_array($_GET['post_id'], $wo['watched_reels']) && $_GET['post_id'] !== end($wo['watched_reels'])) {
        //     $index = array_search($_GET['post_id'], $wo['watched_reels']);
        //     $videos = [];
        //     $videos[] = Wo_PostData($wo['watched_reels'][$index + 1]);
        // }
        // else{
            $postsData['not_in'] = $wo['watched_reels'];
        // }
    }

    $videos = Wo_GetPosts($postsData);

    if (!empty($_GET['user'])) {
        $reelOwnerName = Wo_Secure($_GET['user']);
        $reelOwnerId = Wo_UserIdFromUsername(Wo_Secure($_GET['user']));
        $postsData['publisher_id'] = $reelOwnerId;
    }

    foreach ($videos as $wo['story']) {
        $wo['page'] = 'reels';
        $wo['story']['likeCount'] = Wo_CountLikes($wo['story']['id']);
        $wo['story']['commentCount'] = Wo_CountPostComment($wo['story']['id']);
        $wo['reelOwnerName'] = $reelOwnerName;

        $media = array(
            'type' => 'post',
            'storyId' => $wo['story']['id'],
            'filename' => $wo['story']['postFile'],
            'name' => $wo['story']['postFileName'],
            'postFileThumb' => $wo['story']['postFileThumb'],
        );
        $video = Wo_DisplaySharedFile($media, '', $wo['story']['cache']);

        $html .= loadHTMLPage('reels/list',[
            'ID' => $wo['story']['id'],
            'URL' => $wo['config']['site_url']. "/reels/" . $wo['story']['id'],
            'CLASS' => 'hidden',
            'STORY_ARRAY' => $wo['story'],
            'PUBLISHER_ARRAY' => $wo['story']['publisher'],
            'VIDEO' => $video,
        ]);

        if (!in_array($wo['story']['id'], $wo['watched_reels'])) {
            $wo['watched_reels'][] = $wo['story']['id'];
        }
    }



    $data = array(
        'status' => 200,
        'html' => $html,
        'post_id' => (!empty($wo['story']) ? $wo['story']['id'] : ''),
        'url' => '',
    );

    if (!empty($wo['story']['id'])) {
        $data['url'] = Wo_SeoLink('index.php?link1=reels&id='.$wo['story']['id']);
    }
    
    
    if (!empty($wo['watched_reels'])) {
        setcookie('watched_reels', json_encode($wo['watched_reels']), time()+(60 * 60 * 24),'/');
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
