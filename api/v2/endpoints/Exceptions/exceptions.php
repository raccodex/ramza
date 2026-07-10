<?php

function chatUpdateTypeValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['chat_id']) || empty($_POST['type']) || !in_array($_POST['type'], ['disappearing','chat'])) {
        throw new Exception("chat_id can not be empty");
    }

    if (empty($_POST['disappearing_time']) || !in_array($_POST['disappearing_time'], ['1_day','7_day','90_day','off'])) {
        throw new Exception("disappearing_time can not be empty");
    }

    $wo['chat'] = $db->where('id',Wo_Secure($_POST['chat_id']))->where('user_id',$wo['user']['user_id'])->getOne(T_U_CHATS);

    if (empty($wo['chat'])) {
        throw new Exception("conversation not found");
    }

}

function payFastLoadValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['amount'])) {
        throw new Exception("amount can not be empty");
    }

}

function aiMidJeournyCheckValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

}

function aiMidJeournyConvertImageValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['text']) || empty($_POST['convert_type']) || !in_array($_POST['convert_type'], ['avatar','cover'])) {
        throw new Exception("text , convert_type can not be empty");
    }

}

function aiMidJeournyImageValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['text']) || empty($_POST['size']) || empty($_POST['num_outputs']) || !in_array($_POST['num_outputs'], getAllowedImagesCount())) {
        throw new Exception("text , size , num_outputs can not be empty");
    }

    if ($wo['config']['images_credit_system'] == 1 && shouldTopUpImageCredits($wo['user']['credits'],$_POST['num_outputs'])) {
        throw new Exception("you do not have enough credits");
    }

}

function aiOpenaiImageValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['text']) || empty($_POST['size']) || empty($_POST['num_outputs']) || !in_array($_POST['num_outputs'], getAllowedImagesCount())) {
        throw new Exception("text , size , num_outputs can not be empty");
    }

    if ($wo['config']['images_credit_system'] == 1 && shouldTopUpImageCredits($wo['user']['credits'],$_POST['num_outputs'])) {
        throw new Exception("you do not have enough credits");
    }

}

function aiGenerateBlogValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['text']) || empty($_POST['count'])) {
        throw new Exception("text , count can not be empty");
    }

}

function aiOpenaiPostValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['text']) || empty($_POST['count'])) {
        throw new Exception("text , count can not be empty");
    }

}

function checkUsernameValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['username'])) {
        throw new Exception("username can not be empty");
    }
    // if (empty($_POST['type']) || !in_array($_POST['type'], ['user','group','page'])) {
    //     throw new Exception("type can not be empty");
    // }

}

function stripeCreateSessionValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['amount']) || !is_numeric($_POST['amount'])) {
        throw new Exception("amount can not be empty");
    }

}

function resetGroupAvatarValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['group_id']) || !is_numeric($_POST['group_id'])) {
        throw new Exception("group_id can not be empty");
    }

    $wo['group'] = Wo_GroupData($_POST['group_id']);

    if ($wo['group']['user_id'] != $wo['user']['user_id']) {
        throw new Exception("group not found");
    }
}

function resetPageAvatarValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['page_id']) || !is_numeric($_POST['page_id'])) {
        throw new Exception("page_id can not be empty");
    }

    $wo['page'] = Wo_PageData($_POST['page_id']);

    if ($wo['page']['user_id'] != $wo['user']['user_id']) {
        throw new Exception("page not found");
    }
}

function getOpenToUserByIdValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }
}

function getOpenToUserValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }

}

function editOpenToProvidingServicesValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['services'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_OPEN_TO);

    if (empty($wo['services']) || ($wo['services']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

}

function deleteOpenToProvidingServicesValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['services'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_OPEN_TO);

    if (empty($wo['services']) || ($wo['services']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

}

function addOpenToProvidingServicesValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['services'])) {
        throw new Exception($wo['lang']['services_empty']);
    }
    else if (empty($_POST['job_location'])) {
        throw new Exception($wo['lang']['location_empty']);
    }
    else if (empty($_POST['description'])) {
        throw new Exception($wo['lang']['description_empty']);
    }
}

function editOpenToJobValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['job'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_OPEN_TO);

    if (empty($wo['job']) || ($wo['job']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

    if (!empty($_POST['job_type'])) {
        foreach ($_POST['job_type'] as $key => $value) {
            if (!in_array($value, array('full_time','contract','part_time','internship','temporary'))) {
                throw new Exception($wo['lang']['job_type_empty']);
            }
        }
    }
    if (!empty($_POST['workplaces'])) {
        foreach ($_POST['workplaces'] as $key => $value) {
            if (!in_array($value, array('on_site','hybrid','remote'))) {
                throw new Exception($wo['lang']['workplaces_empty']);
            }
        }
    }

}

function deleteOpenToJobValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['job'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_OPEN_TO);

    if (empty($wo['job']) || ($wo['job']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

}

function addOpenToJobValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['job_title'])) {
        throw new Exception($wo['lang']['Job_title_empty']);
    }
    else if (empty($_POST['job_location'])) {
        throw new Exception($wo['lang']['job_location_empty']);
    }
    else if (empty($_POST['workplaces'])) {
        throw new Exception($wo['lang']['workplaces_empty']);
    }
    else if (empty($_POST['job_type'])) {
        throw new Exception($wo['lang']['job_type_empty']);
    }

    foreach ($_POST['job_type'] as $key => $value) {
        if (!in_array($value, array('full_time','contract','part_time','internship','temporary'))) {
            throw new Exception($wo['lang']['job_type_empty']);
        }
    }
    foreach ($_POST['workplaces'] as $key => $value) {
        if (!in_array($value, array('on_site','hybrid','remote'))) {
            throw new Exception($wo['lang']['workplaces_empty']);
        }
    }
}

function getProjectByIdValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }
}

function getUserProjectsValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }
}

function editProjectValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['project'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_PROJECTS);

    if (empty($wo['project']) || ($wo['project']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

    if (!empty($_POST['project_url']) && !preg_match('/^(http|https):\\/\\/[a-z0-9_]+([\\-\\.]{1}[a-z_0-9]+)*\\.[_a-z]{1,100}' . '((:[0-9]{1,5})?\\/.*)?$/i', $_POST['project_url'])) {
        throw new Exception($wo['lang']['valid_link']);
    }
    if (!empty($_POST['project_start']) && !empty($_POST['project_end'])) {
        $date_start = explode('-', $_POST['project_start']);
        $date_end = explode('-', $_POST['project_end']);
        if ($date_start[0] < $date_end[0]) {
        }
        else{
            if ($date_start[0] > $date_end[0]) {
                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
            }
            else{
                if ($date_start[1] < $date_end[1]) {
                }
                else{
                    if ($date_start[1] > $date_end[1]) {
                        throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                    }
                    else{
                        if ($date_start[2] < $date_end[2]) {

                        }
                        else{
                            if ($date_start[2] > $date_end[2]) {
                                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                            }
                        }
                    }
                }
            } 
        }
    }

}

function deleteProjectValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['project'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_PROJECTS);

    if (empty($wo['project']) || ($wo['project']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

}

function addProjectValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['name'])) {
        throw new Exception($wo['lang']['name_empty']);
    }
    if (empty($_POST['project_start'])) {
        throw new Exception($wo['lang']['start_date_empty']);
    }
    if (empty($_POST['project_end'])) {
        throw new Exception('project_end can not be empty');
    }
    if (!empty($_POST['project_url']) && !preg_match('/^(http|https):\\/\\/[a-z0-9_]+([\\-\\.]{1}[a-z_0-9]+)*\\.[_a-z]{1,100}' . '((:[0-9]{1,5})?\\/.*)?$/i', $_POST['project_url'])) {
        throw new Exception($wo['lang']['valid_link']);
    }
    if (!empty($_POST['project_start']) && !empty($_POST['project_end'])) {
        $date_start = explode('-', $_POST['project_start']);
        $date_end = explode('-', $_POST['project_end']);
        if ($date_start[0] < $date_end[0]) {
        }
        else{
            if ($date_start[0] > $date_end[0]) {
                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
            }
            else{
                if ($date_start[1] < $date_end[1]) {
                }
                else{
                    if ($date_start[1] > $date_end[1]) {
                        throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                    }
                    else{
                        if ($date_start[2] < $date_end[2]) {

                        }
                        else{
                            if ($date_start[2] > $date_end[2]) {
                                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                            }
                        }
                    }
                }
            } 
        }
    }
}

function getCertificationByIdValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }
}

function getUserCertificationValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }
}

function editCertificationValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['certification'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_CERTIFICATION);

    if (empty($wo['certification']) || ($wo['certification']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

}

function deleteCertificationValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['certification'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_CERTIFICATION);

    if (empty($wo['certification']) || ($wo['certification']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

}

function addCertificationValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['name'])) {
        throw new Exception($wo['lang']['name_empty']);
    }
    if (empty($_POST['issuing_organization'])) {
        throw new Exception($wo['lang']['issuing_organization_empty']);
    }
    if (empty($_POST['certification_start'])) {
        throw new Exception($wo['lang']['certification_start']);
    }
    if (empty($_POST['certification_end'])) {
        throw new Exception('certification_end can not be empty');
    }
    if (!empty($_POST['credential_url']) && !preg_match('/^(http|https):\\/\\/[a-z0-9_]+([\\-\\.]{1}[a-z_0-9]+)*\\.[_a-z]{1,100}' . '((:[0-9]{1,5})?\\/.*)?$/i', $_POST['credential_url'])) {
        throw new Exception($wo['lang']['valid_link']);
    }
    if (!empty($_POST['certification_start']) && !empty($_POST['certification_end'])) {
        $date_start = explode('-', $_POST['certification_start']);
        $date_end = explode('-', $_POST['certification_end']);
        if ($date_start[0] < $date_end[0]) {
        }
        else{
            if ($date_start[0] > $date_end[0]) {
                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
            }
            else{
                if ($date_start[1] < $date_end[1]) {
                }
                else{
                    if ($date_start[1] > $date_end[1]) {
                        throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                    }
                    else{
                        if ($date_start[2] < $date_end[2]) {

                        }
                        else{
                            if ($date_start[2] > $date_end[2]) {
                                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                            }
                        }
                    }
                }
            } 
        }
    }
}
function getExperienceByIdValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }
}
function getUserExperienceValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }
}
function editExperienceValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['experience'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_EXPERIENCE);

    if (empty($wo['experience']) || ($wo['experience']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

    if (!empty($_POST['link']) && !preg_match('/^(http|https):\\/\\/[a-z0-9_]+([\\-\\.]{1}[a-z_0-9]+)*\\.[_a-z]{1,100}' . '((:[0-9]{1,5})?\\/.*)?$/i', $_POST['link'])) {
        throw new Exception($wo['lang']['valid_link']);
    }
    if (!empty($_POST['experience_start']) && !empty($_POST['experience_end'])) {
        $date_start = explode('-', $_POST['experience_start']);
        $date_end = explode('-', $_POST['experience_end']);
        if ($date_start[0] < $date_end[0]) {
        }
        else{
            if ($date_start[0] > $date_end[0]) {
                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
            }
            else{
                if ($date_start[1] < $date_end[1]) {
                }
                else{
                    if ($date_start[1] > $date_end[1]) {
                        throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                    }
                    else{
                        if ($date_start[2] < $date_end[2]) {

                        }
                        else{
                            if ($date_start[2] > $date_end[2]) {
                                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                            }
                        }
                    }
                }
            } 
        }
    }

}

function deleteExperienceValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['experience'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_EXPERIENCE);

    if (empty($wo['experience']) || ($wo['experience']->user_id != $wo['user']['user_id'] && !Wo_IsAdmin())) {
        throw new Exception("You are not the owner");
    }

}

function addExperienceValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['title'])) {
        throw new Exception($wo['lang']['title_empty']);
    }
    if (empty($_POST['company_name'])) {
        throw new Exception($wo['lang']['company_name_empty']);
    }
    if (empty($_POST['employment_type']) || !in_array($_POST['employment_type'], array_keys($wo['employment_type']))) {
        throw new Exception($wo['lang']['employment_type_empty']);
    }
    if (empty($_POST['location'])) {
        throw new Exception($wo['lang']['location_empty']);
    }
    if (empty($_POST['experience_start'])) {
        throw new Exception($wo['lang']['start_date_empty']);
    }
    if (empty($_POST['industry'])) {
        throw new Exception($wo['lang']['industry_empty']);
    }
    if (empty($_POST['description'])) {
        throw new Exception($wo['lang']['description_empty']);
    }
    if (!empty($_POST['link']) && !preg_match('/^(http|https):\\/\\/[a-z0-9_]+([\\-\\.]{1}[a-z_0-9]+)*\\.[_a-z]{1,100}' . '((:[0-9]{1,5})?\\/.*)?$/i', $_POST['link'])) {
        throw new Exception($wo['lang']['valid_link']);
    }
    if (!empty($_POST['experience_start']) && !empty($_POST['experience_end'])) {
        $date_start = explode('-', $_POST['experience_start']);
        $date_end = explode('-', $_POST['experience_end']);
        if ($date_start[0] < $date_end[0]) {
        }
        else{
            if ($date_start[0] > $date_end[0]) {
                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
            }
            else{
                if ($date_start[1] < $date_end[1]) {
                }
                else{
                    if ($date_start[1] > $date_end[1]) {
                        throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                    }
                    else{
                        if ($date_start[2] < $date_end[2]) {

                        }
                        else{
                            if ($date_start[2] > $date_end[2]) {
                                throw new Exception($wo['lang']['please_choose_correct_experience_date']);
                            }
                        }
                    }
                }
            } 
        }
    }
}

function admobAddValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }

    $wo['ad_user'] = $db->where('user_id',Wo_Secure($_POST['user_id']))->getOne(T_USERS);

    if (empty($wo['ad_user'])) {
        throw new Exception("user not found");
    }
}

function chatGetMediaValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['user_id']) && empty($_POST['page_id']) && empty($_POST['group_id'])) {
        throw new Exception("user_id , page_id , group_id can not be empty");
    }

    if (!empty($_POST['user_id']) && !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }

    if (!empty($_POST['page_id']) && !is_numeric($_POST['page_id'])) {
        throw new Exception("page_id can not be empty");
    }

    if (!empty($_POST['group_id']) && !is_numeric($_POST['group_id'])) {
        throw new Exception("group_id can not be empty");
    }

    if (empty($_POST['media_type']) || !in_array($_POST['media_type'], ['images','videos','audio','links','docs'])) {
        throw new Exception("media_type can not be empty");
    }
}

function payValidation()
{
	global $sqlConnect, $wo,$db;
	if (!empty($_POST['pay_type']) && in_array($_POST['pay_type'], array(
            'pro',
            'fund'
        )))
	{
		if ($_POST['pay_type'] == 'pro') {
            
            if (!empty($_POST['pro_type']) && in_array($_POST['pro_type'], array_keys($wo["pro_packages"]))) {

                if ($wo["pro_packages"][$_POST['pro_type']]['price'] > $wo['user']['wallet']) {
                	throw new Exception("please top up your wallet");
                }

            } else {
            	throw new Exception("pro_type can not be empty");
            }

        } elseif ($_POST['pay_type'] == 'fund') {
            if (!empty($_POST['price']) && is_numeric($_POST['price']) && $_POST['price'] > 0) {

                if (!empty($_POST['fund_id']) && is_numeric($_POST['fund_id']) && $_POST['fund_id'] > 0) {

                    $fund_id = Wo_Secure($_POST['fund_id']);
                    $price   = Wo_Secure($_POST['price']);
                    $wo['fund']    = $db->where('id', $fund_id)->getOne(T_FUNDING);

                    if (empty($wo['fund'])) {
                    	throw new Exception("fund not found");
                    }

                } else {
                	throw new Exception("fund_id can not empty");
                }

            } else {
            	throw new Exception("price can not empty");
            }
        }

	}
	else {
		throw new Exception("pay_type can not be empty");
    }
}

