<?php 
if ($f == 'get_previous_video') {
    $wo['no_pre_reel'] = 0;
    $videos = [];
    $html      = '';
    $postsData = array(
        'limit' => 1,
        'filter_by' => 'video',
        'order' => 'ASC',
        'before_post_id' => Wo_Secure($_GET['post_id'])
    );
        if (!empty($_GET['type']) && !empty($_GET['id'])) {
        if ($_GET['type'] == 'profile') {
            $postsData['publisher_id'] = $_GET['id'];
        } else if ($_GET['type'] == 'page') {
            $postsData['page_id'] = $_GET['id'];
        } else if ($_GET['type'] == 'group') {
            $postsData['group_id'] = $_GET['id'];
        }
    }

    if ($_GET['type'] == 'reels') {
        if (!empty($wo['watched_reels']) && is_array($wo['watched_reels'])) {
            if (in_array($_GET['post_id'], $wo['watched_reels'])) {
                $_id = $wo['watched_reels'][0];
                if ($_GET['post_id'] !== $wo['watched_reels'][0]) {
                    $index = array_search($_GET['post_id'], $wo['watched_reels']);
                    $_id = $wo['watched_reels'][$index - 1];
                    if (($index - 1) == 0) {
                        //$wo['no_pre_reel'] = 1;
                    }
                }
                $videos = [];
                $videos[] = Wo_PostData($_id);
            }
            else{
                $data = array(
                    'status' => 200,
                    'html' => $html,
                    'post_id' => '',
                );
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
        }
        $postsData['order'] = 'rand';
        $postsData['is_reel'] = 'only';
        unset($postsData['after_post_id']);
        unset($postsData['before_post_id']);
        //$postsData['after_post_id'] = Wo_Secure($_GET['post_id']);
    }

    if (!empty($_GET['type']) && $_GET['type'] == 'watch' || $_GET['type'] == 'reels') {
        $postsData['filter_by'] = 'local_video';
    }

    // if ($_GET['user']) {
    //     $reelOwnerName = Wo_Secure($_GET['user']);
    //     $reelOwnerId = Wo_UserIdFromUsername(Wo_Secure($_GET['user']));
    //     $postsData['publisher_id'] = $reelOwnerId;
    // }

    if (empty($videos)) {
        $videos = Wo_GetPosts($postsData);
    }

    if (!$videos) {
        if ($_GET['type'] == 'reels') {
            $videos = Wo_GetPosts($postsData);
        }
    }

    foreach ($videos as $wo['story']) {
        if($wo['story']['is_reel'] == 1) {
            $wo['page'] = 'reels';
            $wo['story']['likeCount'] = Wo_CountLikes($wo['story']['id']);
            $wo['story']['commentCount'] = Wo_CountPostComment($wo['story']['id']);
        }
        if (empty($wo['story']['album_name']) && $wo['story']['multi_image'] != 1) {
            $html .= Wo_LoadPage('lightbox/content');
        }
    }
    $data = array(
        'status' => 200,
        'html' => $html,
        'post_id' => (!empty($wo['story']) ? $wo['story']['id'] : ''),
    );
    if ($_GET['type'] == 'reels' && !empty($wo['story'])) {
        $data['url'] = Wo_SeoLink('index.php?link1=reels&id='.$wo['story']['id']);
        // if (!in_array($wo['story']['id'], $wo['watched_reels'])) {
        //     $wo['watched_reels'][] = $wo['story']['id'];
        // }
        // if (!empty($wo['watched_reels'])) {
        //     setcookie('watched_reels', json_encode($wo['watched_reels']), time()+(60 * 60 * 24),'/');
        // }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
