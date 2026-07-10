<?php

$response_data = array(
    'api_status' => 400
);

$required_fields =  array(
                        'user',
                        'page',
                        'group',
                    );
if (!empty($_POST['type']) && in_array($_POST['type'], $required_fields)) {
    if ($_POST['type'] == 'user') {
    	try {
	    	$avatar = 'upload/photos/d-avatar.jpg';
	    	if ($wo['user']['gender'] == 'female') {
	    		$avatar = 'upload/photos/f-avatar.jpg';
	    	}
	    	

	    	$db->where('user_id',$wo['user']['user_id'])->update(T_USERS,[
	    		'avatar' => $avatar
	    	]);

	    	if ($wo['user']['avatar_org'] != 'upload/photos/d-avatar.jpg' && $wo['user']['avatar_org'] != 'upload/photos/f-avatar.jpg') {
	    		$explode2 = @end(explode('.', $wo['user']['avatar_org']));
		        $explode3 = @explode('.', $wo['user']['avatar_org']);
		        $media_2 = $explode3[0] . '_avatar_full.' . $explode2;
		        @unlink(trim($media_2));
		        @unlink($wo['user']['avatar_org']);
		        $delete_from_s3 = Wo_DeleteFromToS3($wo['user']['avatar_org']);
		        $delete_from_s3 = Wo_DeleteFromToS3($media_2);
	    	}

	    	$user = Wo_UserData($wo['user']['user_id']);

	    	$response_data = array(
	            'api_status' => 200,
	            'data' => $user
	        );
	    } catch (Exception $e) {
			$error_code    = 5;
	    	$error_message = $e->getMessage();
		}
    }
    elseif ($_POST['type'] == 'page') {
    	try {
	    	resetPageAvatarValidation();

	    	$avatar = 'upload/photos/d-page.jpg';

	    	$db->where('page_id',$wo['page']['page_id'])->update(T_PAGES,[
	    		'avatar' => $avatar
	    	]);

	    	if ($wo['page']['avatar_org'] != 'upload/photos/d-page.jpg') {
		        @unlink($wo['page']['avatar_org']);
		        $delete_from_s3 = Wo_DeleteFromToS3($wo['page']['avatar_org']);
	    	}

	    	$page = Wo_PageData($wo['page']['page_id']);

	    	$response_data = array(
	            'api_status' => 200,
	            'data' => $page
	        );

        } catch (Exception $e) {
			$error_code    = 5;
	    	$error_message = $e->getMessage();
		}

    }
    elseif ($_POST['type'] == 'group') {
    	try {
	    	resetGroupAvatarValidation();

	    	$avatar = 'upload/photos/d-group.jpg ';

	    	$db->where('id',$wo['group']['id'])->update(T_GROUPS,[
	    		'avatar' => $avatar
	    	]);

	    	if ($wo['group']['avatar_org'] != 'upload/photos/d-group.jpg') {
		        @unlink($wo['group']['avatar_org']);
		        $delete_from_s3 = Wo_DeleteFromToS3($wo['group']['avatar_org']);
	    	}

	    	$group = Wo_GroupData($_POST['group_id']);

	    	$response_data = array(
	            'api_status' => 200,
	            'data' => $group
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