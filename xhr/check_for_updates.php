<?php 
if ($f == 'check_for_updates') {
    $false = false;
    if (!is_dir('themes/ramza')) {
        $false = true;
    }
    if (!is_dir('themes/wonderful') && $false == true) {
        $false = true;
    } else {
        $false = false;
    }
    if ($false == true) {
        $data['status']     = 400;
        $data['ERROR_NAME'] = 'It looks like you have renamed your themes, please rename them back to "ramza", "wonderful" to use the auto update system, otherwise please update your site manually.';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if (Wo_CheckMainSession($hash_id) === true || Wo_CheckSession($hash_id) === true) {
        $check = function_exists('Ramza_UpdateCheck') ? Ramza_UpdateCheck() : ['ok' => true, 'available' => false, 'releases' => []];
        $data['status']         = 200;
        $data['script_version'] = $wo['script_version'] ?? '1.0';
        $versions = [];
        if (!empty($check['available']) && !empty($check['releases'])) {
            foreach ($check['releases'] as $rel) {
                if (!empty($rel['version'])) {
                    $versions[] = $rel['version'];
                }
            }
        }
        $data['versions'] = $versions;
        if (empty($versions)) {
            $data['status'] = 300;
        }
    } else {
        $data['status'] = 400;
        $data['ERROR_NAME'] = 'Session expired. Please reload the page.';
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
