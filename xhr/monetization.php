<?php
if ($f == "monetization") {
    if ($s == 'add') {
        if (!empty($_POST['price']) && !empty($_POST['period']) && !empty($_POST['title']) && !empty($_POST['description']) && is_numeric($_POST['price'])) {
            try {
                $count = $db->where('user_id',$wo['user']['user_id'])->getValue(T_USER_MONETIZATION,'count(*)');
                if ($count > 3) {
                    throw new Exception($wo['lang']['you_can_create_just_plans'], 1);
                    
                }
                $day_period = 1;

                if(Wo_Secure($_POST['period']) == "weekly" ) {
                    $day_period = 7;
                } else if(Wo_Secure($_POST['period']) == "monthly") {
                    $day_period = 30;
                } else if(Wo_Secure($_POST['period']) == "yearly") {
                    $day_period = 365;
                }

                $id = $db->insert(T_USER_MONETIZATION, array(
                    'user_id' => $wo['user']['user_id'],
                    'title' => Wo_Secure($_POST['title']),
                    'price' => Wo_Secure($_POST['price']),
                    'currency' => Wo_Secure($_POST['currency']),
                    'paid_every' => $day_period,
                    'period' => Wo_Secure($_POST['period']),
                    'description' => Wo_Secure($_POST['description'])));
                
                $db->where('user_id',$wo['user']['user_id'])->update(T_USERS,['have_monetization' => 1]);
            } catch (Exception $e) {
                header("Content-type: application/json");
                echo json_encode([
                    'status' => 400,
                    'message' => $e->getMessage()
                ]);
                exit();
            }

            if (!empty($id)) {
                $data['status'] = 200;
                $data['url'] = $wo['config']['site_url'] . '/setting/' . $wo['user']['username'] . '/monetization';
                $data['message'] = $wo['lang']['monetization_added'];
            } else {
                $data['message'] = $error_icon . $wo['lang']['something_wrong'];
            }
        } else {
            $data['message'] = $error_icon . $wo['lang']['please_check_details'];
        }
    }
    if ($s == 'delete') {
        if (!empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $monetization = $db->where('id', Wo_Secure($_POST['id']))->getOne(T_USER_MONETIZATION);
            if (!empty($monetization) && ($monetization->user_id == $wo['user']['user_id'] || Wo_IsAdmin())) {
                $db->where('monetization_id', $monetization->id)->delete(T_MONETIZATION_SUBSCRIBTION);
                $db->where('id', $monetization->id)->delete(T_USER_MONETIZATION);

                $f_monetization = $db->where('user_id',$monetization->user_id)->getOne(T_USER_MONETIZATION);
                if (empty($f_monetization)) {
                    $db->where('user_id',$monetization->user_id)->update(T_USERS,['have_monetization' => 0]);
                }

                $data['status'] = 200;
            } else {
                $data['message'] = $error_icon . $wo['lang']['please_check_details'];
            }
        } else {
            $data['message'] = $error_icon . $wo['lang']['please_check_details'];
        }
    }
    if ($s == 'edit') {
        if (!empty($_POST['price'])  && !empty($_POST['period']) && !empty($_POST['title']) && !empty($_POST['description']) && is_numeric($_POST['price'])) {
            $monetization = $db->where('id', Wo_Secure($_POST['id']))->getOne(T_USER_MONETIZATION);
            if (!empty($monetization) && ($monetization->user_id == $wo['user']['user_id'] || IsAdmin())) {
                $day_period = 1;

                if(Wo_Secure($_POST['period']) == "weekly" ) {
                    $day_period = 7;
                } else if(Wo_Secure($_POST['period']) == "monthly") {
                    $day_period = 30;
                } else if(Wo_Secure($_POST['period']) == "yearly") {
                    $day_period = 365;
                }
                $db->where('id', $monetization->id)->update(T_USER_MONETIZATION, array(
                    'user_id' => $wo['user']['user_id'],
                    'title' => Wo_Secure($_POST['title']),
                    'price' => Wo_Secure($_POST['price']),
                    'currency' => Wo_Secure($_POST['currency']),
                    'paid_every' => $day_period,
                    'period' => Wo_Secure($_POST['period']),
                    'description' => Wo_Secure($_POST['description'])));
                $data['status'] = 200;
                $data['url'] = $wo['config']['site_url'] . '/setting/' . $wo['user']['username'] . '/monetization';
                $data['message'] = $wo['lang']['monetization_edited'];
            } else {
                $data['message'] = $error_icon . $wo['lang']['please_check_details'];
            }
        } else {
            $data['message'] = $error_icon . $wo['lang']['please_check_details'];
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}