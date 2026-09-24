<?php 
if ($f == 'download_updates') {
    if (Wo_CheckMainSession($hash_id) === true || Wo_CheckSession($hash_id) === true) {
        $check = function_exists('Ramza_UpdateCheck') ? Ramza_UpdateCheck() : ['ok' => false];
        if (!empty($check['available']) && !empty($check['release'])) {
            $download = Ramza_UpdateDownload($check['release']);
            if (($download['ok'] ?? false) === true) {
                $apply = Ramza_UpdateApply((string) $download['path']);
                if (($apply['ok'] ?? false) === true) {
                    $data['status'] = 200;
                } else {
                    $data['status'] = 400;
                    $data['ERROR_NAME'] = $apply['message'] ?? 'Update installation failed.';
                }
            } else {
                $data['status'] = 400;
                $data['ERROR_NAME'] = $download['message'] ?? 'Download failed.';
            }
        } else {
            $data['status'] = 300;
            $data['ERROR_NAME'] = 'No update available.';
        }
    } else {
        $data['status'] = 400;
        $data['ERROR_NAME'] = 'Session expired. Please reload the page.';
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
