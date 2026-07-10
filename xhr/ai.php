<?php 
if ($s == "generate") {
	$data['status'] = 400;

	if (!empty($_POST['text']) && !empty($_POST['num_outputs']) && in_array($_POST['num_outputs'], getAllowedImagesCount())) {

		if ($wo['config']['images_credit_system'] == 1 && shouldTopUpImageCredits($wo['user']['credits'],$_POST['num_outputs'])) {
			$data['message'] = $wo["lang"]["you_dont_have_enough_credits"];
			header("Content-type: application/json");
			echo json_encode($data);
			exit();
		}

		try {
			$size = $_POST['size'];
			if ($wo['config']['midjeourny_model'] != 'stability-ai-stable-diffusion') {
				$size = getAISize($_POST['size']);
			}

			$data = getMidJeournyImage($_POST['text'],$size,$_POST['num_outputs']);
			
		} catch (Exception $e) {
			$data['message'] = $e->getMessage();
		}
	}
	else{
		$data['message'] = $wo['lang']['please_check_details'];
	}
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
elseif ($s == 'convert') {
	$data['status'] = 400;
	if (!empty($_POST['text'])) {
		try {
			$type = 'avatar';
			if (!empty($_POST['type']) && in_array($_POST['type'], ['avatar','cover'])) {
				$type = Wo_Secure($_POST['type']);
			}
			$data = getMidJeournyUser($_POST['text'],$type);
		} catch (Exception $e) {
			$data['message'] = $e->getMessage();
		}
	}
	else{
		$data['message'] = $wo['lang']['please_check_details'];
	}
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
elseif ($s == "check") {
	$data['status'] = 400;
	if (!empty($_POST['id'])) {
		try {
			$data = checkMidJeourny($_POST['id']);
		} catch (Exception $e) {
			$data['message'] = $e->getMessage();
		}
	}
	else{
		$data['message'] = $wo['lang']['something_wrong'];
	}
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
elseif ($s == "openai") {
	$data['status'] = 400;
	if (!empty($_POST['text']) && !empty($_POST['num_outputs']) && in_array($_POST['num_outputs'], getAllowedImagesCount())) {
		if ($wo['config']['images_credit_system'] == 1 && shouldTopUpImageCredits($wo['user']['credits'],$_POST['num_outputs'])) {
			$data['message'] = $wo["lang"]["you_dont_have_enough_credits"];
			header("Content-type: application/json");
			echo json_encode($data);
			exit();
		}

		try {
			$result = getOpenAiImage($_POST['text'],$_POST['size'],$_POST['num_outputs']);
			if (!empty($result['data'])) {
				$urls = array_map(function ($img) {
					return loadImageContent($img->url);
				}, $result['data']);
				$data['status'] = 200;
				$data['output'] = $urls;
				$data['credits'] = $db->where('user_id',$wo['user']['id'])->getValue(T_USERS,'credits');
			}
			else{
				$data['message'] = $wo['lang']['something_wrong'];
			}
		} catch (Exception $e) {
			$data['message'] = $e->getMessage();
		}
	}
	else{
		$data['message'] = $wo['lang']['please_check_details'];
	}
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
elseif ($s == 'openai_post') {
	$data['status'] = 400;
	if (!empty($_POST['text']) && !empty($_POST['count'])) {
		try {
			$data = getOpenAiText($_POST['text'],$_POST['count']);
		} catch (Exception $e) {
			$data['message'] = $e->getMessage();
		}
	}
	else{
		$data['message'] = $wo['lang']['please_check_details'];
	}
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
elseif ($s == 'generateBlog') {
	$data['status'] = 400;
	if (!empty($_POST['text']) && !empty($_POST['count'])) {
		try {
			$thumbnail = false;
			if (!empty($_POST['thumbnail']) && $_POST['thumbnail'] == 'on') {
				$thumbnail = true;
			}
			$data = getOpenAiBlog($_POST['text'],$_POST['count'],$thumbnail);
		} catch (Exception $e) {
			$data['message'] = $e->getMessage();
		}
	}
	else{
		$data['message'] = $wo['lang']['please_check_details'];
	}
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
