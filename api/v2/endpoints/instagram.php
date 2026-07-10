<?php
if ($wo['config']['website_mode'] != 'instagram') {
	$error_code    = 5;
    $error_message = 'instagram mode not enabled';
    $response_data       = array(
        'api_status'     => '404',
        'errors'         => array(
            'error_id'   => $error_code,
            'error_text' => $error_message
        )
    );
    echo json_encode($response_data, JSON_PRETTY_PRINT);
    exit();
}

$types = array('load_explore_posts');
if (!empty($_POST['type']) && in_array($_POST['type'], $types)) {

    if ($_POST['type'] == 'load_explore_posts') {
        try {

            $posts = [];

            $offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
            $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

            if (!empty($offset)) {
                $db->where('id',$offset,'<');
            }

            $explore_posts = $db->where('postPrivacy','0')->where('multi_image_post','0')->where('active',1)->where("((postFile LIKE '%.jpg%' || postFile LIKE '%.jpeg%' || postFile LIKE '%.png%' || postFile LIKE '%.gif%' || postFile LIKE '%.mp4%' || postFile LIKE '%.mkv%' || postFile LIKE '%.avi%' || postFile LIKE '%.webm%' || postFile LIKE '%.mov%' || postFile LIKE '%.m3u8%' || postSticker != '' || postPhoto != '' || album_name != '' || multi_image = '1'))")->orderBy('id','DESC')->get(T_POSTS,$limit,array('id'));

            foreach ($explore_posts as $key => $value) {

                $post = Wo_PostData($value->id);

                $post['shared_info'] = null;

                if (!empty($post['postFile'])) {
                    $post['postFile'] = Wo_GetMedia($post['postFile']);
                }
                if (!empty($post['postFileThumb'])) {
                    $post['postFileThumb'] = Wo_GetMedia($post['postFileThumb']);
                }
                if (!empty($post['postPlaytube'])) {
                    $post['postText'] = strip_tags($post['postText']);
                }



                if (!empty($post['publisher'])) {
                    foreach ($non_allowed as $key4 => $value4) {
                      unset($post['publisher'][$value4]);
                    }
                }
                else{
                    $post['publisher'] = null;
                }

                if (!empty($post['user_data'])) {
                    foreach ($non_allowed as $key4 => $value4) {
                      unset($post['user_data'][$value4]);
                    }
                }
                else{
                    $post['user_data'] = null;
                }

                if (!empty($post['parent_id'])) {
                    $shared_info = Wo_PostData($post['parent_id']);
                    if (!empty($shared_info)) {
                        if (!empty($shared_info['publisher'])) {
                            foreach ($non_allowed as $key4 => $value4) {
                              unset($shared_info['publisher'][$value4]);
                            }
                        }
                        else{
                            $shared_info['publisher'] = null;
                        }

                        if (!empty($shared_info['user_data'])) {
                            foreach ($non_allowed as $key4 => $value4) {
                              unset($shared_info['user_data'][$value4]);
                            }
                        }
                        else{
                            $shared_info['user_data'] = null;
                        }

                        if (!empty($shared_info['get_post_comments'])) {
                            foreach ($shared_info['get_post_comments'] as $key3 => $comment) {

                                foreach ($non_allowed as $key5 => $value5) {
                                  unset($shared_info['get_post_comments'][$key3]['publisher'][$value5]);
                                }
                            }
                        }
                    }
                    $post['shared_info'] = $shared_info;
                }

                if (!empty($post['get_post_comments'])) {
                    foreach ($post['get_post_comments'] as $key3 => $comment) {

                        foreach ($non_allowed as $key5 => $value5) {
                          unset($post['get_post_comments'][$key3]['publisher'][$value5]);
                        }
                    }
                }
                $posts[] = $post;
            }

            $response_data = array(
                'api_status' => 200,
                'data' => $posts
            );

        } catch (Exception $e) {
            $error_code    = 5;
            $error_message = $e->getMessage();
        }
    }

}
else{
    $error_code    = 4;
    $error_message = 'type can not be empty';
}