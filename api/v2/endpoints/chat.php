<?php

$response_data = array(
    'api_status' => 400
);

$required_fields =  array(
                        'search',
                        'get_media',
                        'update_type',
                    );
if (!empty($_POST['type']) && in_array($_POST['type'], $required_fields)) {

	if ($_POST['type'] == 'search') {

		try {

			chatSearchValidation();

			$text = Wo_Secure($_POST['text']);

			if (!empty($_POST['user_id'])) {
				$user_id = Wo_Secure($_POST['user_id']);
				$db->where("((from_id = '".$wo['user']['user_id']."' AND to_id = '".$user_id."') OR (from_id = '".$user_id."' AND to_id = '".$wo['user']['user_id']."'))");
			}
			elseif (!empty($_POST['page_id'])) {
				$page_id = Wo_Secure($_POST['page_id']);

				$db->where("page_id",$page_id);
			}
			elseif (!empty($_POST['group_id'])) {
				$group_id = Wo_Secure($_POST['group_id']);

				$db->where("group_id",$group_id);
			}

			$messages = $db->where("text","%".$text."%","like")->get(T_MESSAGES);
			$search = array_map(function ($message)
			{
				return GetMessageById($message->id);
			}, $messages);

			$response_data = array(
	            'api_status' => 200,
	            'data' => $search
	        );

		} catch (Exception $e) {
			$error_code    = 5;
	    	$error_message = $e->getMessage();
		}
	}
	if ($_POST['type'] == 'get_media') {

		try {

			chatGetMediaValidation();

			if (!empty($_POST['user_id'])) {
				$user_id = Wo_Secure($_POST['user_id']);
				$db->where("((from_id = '".$wo['user']['user_id']."' AND to_id = '".$user_id."') OR (from_id = '".$user_id."' AND to_id = '".$wo['user']['user_id']."'))");
			}
			elseif (!empty($_POST['page_id'])) {
				$page_id = Wo_Secure($_POST['page_id']);

				$db->where("page_id",$page_id);
			}
			elseif (!empty($_POST['group_id'])) {
				$group_id = Wo_Secure($_POST['group_id']);

				$db->where("group_id",$group_id);
			}

			if ($_POST['media_type'] == 'images') {
				$db->where("media","%upload/photos%","like");
			}
			elseif ($_POST['media_type'] == 'videos') {
				$db->where("media","%upload/videos%","like");
			}
			elseif ($_POST['media_type'] == 'audio') {
				$db->where("media","%upload/sounds%","like");
			}
			elseif ($_POST['media_type'] == 'docs') {
				$db->where("media","%upload/files%","like");
			}
			elseif ($_POST['media_type'] == 'links') {
				$db->where("text","%[/a]%","like");
			}

			$offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
		    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

		    if (!empty($offset)) {
		    	$db->where('id',$offset,'<');
		    }

			$messages = $db->orderBy('id','DESC')->get(T_MESSAGES,$limit);
			$search = array_map(function ($message)
			{
				return GetMessageById($message->id);
			}, $messages);

			$response_data = array(
	            'api_status' => 200,
	            'data' => $search
	        );


		} catch (Exception $e) {
			$error_code    = 5;
	    	$error_message = $e->getMessage();
		}
	}
	if ($_POST['type'] == 'update_type') {

		try {

			chatUpdateTypeValidation();

			$time_array = [
				'1_day' => (60 * 60 * 24),
				'7_day' => (60 * 60 * 24 * 7),
				'90_day' => (60 * 60 * 24 * 90),
				'off' => ''
			];

			$db->where('id',$wo['chat']->id)->update(T_U_CHATS,[
				'type' => Wo_Secure($_POST['type']),
				'disappearing_time' => $time_array[$_POST['disappearing_time']]
			]);

			$response_data = array(
	            'api_status' => 200,
	            'message' => 'chat updated successfully'
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