function coinpaymentsCallbackValidation()
{
    if (!isset($_POST['user_id']) || empty($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }
    if (!isset($_POST['amount1']) || empty($_POST['amount1']) || !is_numeric($_POST['amount1'])) {
        throw new Exception("amount1 can not be empty");
    }
}

function yoomoneyCreateValidation()
{
    global $sqlConnect, $wo,$db;
    if (empty($_POST['amount']) || !is_numeric($_POST['amount'])) {
        throw new Exception("amount can not be empty");
    }
}

function yoomoneySuccessValidation()
{
    global $sqlConnect, $wo,$db;
    if (empty($_POST['notification_type']) || empty($_POST['operation_id']) || empty($_POST['amount']) || empty($_POST['currency']) || empty($_POST['datetime']) || empty($_POST['sender']) || empty($_POST['codepro']) || empty($_POST['label']) || empty($_POST['codepro']) || empty($_POST['sha1_hash'])) {
        throw new Exception("notification_type , operation_id , amount , currency , datetime , sender , label , codepro , sha1_hash can not be empty");
    }
    $hash = sha1($_POST['notification_type'].'&'.
    $_POST['operation_id'].'&'.
    $_POST['amount'].'&'.
    $_POST['currency'].'&'.
    $_POST['datetime'].'&'.
    $_POST['sender'].'&'.
    $_POST['codepro'].'&'.
    $wo['config']['yoomoney_notifications_secret'].'&'.
    $_POST['label']);

    $_POST['codepro'] = (is_string($_POST['codepro']) && strtolower($_POST['codepro']) == 'true' ? true : false);

    if ($_POST['sha1_hash'] != $hash || $_POST['codepro'] == true) {
        throw new Exception("hash not match or codepro = true");
    }

    $wo['user'] = $db->where('user_id',Wo_Secure($_POST['label']))->getOne(T_USERS);
    if (empty($wo['user'])) {
        throw new Exception("user not found");
    }
}

function authorizePayValidation()
{
    if (empty($_POST['card_number']) || empty($_POST['card_month']) || empty($_POST['card_year']) || empty($_POST['card_cvc']) || empty($_POST['amount']) || !is_numeric($_POST['amount'])) {
        throw new Exception("card_number , card_month , card_year , card_cvc , amount can not be empty");
    }
}

function fluttewavePayValidation()
{
    if (empty($_POST['email']) || empty($_POST['amount']) || !is_numeric($_POST['amount'])) {
        throw new Exception("email , amount can not be empty");
    }
}

function fluttewaveSuccessValidation()
{
    if (empty($_POST['status']) || empty($_POST['transaction_id'])) {
        throw new Exception("status , transaction_id can not be empty");
    }
    elseif ($_POST['status'] != 'successful') {
        throw new Exception("status not successful");
    }
}

function fortumoSuccessValidation()
{
    global $sqlConnect, $wo,$db;
    if (empty($_POST['amount']) || empty($_POST['status']) || empty($_POST['cuid']) || empty($_POST['price'])) {
        throw new Exception("amount , status , cuid , price can not be empty");
    }
    elseif ($_POST['status'] != 'completed') {
        throw new Exception("completed not successful");
    }

    $user_id = Wo_Secure($_POST['cuid']);
    $wo['user'] = $db->objectBuilder()->where('user_id',$user_id)->getOne(T_USERS);
    if (empty($wo['user'])) {
        throw new Exception("user not found");
    }
}

function aamarpayPayValidation()
{
    global $sqlConnect, $wo,$db;
    if (empty($_POST['amount']) || !is_numeric($_POST['amount']) || empty($_POST['name']) || empty($_POST['email']) || empty($_POST['phone'])) {
        throw new Exception("amount , name , email , phone can not be empty");
    }
}

function aamarpaySuccessValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['amount']) || empty($_POST['mer_txnid']) || empty($_POST['opt_a']) || empty($_POST['pay_status'])) {
        throw new Exception("amount , mer_txnid , opt_a , pay_status can not be empty");
    }
    elseif ($_POST['pay_status'] != 'Successful') {
        throw new Exception("pay_status not Successful");
    }

    $wo['user'] = $db->arrayBuilder()->where('user_id',Wo_Secure($_POST['opt_a']))->getOne(T_USERS);
    if (empty($wo['user'])) {
        throw new Exception("user not found");
    }
}

function addressAddValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['name']) || empty($_POST['phone']) || empty($_POST['country']) || empty($_POST['city']) || empty($_POST['zip']) || empty($_POST['address'])) {
        throw new Exception("name , phone , country , city , zip , address can not be empty");
    }
}

function addressEditValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['name']) || empty($_POST['phone']) || empty($_POST['country']) || empty($_POST['city']) || empty($_POST['zip']) || empty($_POST['address']) ||  empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("name , phone , country , city , zip , address , id can not be empty");
    }

    $wo['address'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_ADDRESS);
    $isOwner = false;
    if (!empty($wo['address']) && ($wo['address']->user_id == $wo['user']['user_id'] || IsAdmin())) {
        $isOwner = true;
    }
    if (empty($wo['address']) || !$isOwner) {
        throw new Exception("address not found");
    }
}

function addressDeleteValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
        throw new Exception("id can not be empty");
    }

    $wo['address'] = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_ADDRESS);
    $isOwner = false;
    if (!empty($wo['address']) && ($wo['address']->user_id == $wo['user']['user_id'] || IsAdmin())) {
        $isOwner = true;
    }
    if (empty($wo['address']) || !$isOwner) {
        throw new Exception("address not found");
    }
}

function marketAddCartValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
        throw new Exception("product_id can not be empty");
    }

    $is_added = $db->where('product_id', Wo_Secure($_POST['product_id']))->where('user_id',$wo['user']['user_id'])->getOne(T_USERCARD);
    if (!empty($is_added)) {
        throw new Exception("product already in cart");
    }
}

function marketChangeQtyValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['product_id']) || !is_numeric($_POST['product_id']) || empty($_POST['qty']) || !is_numeric($_POST['qty'])) {
        throw new Exception("product_id , qty can not be empty");
    }

    $wo['product'] = Wo_GetProduct(Wo_Secure($_POST['product_id']));

    if (empty($wo['product'])) {
        throw new Exception("product not found");
    }

    if ($wo['product']['units'] < $_POST['qty']) {
        throw new Exception("max qty is " . $wo['product']['units']);
    }
}

function marketRemoveCartValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
        throw new Exception("product_id can not be empty");
    }

    $is_added = $db->where('product_id', Wo_Secure($_POST['product_id']))->where('user_id',$wo['user']['user_id'])->getOne(T_USERCARD);
    if (empty($is_added)) {
        throw new Exception("product not found");
    }
}

function marketBuyValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['address_id']) || !is_numeric($_POST['address_id'])) {
        throw new Exception("address_id can not be empty");
    }

    $wo['address'] = $db->where('id',Wo_Secure($_POST['address_id']))->where('user_id',$wo['user']['user_id'])->getOne(T_USER_ADDRESS);
    if (empty($wo['address'])) {
        throw new Exception("address not found");
    }

    $wo['items'] = $db->where('user_id',$wo['user']['user_id'])->get(T_USERCARD);

    if (empty($wo['items'])) {
        throw new Exception("no items found");
    }

    $total = 0;
    $wo['insert'] = array();
    foreach ($wo['items'] as $key => $item) {
        $product = $wo['main_product'] = Wo_GetProduct($item->product_id);
        if (empty($product)) {
            throw new Exception("product not found");
        }
        if ($item->units <= $product['units']) {
            if (!empty($wo['currencies']) && !empty($wo['currencies'][$product['currency']]) && $wo['currencies'][$product['currency']]['text'] != $wo['config']['currency'] && !empty($wo['config']['exchange']) && !empty($wo['config']['exchange'][$wo['currencies'][$product['currency']]['text']])) {
                $total += (($product['price'] / $wo['config']['exchange'][$wo['currencies'][$product['currency']]['text']]) * $item->units);
            }
            else{
                $total += ($product['price'] * $item->units);
            }
            if (!in_array($product['user_id'], array_keys($wo['insert']))) {
                $f_price = $product['price'];
                if (!empty($wo['config']['exchange']) && !empty($wo['config']['exchange'][$wo['currencies'][$product['currency']]['text']])) {
                    $f_price = ($product['price'] / $wo['config']['exchange'][$wo['currencies'][$product['currency']]['text']]);
                }
                $wo['insert'][$product['user_id']] = array();
                $wo['insert'][$product['user_id']][] = array('product_id' => $product['id'],
                                                       'price' => $f_price,
                                                       'units' => $item->units);
            }
            else{
                $f_price = $product['price'];
                if (!empty($wo['config']['exchange']) && !empty($wo['config']['exchange'][$wo['currencies'][$product['currency']]['text']])) {
                    $f_price = ($product['price'] / $wo['config']['exchange'][$wo['currencies'][$product['currency']]['text']]);
                }
                $wo['insert'][$product['user_id']][] = array('product_id' => $product['id'],
                                                       'price' => $f_price,
                                                       'units' => $item->units);
            }
        }
        else{
            throw new Exception("max qty is " . $product['units']);
        }
    }

    if ($wo['user']['wallet'] < $total) {
        throw new Exception("please top up your wallet");
    }

    if (empty($wo['insert'])) {
        throw new Exception("cart is empty");
    }
}

function chatSearchValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['text'])) {
        throw new Exception("text can not be empty");
    }

    if (empty($_POST['user_id']) && empty($_POST['page_id']) && empty($_POST['group_id'])) {
        throw new Exception("user_id , page_id , group_id can not be empty");
    }

    if (!empty($_POST['user_id']) && !is_numeric($_POST['user_id'])) {
        throw new Exception("user_id can not be empty");
    }

    if (!empty($_POST['page_id']) && !is_numeric($_POST['page_id'])) {
        throw new Exception("page_id can not be empty");
    }

    if (!empty($_POST['group_id']) && !is_numeric($_POST['group_id'])) {
        throw new Exception("group_id can not be empty");
    }
}

function marketTrackingValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['tracking_url']) || empty($_POST['tracking_id']) || empty($_POST['order_hash'])) {
        throw new Exception("tracking_url , tracking_id , order_hash can not be empty");
    }
    elseif (!filter_var($_POST['tracking_url'], FILTER_VALIDATE_URL)) {
        throw new Exception("tracking_url not valid");
    }
    $wo['hash_id'] = Wo_Secure($_POST['order_hash']);
    $wo['tracking_url'] = Wo_Secure($_POST['tracking_url']);
    $wo['tracking_id'] = Wo_Secure($_POST['tracking_id']);
    $wo['order'] = $db->where('hash_id',$wo['hash_id'])->where('product_owner_id',$wo['user']['user_id'])->getOne(T_USER_ORDERS);
    if (empty($wo['order'])) {
        throw new Exception("order not found");
    }
}

function marketRefundValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['hash_order']) || empty($_POST['message'])) {
        throw new Exception("hash_order , message can not be empty");
    }

    $wo['hash_id'] = Wo_Secure($_POST['order_hash']);
    $wo['message'] = Wo_Secure($_POST['message']);

    $wo['order'] = $db->where('hash_id',$wo['hash_id'])->where('product_owner_id',$wo['user']['user_id'])->getOne(T_USER_ORDERS);
    if (empty($wo['order'])) {
        throw new Exception("order not found");
    }
}

function marketChangeStatusValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['hash_order']) || empty($_POST['status'])) {
        throw new Exception("hash_order , status can not be empty");
    }
    
    $wo['hash_id'] = Wo_Secure($_POST['order_hash']);

    $wo['order'] = $db->where('hash_id',$wo['hash_id'])->getOne(T_USER_ORDERS);
    if (empty($wo['order'])) {
        throw new Exception("order not found");
    }
}

function marketReviewValidation()
{
    global $sqlConnect, $wo,$db;

    if (empty($_POST['rating']) || !in_array($_POST['rating'], array(1,2,3,4,5)) || empty($_POST['review']) || empty($_POST['product_id'])) {
        throw new Exception("review , rating , product_id can not be empty");
    }
}