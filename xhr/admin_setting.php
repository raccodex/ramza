<?php
use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
if ($f == 'admin_setting' AND (Wo_IsAdmin() || Wo_IsModerator())) {
    if ($s === 'fetch_mobile_license_public_key') {
        header('Content-Type: application/json; charset=UTF-8');
        if (!Wo_IsAdmin() || Wo_CheckSession($hash_id) !== true) {
            echo json_encode(array('status' => 403, 'message' => 'Administrator verification failed.'));
            exit();
        }
        if (function_exists('Ramza_IsDemoMode') && Ramza_IsDemoMode()) {
            echo json_encode(array('status' => 403, 'message' => 'Mobile settings are locked in demo mode.'));
            exit();
        }
        if (!function_exists('Ramza_MobileTablesReady') || !Ramza_MobileTablesReady()) {
            echo json_encode(array('status' => 503, 'message' => 'Apply updates/ramza_mobile_api_v1.sql first.'));
            exit();
        }
        try {
            $publicKey = function_exists('Ramza_MobileDiscoverLicensePublicKey')
                ? Ramza_MobileDiscoverLicensePublicKey()
                : '';
            if ($publicKey === '') {
                echo json_encode(array(
                    'status' => 503,
                    'message' => 'The license server has not published its mobile signing key yet.',
                ));
                exit();
            }
            echo json_encode(array(
                'status' => 200,
                'message' => 'License public key fetched.',
                'public_key' => $publicKey,
            ));
        } catch (Throwable $error) {
            error_log('Ramza mobile public-key fetch failed: ' . preg_replace('/[\r\n]+/', ' ', $error->getMessage()));
            echo json_encode(array(
                'status' => 503,
                'message' => 'The license public key could not be fetched.',
            ));
        }
        exit();
    }

    if ($s === 'update_mobile_app_settings') {
        header('Content-Type: application/json; charset=UTF-8');
        if (!Wo_IsAdmin() || Wo_CheckSession($hash_id) !== true) {
            echo json_encode(array('status' => 403, 'message' => 'Administrator verification failed.'));
            exit();
        }
        if (function_exists('Ramza_IsDemoMode') && Ramza_IsDemoMode()) {
            echo json_encode(array('status' => 403, 'message' => 'Mobile settings are locked in demo mode.'));
            exit();
        }
        if (!function_exists('Ramza_MobileTablesReady') || !Ramza_MobileTablesReady()) {
            echo json_encode(array('status' => 503, 'message' => 'Apply updates/ramza_mobile_api_v1.sql first.'));
            exit();
        }

        $mobileText = static function (string $key, int $maximum = 255): string {
            $value = trim((string) ($_POST[$key] ?? ''));
            if (mb_strlen($value) > $maximum) {
                throw new InvalidArgumentException('A mobile setting is too long: ' . $key);
            }
            return $value;
        };
        $mobileBool = static function (string $key): string {
            return !empty($_POST[$key]) && (string) $_POST[$key] === '1' ? '1' : '0';
        };
        $mobileUrl = static function (string $key) use ($mobileText): string {
            $value = $mobileText($key, 1000);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Enter a valid URL for ' . $key . '.');
            }
            return $value;
        };

        try {
            $androidPackage = $mobileText('android_package', 190);
            $iosBundle = $mobileText('ios_bundle_id', 190);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $androidPackage) !== 1) {
                throw new InvalidArgumentException('Enter a valid Android package name.');
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*(\.[A-Za-z0-9][A-Za-z0-9-]*)+$/', $iosBundle) !== 1) {
                throw new InvalidArgumentException('Enter a valid iOS bundle ID.');
            }
            foreach (array('minimum_android_version', 'minimum_ios_version', 'latest_android_version', 'latest_ios_version') as $versionKey) {
                $version = $mobileText($versionKey, 32);
                if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
                    throw new InvalidArgumentException('Use semantic versions such as 1.0.0.');
                }
            }
            foreach (array('primary_color', 'secondary_color') as $colorKey) {
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $mobileText($colorKey, 7)) !== 1) {
                    throw new InvalidArgumentException('Use six-digit hexadecimal colors.');
                }
            }

            $plainSettings = array(
                'mobile_enabled' => $mobileBool('mobile_enabled'),
                'app_name' => $mobileText('app_name', 80),
                'codecanyon_username' => $mobileText('codecanyon_username', 100),
                'android_package' => $androidPackage,
                'ios_bundle_id' => $iosBundle,
                'primary_color' => strtolower($mobileText('primary_color', 7)),
                'secondary_color' => strtolower($mobileText('secondary_color', 7)),
                'minimum_android_version' => $mobileText('minimum_android_version', 32),
                'minimum_ios_version' => $mobileText('minimum_ios_version', 32),
                'latest_android_version' => $mobileText('latest_android_version', 32),
                'latest_ios_version' => $mobileText('latest_ios_version', 32),
                'force_update' => $mobileBool('force_update'),
                'maintenance_mode' => $mobileBool('maintenance_mode'),
                'maintenance_message' => $mobileText('maintenance_message', 240),
                'android_store_url' => $mobileUrl('android_store_url'),
                'ios_store_url' => $mobileUrl('ios_store_url'),
                'terms_url' => $mobileUrl('terms_url'),
                'privacy_url' => $mobileUrl('privacy_url'),
            );
            foreach ($plainSettings as $settingKey => $settingValue) {
                if (!Ramza_SaveMobileSetting($settingKey, $settingValue, false)) {
                    throw new RuntimeException('A mobile setting could not be saved.');
                }
            }
            $licensePublicKey = $mobileText('license_public_key', 128);
            if ($licensePublicKey !== '') {
                $decodedPublicKey = Ramza_MobileDecodeBase64Url($licensePublicKey);
                if (strlen($decodedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    throw new InvalidArgumentException('Enter a valid Ed25519 license public key.');
                }
                if (!Ramza_SaveMobileSetting('license_public_key', $licensePublicKey, false)) {
                    throw new RuntimeException('The license public key could not be saved.');
                }
            }

            foreach (array('main_purchase_code', 'mobile_addon_purchase_code') as $secretKey) {
                $secret = $mobileText($secretKey, 190);
                if ($secret !== '' && !Ramza_SaveMobileSetting($secretKey, $secret, true)) {
                    throw new RuntimeException('A protected license value could not be saved.');
                }
            }

            $installationId = Ramza_MobileSetting('license_installation_id');
            if ($installationId === '') {
                $installationId = 'rmi_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                Ramza_SaveMobileSetting('license_installation_id', $installationId, false);
            }
            $publicClientId = Ramza_MobileSetting('public_client_id');
            if ($publicClientId === '') {
                $publicClientId = 'rmp_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                Ramza_SaveMobileSetting('public_client_id', $publicClientId, false);
            }
            $enabled = (int) $plainSettings['mobile_enabled'];
            $now = time();
            $clientStatement = mysqli_prepare(
                $sqlConnect,
                'INSERT INTO `Ramza_MobileApiClients`
                 (`public_client_id`,`display_name`,`android_package`,`ios_bundle_id`,`enabled`,`created_at`,`updated_at`)
                 VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`),
                 `android_package` = VALUES(`android_package`), `ios_bundle_id` = VALUES(`ios_bundle_id`),
                 `enabled` = VALUES(`enabled`), `updated_at` = VALUES(`updated_at`)'
            );
            mysqli_stmt_bind_param(
                $clientStatement,
                'ssssiii',
                $publicClientId,
                $plainSettings['app_name'],
                $androidPackage,
                $iosBundle,
                $enabled,
                $now,
                $now
            );
            mysqli_stmt_execute($clientStatement);
            mysqli_stmt_close($clientStatement);

            $licenseStatus = 'unconfigured';
            if (!empty($_POST['verify_license']) && (string) $_POST['verify_license'] === '1') {
                $mainCode = Ramza_MobileSetting('main_purchase_code', '', true);
                $addonCode = Ramza_MobileSetting('mobile_addon_purchase_code', '', true);
                if ($mainCode === '' || $addonCode === '' || $plainSettings['codecanyon_username'] === '') {
                    throw new InvalidArgumentException('Save both purchase codes and the CodeCanyon username before verification.');
                }
                try {
                    Ramza_MobileLicensePublicKey();
                } catch (Throwable) {
                    throw new RuntimeException('The mobile license public key is unavailable. Update the license service or paste the public key.');
                }
                $activation = Ramza_MobileActivateLicense(array(
                    'site_url' => rtrim((string) $wo['config']['site_url'], '/'),
                    'main_purchase_code' => $mainCode,
                    'mobile_addon_purchase_code' => $addonCode,
                    'codecanyon_username' => $plainSettings['codecanyon_username'],
                    'android_package_name' => $androidPackage,
                    'ios_bundle_id' => $iosBundle,
                    'installation_id' => $installationId,
                    'public_client_id' => $publicClientId,
                ));
                if (empty($activation['success'])) {
                    $errorCode = (string) ($activation['code'] ?? 'LICENSE_INVALID');
                    throw new RuntimeException('License verification failed: ' . $errorCode);
                }
                $payload = $activation['payload'];
                $licenseStatus = in_array((string) ($payload['status'] ?? ''), array('active', 'grace'), true)
                    ? (string) $payload['status']
                    : 'invalid';
                if ($licenseStatus === 'invalid') {
                    throw new RuntimeException('License verification returned an invalid status.');
                }
                if (!Ramza_MobilePersistLicense(
                    $payload,
                    (string) $activation['signature'],
                    (string) ($activation['activation_token'] ?? '')
                )) {
                    throw new RuntimeException('The verified mobile license could not be stored securely.');
                }
            } else {
                $licenseResult = mysqli_query($sqlConnect, 'SELECT `status` FROM `Ramza_MobileLicenseCache` WHERE `id` = 1 LIMIT 1');
                $licenseRow = $licenseResult ? mysqli_fetch_assoc($licenseResult) : null;
                $licenseStatus = is_array($licenseRow) ? (string) $licenseRow['status'] : 'unconfigured';
            }

            echo json_encode(array(
                'status' => 200,
                'message' => !empty($_POST['verify_license']) ? 'Mobile settings saved and license verified.' : 'Mobile settings saved.',
                'client_id' => $publicClientId,
                'license_status' => $licenseStatus,
            ));
        } catch (Throwable $error) {
            echo json_encode(array(
                'status' => 400,
                'message' => preg_replace('/[\r\n]+/', ' ', $error->getMessage()),
            ));
        }
        exit();
    }

    if ($s == 'search_in_pages') {
        $keyword           = Wo_Secure($_POST['keyword']);
        $html              = '';

        $cleanKeyword = trim(strip_tags(html_entity_decode($keyword, ENT_QUOTES, 'UTF-8')));
        $keywordLower = strtolower($cleanKeyword);
        $seenLinks = array();
        $not_allowed_files = array(
            'edit-custom-page',
            'edit-lang',
            'edit-movie',
            'edit-profile-field',
            'edit-terms-pages',
            'manage-permissions'
        );
        $resultCount = 0;
        $resultLimit = 12;
        $renderSearchItem = function ($link, $title, $pageTitle = '') use (&$html, &$seenLinks, &$resultCount, $resultLimit, $cleanKeyword) {
            if ($resultCount >= $resultLimit) {
                return;
            }
            $link = trim((string) $link);
            $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $title), ENT_QUOTES, 'UTF-8')));
            $pageTitle = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $pageTitle), ENT_QUOTES, 'UTF-8')));
            if ($link === '' || $title === '') {
                return;
            }
            $key = $link . '|' . strtolower($title);
            if (!empty($seenLinks[$key])) {
                return;
            }
            $seenLinks[$key] = true;
            $resultCount++;
            if ($pageTitle === '') {
                $pageTitle = ucwords(str_replace(array('-', '_'), ' ', $link));
            }
            $href = Wo_LoadAdminLinkSettings($link) . '?highlight=' . urlencode($cleanKeyword);
            $html .= '<a class="rac-admin-search-result" role="option" aria-selected="false" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"><span class="rac-admin-search-page">' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '</span><span class="rac-admin-search-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span></a>';
        };

        if ($keywordLower !== '') {
            $files = scandir('./admin-panel/pages');
            foreach ($files as $file) {
                if (file_exists('./admin-panel/pages/' . $file . '/content.phtml') && !in_array($file, $not_allowed_files)) {
                    $pageTitle = ucwords(str_replace(array('-', '_'), ' ', $file));
                    if (strpos(strtolower($file), $keywordLower) !== false || strpos(strtolower($pageTitle), $keywordLower) !== false) {
                        $renderSearchItem($file, $pageTitle, 'Admin page');
                    }
                }
            }
        }

        if (file_exists('./admin-panel/search-result.php')) {
            include_once './admin-panel/search-result.php';

            $foundItems = [];
            foreach ($pages_search as $item) {
                $searchText = strtolower(($item['title'] ?? '') . ' ' . ($item['page_title'] ?? '') . ' ' . ($item['link'] ?? ''));
                if ($keywordLower !== '' && strpos($searchText, $keywordLower) !== false) {
                    $foundItems[] = $item;
                }
            }

            if (!empty($foundItems)) {
                foreach ($foundItems as $key => $item) {
                    $renderSearchItem($item['link'], $item['title'], $item['page_title']);
                }
            }
        }
        else{
            $files             = scandir('./admin-panel/pages');
            foreach ($files as $key => $file) {
                if (file_exists('./admin-panel/pages/' . $file . '/content.phtml') && !in_array($file, $not_allowed_files)) {
                    $string = file_get_contents('./admin-panel/pages/' . $file . '/content.phtml');
                    preg_match_all("@(?s)<h2([^<]*)>([^<]*)<\/h2>@", $string, $matches1);
                    if (!empty($matches1) && !empty($matches1[2])) {
                        foreach ($matches1[2] as $key => $title) {
                            if (strpos(strtolower($title), strtolower($keyword)) !== false) {
                                $page_title = '';
                                preg_match_all("@(?s)<h2([^<]*)>([^<]*)<\/h2>@", $string, $matches3);
                                if (!empty($matches3) && !empty($matches3[2])) {
                                    foreach ($matches3[2] as $key => $title2) {
                                        $page_title = $title2;
                                        break;
                                    }
                                }
                                $renderSearchItem($file, $title, $page_title);
                                break;
                            }
                        }
                    }
                    preg_match_all("@(?s)<label([^<]*)>([^<]*)<\/label>@", $string, $matches2);
                    if (!empty($matches2) && !empty($matches2[2])) {
                        foreach ($matches2[2] as $key => $lable) {
                            if (strpos(strtolower($lable), strtolower($keyword)) !== false) {
                                $page_title = '';
                                preg_match_all("@(?s)<h2([^<]*)>([^<]*)<\/h2>@", $string, $matches3);
                                if (!empty($matches3) && !empty($matches3[2])) {
                                    foreach ($matches3[2] as $key => $title2) {
                                        $page_title = $title2;
                                        break;
                                    }
                                }
                                $renderSearchItem($file, $lable, $page_title);
                                break;
                            }
                        }
                    }
                }
            }
        }
            




        
        $data = array(
            'status' => 200,
            'html' => $html
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_country_ad') {
        $data['status'] = 400;
        if (!empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $data['status'] = 200;
            $data['data'] = $wo['countries_ads'][$_POST['id']];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_supported_coins') {
        $result = coinpayments_api_call(array('key' => $wo['config']['coinpayments_public_key'],
                                              'version' => '1',
                                              'format' => 'json',
                                              'cmd' => 'rates',
                                              'accepted' => '1'));
        $coins = array();
        if (!empty($result) && $result['status'] == 200) {
            foreach ($result['data'] as $key => $value) {
                if ($value['accepted'] == 1 && $value['is_fiat'] == 0) {
                    $coins[$key] = $key;
                }
            }
            $db->where('name', 'coinpayments_coins')->update(T_CONFIG, array('value' => json_encode($coins)));
            header("Content-type: application/json");
            echo json_encode(array('status' => 200));
            exit();
        }
        else{
            header("Content-type: application/json");
            echo json_encode(array('status' => 400,
                                   'message' => $result['message']));
            exit();
        }
    }
    if ($s == 'activate-product') {
        if (!empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $id      = Wo_Secure($_POST['id']);
            $product = Wo_GetProduct($id);
            if (empty($product)) {
                $data['message'] = 'Please check the details';
            }
            if (!empty($product)) {
                $db->where('id', $id)->update(T_PRODUCTS, array(
                    'active' => '1'
                ));
                $db->where('product_id', $id)->update(T_POSTS, array(
                    'active' => 1
                ));
                $notification_data_array = array(
                    'recipient_id' => $product['user_id'],
                    'type' => 'admin_notification',
                    'url' => 'index.php?link1=my-products',
                    'text' => $wo['lang']['product_approved'],
                    'type2' => 'approve_product'
                );
                Wo_RegisterNotification($notification_data_array);
            }
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete-review') {
        if (!empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $id = Wo_Secure($_POST['id']);
            $db->where('id', $id)->delete(T_PRODUCT_REVIEW);
            $images = $db->where('review_id', $id)->get(T_MEDIA);
            if (!empty($images)) {
                foreach ($images as $key => $value) {
                    @unlink($value->image);
                    @Wo_DeleteFromToS3($value->image);
                }
            }
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_multi_review') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $id) {
                if (!empty($id) && is_numeric($id)) {
                    $db->where('id', $id)->delete(T_PRODUCT_REVIEW);
                    $images = $db->where('review_id', $id)->get(T_MEDIA);
                    if (!empty($images)) {
                        foreach ($images as $key => $value) {
                            @unlink($value->image);
                            @Wo_DeleteFromToS3($value->image);
                        }
                    }
                }
            }
        }
        $data['status'] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete-order') {
        if (!empty($_POST['id'])) {
            $id    = Wo_Secure($_POST['id']);
            $order = $db->where('id', $id)->getOne(T_USER_ORDERS);
            if (!empty($order)) {
                $db->where('hash_id', $order->hash_id)->delete(T_USER_ORDERS);
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'change_status') {
        if (!empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0 && !empty($_POST['status']) && in_array($_POST['status'], array(
            'placed',
            'accepted',
            'packed',
            'shipped',
            'delivered',
            'canceled'
        ))) {
            $id     = Wo_Secure($_POST['id']);
            $status = Wo_Secure($_POST['status']);
            $db->where('id', $id)->update(T_USER_ORDERS, array(
                'status' => $status
            ));
            $order                   = $db->where('id', $id)->getOne(T_USER_ORDERS);
            $notification_data_array = array(
                'recipient_id' => $order->user_id,
                'type' => 'admin_notification',
                'type2' => 'admin_status_changed',
                'url' => 'index.php?link1=customer_order&id=' . $order->hash_id,
                'time' => time()
            );
            $db->insert(T_NOTIFICATION, $notification_data_array);
            $notification_data_array = array(
                'recipient_id' => $order->product_owner_id,
                'type' => 'admin_notification',
                'type2' => 'admin_status_changed',
                'url' => 'index.php?link1=order&id=' . $order->hash_id,
                'time' => time()
            );
            $db->insert(T_NOTIFICATION, $notification_data_array);
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'order_multi_status') {
        if (!empty($_POST['ids']) && !empty($_POST['action_type']) && in_array($_POST['action_type'], array(
            'placed',
            'accepted',
            'packed',
            'shipped',
            'delivered',
            'canceled',
            'delete'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value)) {
                    $id     = Wo_Secure($value);
                    $status = Wo_Secure($_POST['action_type']);
                    if ($_POST['action_type'] == 'delete') {
                        $order = $db->where('id', $id)->getOne(T_USER_ORDERS);
                        if (!empty($order)) {
                            $db->where('hash_id', $order->hash_id)->delete(T_USER_ORDERS);
                        }
                    } else {
                        $db->where('id', $id)->update(T_USER_ORDERS, array(
                            'status' => $status
                        ));
                        $order                   = $db->where('id', $id)->getOne(T_USER_ORDERS);
                        $notification_data_array = array(
                            'recipient_id' => $order->user_id,
                            'type' => 'admin_notification',
                            'type2' => 'admin_status_changed',
                            'url' => 'index.php?link1=customer_order&id=' . $order->hash_id,
                            'time' => time()
                        );
                        $db->insert(T_NOTIFICATION, $notification_data_array);
                        $notification_data_array = array(
                            'recipient_id' => $order->product_owner_id,
                            'type' => 'admin_notification',
                            'type2' => 'admin_status_changed',
                            'url' => 'index.php?link1=order&id=' . $order->hash_id,
                            'time' => time()
                        );
                        $db->insert(T_NOTIFICATION, $notification_data_array);
                        $data['status'] = 200;
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'delete_color') {
        if (!empty($_POST['id'])) {
            $id    = Wo_Secure($_POST['id']);
            $color = $db->where('id', $id)->getOne(T_COLORS);
            if (!empty($color)) {
                $db->where('id', $id)->delete(T_COLORS);
                $photo_file = $color->image;
                if (file_exists($photo_file)) {
                    @unlink(trim($photo_file));
                } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                    @Wo_DeleteFromToS3($photo_file);
                }
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_image_post') {
        if (!empty($_POST['image_color']) && !empty($_FILES['image'])) {
            $fileInfo = array(
                'file' => $_FILES["image"]["tmp_name"],
                'name' => $_FILES['image']['name'],
                'size' => $_FILES["image"]["size"],
                'type' => $_FILES["image"]["type"],
                'types' => 'jpeg,jpg,png,bmp,gif',
                'compress' => false
            );
            $media    = Wo_ShareFile($fileInfo);
            if (!empty($media['filename'])) {
                $db->insert(T_COLORS, array(
                    'text_color' => Wo_Secure($_POST['image_color']),
                    'image' => $media['filename'],
                    'time' => time()
                ));
            }
            $data = array(
                'status' => 200
            );
        } else {
            if (!empty($_FILES["image"]["error"]) || !empty($_FILES["image"]["error"])) {
                $error = $error_icon . 'The file is too big, please increase your server upload limit in php.ini';
            } else {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
            $data = array(
                'status' => 400,
                'error' => $error
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_color') {
        if (!empty($_POST['color_1']) && !empty($_POST['color_2']) && !empty($_POST['color_text'])) {
            $db->insert(T_COLORS, array(
                'color_1' => Wo_Secure($_POST['color_1']),
                'color_2' => Wo_Secure($_POST['color_2']),
                'text_color' => Wo_Secure($_POST['color_text']),
                'time' => time()
            ));
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'remove_provider') {
        if (!empty($_POST['provider'])) {
            if (in_array($_POST['provider'], $wo['config']['providers_array'])) {
                foreach ($wo['config']['providers_array'] as $key => $provider) {
                    if ($provider == $_POST['provider']) {
                        unset($wo['config']['providers_array'][$key]);
                    }
                }
                $saveSetting = Wo_SaveConfig('providers_array', json_encode($wo['config']['providers_array']));
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_provider') {
        if (!empty($_POST['provider'])) {
            $wo['config']['providers_array'][] = Wo_Secure($_POST['provider']);
            $saveSetting                       = Wo_SaveConfig('providers_array', json_encode($wo['config']['providers_array']));
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_lang_status') {
        $saveSetting = Wo_SaveConfig($_POST['name'], $_POST['value']);
        $data        = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'select_currency') {
        if (!empty($_POST['currency']) && in_array($_POST['currency'], $wo['config']['currency_array'])) {
            $currency    = Wo_Secure($_POST['currency']);
            $saveSetting = Wo_SaveConfig('currency', $currency);
            $saveSetting = Wo_SaveConfig('ads_currency', $currency);
            if (in_array($_POST['currency'], $wo['stripe_currency'])) {
                $saveSetting = Wo_SaveConfig('stripe_currency', $currency);
            }
            else{
                $saveSetting = Wo_SaveConfig('stripe_currency', 'USD');
            }

            if (in_array($_POST['currency'], $wo['paypal_currency'])) {
                $saveSetting = Wo_SaveConfig('paypal_currency', $currency);
            }
            else{
                $saveSetting = Wo_SaveConfig('paypal_currency', 'USD');
            }
            
            if (in_array($_POST['currency'], $wo['2checkout_currency'])) {
                $saveSetting = Wo_SaveConfig('2checkout_currency', $currency);
            }
            else{
                $saveSetting = Wo_SaveConfig('2checkout_currency', 'USD');
            }
            $request                                                              = fetchDataFromURL("https://v6.exchangerate-api.com/v6/".$wo['config']['exchangerate_key']."/latest/".$currency);
            $exchange                                                             = json_decode($request, true);
            if (!empty($exchange) && $exchange['result'] == 'success' && !empty($exchange['conversion_rates'])) {

                Wo_SaveConfig('exchange', json_encode($exchange['conversion_rates']));
                Wo_SaveConfig('exchange_update', (time() + (60 * 60 * 12)));
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_curreny') {
        if (!empty($_POST['currency']) && !empty($_POST['currency_symbol'])) {
            $wo['config']['currency_array'][]                                     = Wo_Secure($_POST['currency']);
            $wo['config']['currency_symbol_array'][Wo_Secure($_POST['currency'])] = Wo_Secure($_POST['currency_symbol']);
            $saveSetting                                                          = Wo_SaveConfig('currency_array', json_encode($wo['config']['currency_array']));
            $saveSetting                                                          = Wo_SaveConfig('currency_symbol_array', json_encode($wo['config']['currency_symbol_array']));
            $request                                                              = fetchDataFromURL("https://v6.exchangerate-api.com/v6/".$wo['config']['exchangerate_key']."/latest/".$wo['config']['currency']);
            $exchange                                                             = json_decode($request, true);
            if (!empty($exchange) && $exchange['result'] == 'success' && !empty($exchange['conversion_rates'])) {
                Wo_SaveConfig('exchange', json_encode($exchange['conversion_rates']));
                Wo_SaveConfig('exchange_update', (time() + (60 * 60 * 12)));
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'edit_curreny') {
        if (!empty($_POST['currency']) && !empty($_POST['currency_symbol']) && in_array($_POST['currency_id'], array_keys($wo['config']['currency_array']))) {
            $wo['config']['currency_array'][$_POST['currency_id']]                = Wo_Secure($_POST['currency']);
            $wo['config']['currency_symbol_array'][Wo_Secure($_POST['currency'])] = Wo_Secure($_POST['currency_symbol']);
            $saveSetting                                                          = Wo_SaveConfig('currency_array', json_encode($wo['config']['currency_array']));
            $saveSetting                                                          = Wo_SaveConfig('currency_symbol_array', json_encode($wo['config']['currency_symbol_array']));
            $request                                                              = fetchDataFromURL("https://v6.exchangerate-api.com/v6/".$wo['config']['exchangerate_key']."/latest/".$wo['config']['currency']);
            $exchange                                                             = json_decode($request, true);
            if (!empty($exchange) && $exchange['result'] == 'success' && !empty($exchange['conversion_rates'])) {
                Wo_SaveConfig('exchange', json_encode($exchange['conversion_rates']));
                Wo_SaveConfig('exchange_update', (time() + (60 * 60 * 12)));
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'remove__curreny') {
        if (!empty($_POST['currency'])) {
            if (in_array($_POST['currency'], $wo['config']['currency_array'])) {
                foreach ($wo['config']['currency_array'] as $key => $currency) {
                    if ($currency == $_POST['currency']) {
                        if (in_array($currency, array_keys($wo['config']['currency_symbol_array']))) {
                            unset($wo['config']['currency_symbol_array'][$currency]);
                        }
                        unset($wo['config']['currency_array'][$key]);
                    }
                }
                if ($wo['config']['currency'] == $_POST['currency']) {
                    if (!empty($wo['config']['currency_array'])) {
                        $saveSetting = Wo_SaveConfig('currency', reset($wo['config']['currency_array']));
                        $saveSetting = Wo_SaveConfig('ads_currency', reset($wo['config']['currency_array']));
                    }
                }
                $saveSetting = Wo_SaveConfig('currency_array', json_encode($wo['config']['currency_array']));
                $saveSetting = Wo_SaveConfig('currency_symbol_array', json_encode($wo['config']['currency_symbol_array']));
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'approve_receipt') {
        if (!empty($_GET['receipt_id'])) {
            $id      = Wo_Secure($_GET['receipt_id']);
            $receipt = $db->where('id', $id)->getOne('bank_receipts', array(
                '*'
            ));
            if ($receipt) {
                $updated = $db->where('id', $id)->update('bank_receipts', array(
                    'approved' => 1,
                    'approved_at' => time()
                ));
                $updated = true;
                if ($updated === true) {
                    if ($receipt->mode == 'wallet') {
                        $amount = $receipt->price;
                        $result = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` + " . $amount . " WHERE `user_id` = '" . $receipt->user_id . "'");
                        if ($result) {
                            cache($receipt->user_id, 'users', 'delete');
                            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $receipt->user_id . "', 'WALLET', '" . $amount . "', 'bank receipts')");
                        }
                        $notification_data_array = array(
                            'recipient_id' => $receipt->user_id,
                            'type' => 'admin_notification',
                            'url' => 'index.php?link1=wallet',
                            'text' => $wo['lang']['bank_pro'],
                            'type2' => 'no_name'
                        );
                        Wo_RegisterNotification($notification_data_array);
                    } elseif ($receipt->mode == 'donate') {
                        $fund = $db->where('id', $receipt->fund_id)->getOne(T_FUNDING);
                        if (!empty($fund)) {
                            $amount             = $receipt->price;
                            $fund_id            = $receipt->fund_id;
                            //$notes              = "Doanted to " . mb_substr($fund->title, 0, 100, "UTF-8");
                            //$notes = str_replace('{text}', mb_substr($fund->title, 0, 100, "UTF-8"), $wo['lang']['trans_doanted_to']);
                            $notes = mb_substr($fund->title, 0, 100, "UTF-8");
                            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$receipt->user_id}, 'DONATE', {$amount}, '{$notes}')");
                            $admin_com          = 0;
                            if (!empty($wo['config']['donate_percentage']) && is_numeric($wo['config']['donate_percentage']) && $wo['config']['donate_percentage'] > 0) {
                                $admin_com = ($wo['config']['donate_percentage'] * $amount) / 100;
                                $amount    = $amount - $admin_com;
                            }
                            $user_data = Wo_UserData($fund->user_id);
                            $db->where('user_id', $fund->user_id)->update(T_USERS, array(
                                'balance' => $user_data['balance'] + $amount
                            ));
                            cache($fund->user_id, 'users', 'delete');
                            $fund_raise_id           = $db->insert(T_FUNDING_RAISE, array(
                                'user_id' => $receipt->user_id,
                                'funding_id' => $fund_id,
                                'amount' => $amount,
                                'time' => time()
                            ));
                            $post_data               = array(
                                'user_id' => $receipt->user_id,
                                'fund_raise_id' => $fund_raise_id,
                                'time' => time(),
                                'multi_image_post' => 0
                            );
                            $id                      = Wo_RegisterPost($post_data);
                            $notification_data_array = array(
                                'notifier_id' => $receipt->user_id,
                                'recipient_id' => $fund->user_id,
                                'type' => 'fund_donate',
                                'url' => 'index.php?link1=show_fund&id=' . $fund->hashed_id
                            );
                            Wo_RegisterNotification($notification_data_array);
                            $notification_data_array = array(
                                'recipient_id' => $receipt->user_id,
                                'type' => 'admin_notification',
                                'url' => 'index.php?link1=show_fund&id=' . $fund->hashed_id,
                                'text' => $wo['lang']['bank_pro'],
                                'type2' => 'no_name'
                            );
                            Wo_RegisterNotification($notification_data_array);
                        }
                    } else {
                        $pro_type     = $receipt->mode;
                        $update_array = array(
                            'is_pro' => 1,
                            'pro_time' => time(),
                            'pro_' => 1,
                            'pro_type' => $pro_type
                        );
                        if (in_array($pro_type, array_keys($wo['pro_packages'])) && $wo['pro_packages'][$pro_type]['verified_badge'] == 1) {
                            $update_array['verified'] = 1;
                        }
                        $mysqli    = Wo_UpdateUserData($receipt->user_id, $update_array);
                        $user_data = Wo_UserData($receipt->user_id);
                        if (!empty($user_data['ref_user_id']) && $wo['config']['affiliate_type'] == 1 && $user_data['referrer'] == 0) {
                            $amount1     = $receipt->price;
                            $ref_user_id = $user_data['ref_user_id'];
                            if ($wo['config']['amount_percent_ref'] > 0) {
                                if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                                    $update_user    = Wo_UpdateUserData($user_data['user_id'], array(
                                        'referrer' => $ref_user_id,
                                        'src' => 'Referrer'
                                    ));
                                    $ref_amount     = ($wo['config']['amount_percent_ref'] * $amount1) / 100;
                                    $update_balance = Wo_UpdateBalance($ref_user_id, $ref_amount);
                                    unset($_SESSION['ref']);
                                }
                            } else if ($wo['config']['amount_ref'] > 0) {
                                if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                                    $update_user    = Wo_UpdateUserData($user_data['user_id'], array(
                                        'referrer' => $ref_user_id,
                                        'src' => 'Referrer'
                                    ));
                                    $update_balance = Wo_UpdateBalance($ref_user_id, $wo['config']['amount_ref']);
                                    unset($_SESSION['ref']);
                                }
                            }
                        }
                        $amount1                 = $receipt->price;
                        $notes                   = $wo['lang']['upgrade_to_pro'] . " " . $receipt->description . " : Bank";
                        $create_payment_log      = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$wo['user']['user_id']}, 'PRO', {$amount1}, '{$notes}')");
                        $notification_data_array = array(
                            'recipient_id' => $receipt->user_id,
                            'type' => 'admin_notification',
                            'url' => 'index.php?link1=upgraded',
                            'text' => $wo['lang']['bank_pro'],
                            'type2' => 'no_name'
                        );
                        Wo_RegisterNotification($notification_data_array);
                    }
                    $data = array(
                        'status' => 200
                    );
                }
            }
            $data = array(
                'status' => 200,
                'data' => $receipt
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_receipt') {
        if (!empty($_GET['receipt_id'])) {
            $user_id                 = Wo_Secure($_GET['user_id']);
            $id                      = Wo_Secure($_GET['receipt_id']);
            $photo_file              = Wo_Secure($_GET['receipt_file']);
            $receipt                 = $db->where('id', $id)->getOne('bank_receipts', array(
                '*'
            ));
            $notification_data_array = array(
                'recipient_id' => $receipt->user_id,
                'type' => 'admin_notification',
                'url' => 'index.php',
                'text' => $wo['lang']['bank_decline'],
                'type2' => 'no_name'
            );
            Wo_RegisterNotification($notification_data_array);
            $db->where('id', $id)->delete('bank_receipts');
            if (file_exists($photo_file)) {
                @unlink(trim($photo_file));
            } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                @Wo_DeleteFromToS3($photo_file);
            }
            $data = array(
                'status' => 200
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_user_posts') {
        $data['status'] = 400;
        if (!empty($_GET['user_id'])) {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 200
            ));
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $user_id = Wo_Secure($_GET['user_id']);
            Wo_DeleteAllUserPosts($user_id);
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_user_articles') {
        $data['status'] = 400;
        if (!empty($_GET['user_id'])) {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 200
            ));
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $user_id = Wo_Secure($_GET['user_id']);
            $blogs   = $db->where('user', $user_id)->get(T_BLOG);
            if (!empty($blogs)) {
                foreach ($blogs as $key => $value) {
                    Wo_DeleteMyBlog($value->id);
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_user_stories') {
        $data['status'] = 400;
        if (!empty($_GET['user_id'])) {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 200
            ));
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $user_id = Wo_Secure($_GET['user_id']);
            $info    = $db->where('user_id', $user_id)->get(T_USER_STORY);
            if (!empty($info)) {
                foreach ($info as $key => $value) {
                    Wo_DeleteStatus($value->id);
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_user_messages') {
        $data['status'] = 400;
        if (!empty($_GET['user_id'])) {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 200
            ));
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $my_id         = Wo_Secure($_GET['user_id']);
            $query_one     = "SELECT id FROM " . T_MESSAGES . " WHERE (`to_id` = '{$my_id}') OR (`from_id` = {$my_id})";
            $sql_query_one = mysqli_query($sqlConnect, $query_one);
            if (mysqli_num_rows($sql_query_one)) {
                while ($sql_fetch_one = mysqli_fetch_assoc($sql_query_one)) {
                    $deleteMessage = Wo_DeleteMessage($sql_fetch_one['id'], '', $my_id);
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_user_notifications') {
        $data['status'] = 400;
        if (!empty($_GET['user_id'])) {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 200
            ));
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $my_id = Wo_Secure($_GET['user_id']);
            mysqli_query($sqlConnect, "DELETE FROM " . T_NOTIFICATION . " WHERE `recipient_id` = {$my_id} OR `notifier_id` = {$my_id}");
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'ban_user') {
        $data['status'] = 400;
        if (!empty($_GET['user_id']) && is_numeric($_GET['user_id'])) {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 200
            ));
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $user_id      = Wo_Secure($_GET['user_id']);
            $update_array = array(
                'banned' => 1
            );
            if (!empty($_GET['reason'])) {
                $reason                        = Wo_Secure($_GET['reason']);
                $update_array['banned_reason'] = $reason;
            }
            $info = $db->where('user_id', $user_id)->update(T_USERS, $update_array);
            cache($user_id, 'users', 'delete');
            $db->where('user_id', $user_id)->update(T_POSTS, array('active' => 0));
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'unban_user') {
        $data['status'] = 400;
        if (!empty($_GET['user_id']) && is_numeric($_GET['user_id'])) {
            $user_id      = Wo_Secure($_GET['user_id']);
            $update_array = array(
                'banned' => 0,
                'banned_reason' => ''
            );
            $db->where('user_id', $user_id)->update(T_USERS, $update_array);
            cache($user_id, 'users', 'delete');
            $db->where('user_id', $user_id)->update(T_POSTS, array('active' => 1));
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_multi_users') {
        if (!empty($_POST['ids']) && !empty($_POST['type']) && in_array($_POST['type'], array(
            'activate',
            'deactivate',
            'delete',
            'free',
            'star',
            'hot',
            'ultima',
            'vip'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (is_numeric($value) && $value > 0) {
                    if ($_POST['type'] == 'delete') {
                        $delete = Wo_DeleteUser(Wo_Secure($value));
                    } elseif ($_POST['type'] == 'activate') {
                        $db->where('user_id', Wo_Secure($value));
                        $update_data = array(
                            'active' => '1',
                            'email_code' => ''
                        );
                        $update      = $db->update(T_USERS, $update_data);
                    } elseif ($_POST['type'] == 'deactivate') {
                        $db->where('user_id', Wo_Secure($value));
                        $update_data = array(
                            'active' => '0',
                            'email_code' => ''
                        );
                        $update      = $db->update(T_USERS, $update_data);
                    } elseif ($_POST['type'] == 'free') {
                        $member_type = 0;
                        $member_pro  = 0;
                        $down        = Wo_DownUpgradeUser(Wo_Secure($value));
                        $update_data = array(
                            'pro_type' => $member_type,
                            'is_pro' => $member_pro,
                            'pro_time' => 0
                        );
                        Wo_UpdateUserData(Wo_Secure($value), $update_data);
                    } elseif ($_POST['type'] == 'star') {
                        $member_type = 1;
                        $member_pro  = 1;
                        $time        = time();
                        $update_data = array(
                            'pro_type' => $member_type,
                            'is_pro' => $member_pro,
                            'pro_time' => $time
                        );
                        Wo_UpdateUserData(Wo_Secure($value), $update_data);
                    } elseif ($_POST['type'] == 'hot') {
                        $member_type = 2;
                        $member_pro  = 1;
                        $time        = time();
                        $update_data = array(
                            'pro_type' => $member_type,
                            'is_pro' => $member_pro,
                            'pro_time' => $time
                        );
                        Wo_UpdateUserData(Wo_Secure($value), $update_data);
                    } elseif ($_POST['type'] == 'ultima') {
                        $member_type = 3;
                        $member_pro  = 1;
                        $time        = time();
                        $update_data = array(
                            'pro_type' => $member_type,
                            'is_pro' => $member_pro,
                            'pro_time' => $time
                        );
                        Wo_UpdateUserData(Wo_Secure($value), $update_data);
                    } elseif ($_POST['type'] == 'vip') {
                        $member_type = 4;
                        $member_pro  = 1;
                        $time        = time();
                        $update_data = array(
                            'pro_type' => $member_type,
                            'is_pro' => $member_pro,
                            'pro_time' => $time
                        );
                        Wo_UpdateUserData(Wo_Secure($value), $update_data);
                    }
                    cache($value, 'users', 'delete');
                }
            }
            
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_verification') {
        if (!empty($_POST['ids']) && !empty($_POST['type']) && in_array($_POST['type'], array(
            'verify',
            'delete'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (is_numeric($value) && $value > 0) {
                    $verify = $db->where('id', Wo_Secure($value))->getOne(T_VERIFICATION_REQUESTS);
                    if ($_POST['type'] == 'delete') {
                        Wo_DeleteVerificationRequest(Wo_Secure($value));
                    } elseif ($_POST['type'] == 'verify') {
                        $id = $verify->user_id;
                        if (!empty($verify->page_id) && $verify->page_id > 0) {
                            $id = $verify->page_id;
                        }
                        Wo_VerifyUser($id, $verify->id, $verify->type);
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'delete_multi_refund') {
        if (!empty($_POST['ids']) && !empty($_POST['type']) && in_array($_POST['type'], array(
            'approve',
            'delete'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (is_numeric($value) && $value > 0) {
                    $request = $db->where('id', Wo_Secure($value))->getOne(T_REFUND);
                    if ($_POST['type'] == 'delete') {
                        $db->where('id', Wo_Secure($value))->delete(T_REFUND);
                        $data = array(
                            'status' => 200
                        );
                        if (empty($request->order_hash_id)) {
                            $notification_data_array = array(
                                'recipient_id' => $request->user_id,
                                'type' => 'admin_notification',
                                'url' => 'index.php?link1=home',
                                'text' => $wo['lang']['refund_decline'],
                                'type2' => 'refund_decline'
                            );
                            Wo_RegisterNotification($notification_data_array);
                        } else {
                            $notification_data_array = array(
                                'recipient_id' => $request->user_id,
                                'type' => 'admin_notification',
                                'url' => 'index.php?link1=customer_order&id=' . $request->order_hash_id,
                                'text' => $wo['lang']['refund_decline'],
                                'type2' => 'refund_decline'
                            );
                            Wo_RegisterNotification($notification_data_array);
                        }
                    } elseif ($_POST['type'] == 'approve') {
                        if (empty($request->order_hash_id)) {
                            $price = $wo['pro_packages'][$request->pro_type]['price'];
                            $db->where('user_id', $request->user_id)->update(T_USERS, array(
                                'balance' => $db->inc($price),
                                'is_pro' => 0
                            ));
                            $notification_data_array = array(
                                'recipient_id' => $request->user_id,
                                'type' => 'admin_notification',
                                'url' => 'index.php?link1=setting&page=payments',
                                'text' => $wo['lang']['refund_approve'],
                                'type2' => 'refund_approve'
                            );
                            Wo_RegisterNotification($notification_data_array);
                        } else {
                            $total_final_price = 0;
                            $price             = 0;
                            $orders            = $db->where('hash_id', $request->order_hash_id)->get(T_USER_ORDERS);
                            foreach ($orders as $key => $order) {
                                $db->where('id', $order->product_id)->update(T_PRODUCTS, array(
                                    'units' => $db->inc($order->units)
                                ));
                                $total_final_price += $order->final_price;
                                $price += $order->price;
                            }
                            $order = $db->where('hash_id', $request->order_hash_id)->getOne(T_USER_ORDERS);
                            $user  = $db->where('user_id', $order->product_owner_id)->update(T_USERS, array(
                                'balance' => $db->dec($total_final_price)
                            ));
                            $user  = $db->where('user_id', $request->user_id)->update(T_USERS, array(
                                'wallet' => $db->inc($price)
                            ));

                            cache($request->user_id, 'users', 'delete');
                            cache($order->product_owner_id, 'users', 'delete');
                            $db->where('hash_id', $request->order_hash_id)->update(T_USER_ORDERS, array(
                                'status' => 'canceled'
                            ));
                            $notification_data_array = array(
                                'recipient_id' => $request->user_id,
                                'type' => 'admin_notification',
                                'url' => 'index.php?link1=customer_order&id=' . $request->order_hash_id,
                                'text' => $wo['lang']['refund_approve'],
                                'type2' => 'refund_approve'
                            );
                            Wo_RegisterNotification($notification_data_array);
                        }
                        $db->where('id', Wo_Secure($value))->delete(T_REFUND);
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'delete_multi_blog') {
        if (!empty($_POST['ids']) && !empty($_POST['type']) && in_array($_POST['type'], array(
            'activate',
            'deactivate',
            'delete'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (is_numeric($value) && $value > 0) {
                    $post = $db->where('id', Wo_Secure($value))->getOne(T_BLOG);
                    if ($_POST['type'] == 'delete') {
                        Wo_DeleteMyBlog(Wo_Secure($value));
                    } elseif ($_POST['type'] == 'activate') {
                        if (!empty($post)) {
                            $db->where('id', Wo_Secure($value))->update(T_BLOG, array(
                                'active' => '1'
                            ));
                            $db->where('blog_id', Wo_Secure($value))->update(T_POSTS, array(
                                'active' => 1
                            ));
                            $b_post = $db->where('blog_id', Wo_Secure($value))->getOne(T_POSTS);
                            if (!empty($b_post)) {
                                Wo_RegisterPoint($b_post->id, "createblog", '+', $b_post->user_id);
                            }
                            $notification_data_array = array(
                                'recipient_id' => $post->user,
                                'type' => 'admin_notification',
                                'url' => 'index.php?link1=read-blog&id=' . $post->id,
                                'text' => $wo['lang']['approve_blog'],
                                'type2' => 'approve_blog'
                            );
                            Wo_RegisterNotification($notification_data_array);
                        }
                    } elseif ($_POST['type'] == 'deactivate') {
                        if (!empty($post)) {
                            $db->where('id', Wo_Secure($value))->update(T_BLOG, array(
                                'active' => '0'
                            ));
                            $db->where('blog_id', Wo_Secure($value))->update(T_POSTS, array(
                                'active' => 0
                            ));
                        }
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'delete_multi_post') {
        if (!empty($_POST['ids']) && !empty($_POST['type']) && in_array($_POST['type'], array(
            'activate',
            'deactivate',
            'delete'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (is_numeric($value) && $value > 0) {
                    $post = $db->where('id', Wo_Secure($value))->getOne(T_POSTS);
                    if ($_POST['type'] == 'delete') {
                        Wo_DeletePost(Wo_Secure($value));
                    } elseif ($_POST['type'] == 'activate') {
                        if (!empty($post)) {
                            $db->where('id', Wo_Secure($value))->update(T_POSTS, array(
                                'active' => 1
                            ));
                            if (!empty($post->blog_id)) {
                                $db->where('id', $post->blog_id)->update(T_BLOG, array(
                                    'active' => '1'
                                ));
                            }
                            $notification_data_array = array(
                                'recipient_id' => $post->user_id,
                                'type' => 'admin_notification',
                                'url' => 'index.php?link1=post&id=' . $post->id,
                                'text' => $wo['lang']['approve_post'],
                                'type2' => 'approve_post'
                            );
                            Wo_RegisterNotification($notification_data_array);
                        }
                    } elseif ($_POST['type'] == 'deactivate') {
                        if (!empty($post)) {
                            $db->where('id', Wo_Secure($value))->update(T_POSTS, array(
                                'active' => 0
                            ));
                            if (!empty($post->blog_id)) {
                                $db->where('id', $post->blog_id)->update(T_BLOG, array(
                                    'active' => '0'
                                ));
                            }
                        }
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_gender') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && in_array($value, array_keys($wo['genders']))) {
                    $db->where('lang_key', Wo_Secure($value))->delete(T_LANGS);
                    $gender = $db->where('gender_id', Wo_Secure($value))->getOne(T_GENDER);
                    if (!empty($gender)) {
                        $link = $gender->image;
                        if (file_exists($link)) {
                            @unlink(trim($link));
                        } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                            @Wo_DeleteFromToS3($link);
                        }
                        $db->where('gender_id', Wo_Secure($value))->delete(T_GENDER);
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_event') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteEvent($value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_category') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value)) {
                    $types = array(
                        'page' => T_PAGES_CATEGORY,
                        'group' => T_GROUPS_CATEGORY,
                        'blog' => T_BLOGS_CATEGORY,
                        'product' => T_PRODUCTS_CATEGORY,
                        'job' => T_JOB_CATEGORY
                    );
                    if (!empty($_GET['type']) && in_array($_GET['type'], array_keys($types))) {
                        if ($value != 'other' && $value != 'all_') {
                            $lang_key = Wo_Secure($value);
                            $category = $db->where('lang_key', $lang_key)->getOne($types[$_GET['type']]);
                            if (!empty($category)) {
                                $db->where('lang_key', $lang_key)->delete(T_LANGS);
                                $db->where('lang_key', $lang_key)->delete($types[$_GET['type']]);
                                if ($_GET['type'] == 'page') {
                                    $db->where('page_category', $category->id)->update(T_PAGES, array(
                                        'page_category' => 1
                                    ));
                                }
                                if ($_GET['type'] == 'group') {
                                    $db->where('category', $category->id)->update(T_GROUPS, array(
                                        'category' => 1
                                    ));
                                    resetCache("groups");
                                }
                                if ($_GET['type'] == 'blog') {
                                    $db->where('category', $category->id)->update(T_BLOG, array(
                                        'category' => 1
                                    ));
                                }
                                if ($_GET['type'] == 'product') {
                                    $db->where('category', $category->id)->update(T_PRODUCTS, array(
                                        'category' => 0
                                    ));
                                }
                            }
                        }
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_custom_field') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value)) {
                    $placement_array = array(
                        'page',
                        'group',
                        'product'
                    );
                    if (!empty($_GET['type']) && in_array($_GET['type'], $placement_array)) {
                        $delete = Wo_DeleteCustomField($value, $_GET['type']);
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_invitation') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteUserInvitation('id', $value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_ban') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value)) {
                    Wo_DeleteBanned(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_code') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteAdminInvitation('id', $value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_page') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteCustomPage($value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_ads') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteUserAd($value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_sub_category') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value)) {
                    $types = array(
                        'page',
                        'group',
                        'product'
                    );
                    if (!empty($_GET['type']) && in_array($_GET['type'], $types)) {
                        $lang_key = Wo_Secure($value);
                        $category = $db->where('lang_key', $lang_key)->where('type', Wo_Secure($_GET['type']))->getOne(T_SUB_CATEGORIES);
                        if (!empty($category)) {
                            $db->where('lang_key', $lang_key)->delete(T_LANGS);
                            $db->where('id', $category->id)->delete(T_SUB_CATEGORIES);
                            if ($_GET['type'] == 'page') {
                                $db->where('sub_category', $category->id)->update(T_PAGES, array(
                                    'sub_category' => ''
                                ));
                            }
                            if ($_GET['type'] == 'group') {
                                $db->where('sub_category', $category->id)->update(T_GROUPS, array(
                                    'sub_category' => ''
                                ));
                                resetCache("groups");
                            }
                            if ($_GET['type'] == 'product') {
                                $db->where('sub_category', $category->id)->update(T_PRODUCTS, array(
                                    'sub_category' => ''
                                ));
                            }
                        }
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_section') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteForumSection(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_game') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteGame(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_reply') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteThreadReply(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_movies') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteFilm(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_thread') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteForumThread(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_forum') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteForum(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_page') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeletePage(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_fund') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value3) {
                if (!empty($value3) && is_numeric($value3) && $value3 > 0) {
                    $id   = Wo_Secure($value3);
                    $fund = $db->where('id', $id)->getOne(T_FUNDING);
                    if (!empty($fund)) {
                        @Wo_DeleteFromToS3($fund->image);
                        if (file_exists($fund->image)) {
                            try {
                                unlink($fund->image);
                            }
                            catch (Exception $e) {
                            }
                        }
                        $db->where('id', $id)->delete(T_FUNDING);
                        $raise = $db->where('funding_id', $id)->get(T_FUNDING_RAISE);
                        $db->where('funding_id', $id)->delete(T_FUNDING_RAISE);
                        $posts = $db->where('fund_id', $id)->get(T_POSTS);
                        if (!empty($posts)) {
                            foreach ($posts as $key => $value) {
                                $db->where('parent_id', $value->id)->delete(T_POSTS);
                            }
                        }
                        $db->where('fund_id', $id)->delete(T_POSTS);
                        foreach ($raise as $key => $value) {
                            $raise_posts = $db->where('fund_raise_id', $value->id)->get(T_POSTS);
                            if (!empty($raise_posts)) {
                                foreach ($posts as $key => $value1) {
                                    $db->where('parent_id', $value1->id)->delete(T_POSTS);
                                }
                            }
                            $db->where('fund_raise_id', $value->id)->delete(T_POSTS);
                        }
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_offer') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    $offer_id = Wo_Secure($value);
                    $offer    = $db->where('id', $offer_id)->getOne(T_OFFER);
                    if (!empty($offer)) {
                        if (!empty($offer->image)) {
                            @unlink($offer->image);
                            Wo_DeleteFromToS3($offer->image);
                        }
                    }
                    $db->where('id', $offer_id)->delete(T_OFFER);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_job') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    $job_id = Wo_Secure($value);
                    $job    = $db->where('id', $job_id)->getOne(T_JOB);
                    if (!empty($job)) {
                        if ($job->image_type != 'cover') {
                            @unlink($job->image);
                            Wo_DeleteFromToS3($job->image);
                        }
                    }
                    $db->where('id', $job_id)->delete(T_JOB);
                    $db->where('job_id', $job_id)->delete(T_JOB_APPLY);
                    $post = $db->where('job_id', $job_id)->getOne(T_POSTS);
                    if (!empty($post)) {
                        Wo_DeletePost($post->id);
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_group') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteGroup(Wo_Secure($value));
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_app') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteApp($value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_gift') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteGift($value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_sticker') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (!empty($value) && is_numeric($value) && $value > 0) {
                    Wo_DeleteSticker($value);
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'remove_multi_payment') {
        if (!empty($_POST['ids']) && !empty($_POST['type']) && in_array($_POST['type'], array(
            'paid',
            'decline',
            'delete'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (is_numeric($value) && $value > 0) {
                    $get_payment_info = Wo_GetPaymentHistory(Wo_Secure($value));
                    if ($_POST['type'] == 'delete') {
                        if (!empty($get_payment_info)) {
                            $id     = $get_payment_info['id'];
                            $update = mysqli_query($sqlConnect, "UPDATE " . T_A_REQUESTS . " SET status = '2' WHERE id = {$id}");
                            if ($update) {
                                $body                    = Wo_LoadPage('emails/payment-declined');
                                $body                    = str_replace('{{name}}', $get_payment_info['user']['name'], $body);
                                $body                    = str_replace('{{amount}}', $get_payment_info['amount'], $body);
                                $body                    = str_replace('{{site_name}}', $config['siteName'], $body);
                                $send_message_data       = array(
                                    'from_email' => $wo['config']['siteEmail'],
                                    'from_name' => $wo['config']['siteName'],
                                    'to_email' => $get_payment_info['user']['email'],
                                    'to_name' => $get_payment_info['user']['name'],
                                    'subject' => 'Payment Declined | ' . $wo['config']['siteName'],
                                    'charSet' => 'utf-8',
                                    'message_body' => $body,
                                    'is_html' => true
                                );
                                $send_message            = Wo_SendMessage($send_message_data);
                                $notification_data_array = array(
                                    'recipient_id' => $get_payment_info['user_id'],
                                    'type' => 'admin_notification',
                                    'url' => 'index.php?link1=setting&page=payments',
                                    'text' => $wo['lang']['withdraw_declined'],
                                    'type2' => 'withdraw_declined'
                                );
                                Wo_RegisterNotification($notification_data_array);
                            }
                        }
                        $db->where('id', Wo_Secure($value))->delete(T_A_REQUESTS);
                    } elseif ($_POST['type'] == 'decline') {
                        if (!empty($get_payment_info)) {
                            $id     = $get_payment_info['id'];
                            $update = mysqli_query($sqlConnect, "UPDATE " . T_A_REQUESTS . " SET status = '2' WHERE id = {$id}");
                            if ($update) {
                                $body                    = Wo_LoadPage('emails/payment-declined');
                                $body                    = str_replace('{{name}}', $get_payment_info['user']['name'], $body);
                                $body                    = str_replace('{{amount}}', $get_payment_info['amount'], $body);
                                $body                    = str_replace('{{site_name}}', $config['siteName'], $body);
                                $send_message_data       = array(
                                    'from_email' => $wo['config']['siteEmail'],
                                    'from_name' => $wo['config']['siteName'],
                                    'to_email' => $get_payment_info['user']['email'],
                                    'to_name' => $get_payment_info['user']['name'],
                                    'subject' => 'Payment Declined | ' . $wo['config']['siteName'],
                                    'charSet' => 'utf-8',
                                    'message_body' => $body,
                                    'is_html' => true
                                );
                                $send_message            = Wo_SendMessage($send_message_data);
                                $notification_data_array = array(
                                    'recipient_id' => $get_payment_info['user_id'],
                                    'type' => 'admin_notification',
                                    'url' => 'index.php?link1=setting&page=payments',
                                    'text' => $wo['lang']['withdraw_declined'],
                                    'type2' => 'withdraw_declined'
                                );
                                Wo_RegisterNotification($notification_data_array);
                            }
                        }
                    } elseif ($_POST['type'] == 'paid') {
                        if (!empty($get_payment_info)) {
                            $id     = $get_payment_info['id'];
                            $update = mysqli_query($sqlConnect, "UPDATE " . T_A_REQUESTS . " SET status = '1' WHERE id = {$id}");
                            if ($update) {
                                $body                    = Wo_LoadPage('emails/payment-sent');
                                $body                    = str_replace('{{name}}', $get_payment_info['user']['name'], $body);
                                $body                    = str_replace('{{amount}}', $get_payment_info['amount'], $body);
                                $body                    = str_replace('{{site_name}}', $config['siteName'], $body);
                                $send_message_data       = array(
                                    'from_email' => $wo['config']['siteEmail'],
                                    'from_name' => $wo['config']['siteName'],
                                    'to_email' => $get_payment_info['user']['email'],
                                    'to_name' => $get_payment_info['user']['name'],
                                    'subject' => 'New Payment | ' . $wo['config']['siteName'],
                                    'charSet' => 'utf-8',
                                    'message_body' => $body,
                                    'is_html' => true
                                );
                                $send_message            = Wo_SendMessage($send_message_data);
                                $notification_data_array = array(
                                    'recipient_id' => $get_payment_info['user_id'],
                                    'type' => 'admin_notification',
                                    'url' => 'index.php?link1=setting&page=payments',
                                    'text' => $wo['lang']['withdraw_approve'],
                                    'type2' => 'withdraw_approve'
                                );
                                Wo_RegisterNotification($notification_data_array);
                                Wo_UpdateBalance($get_payment_info['user_id'], $get_payment_info['amount'], '-','withdrawal');
                            }
                        }
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'delete_multi_report') {
        if (!empty($_POST['ids']) && !empty($_POST['type']) && in_array($_POST['type'], array(
            'safe',
            'delete',
            'ban'
        ))) {
            foreach ($_POST['ids'] as $key => $value) {
                if (is_numeric($value) && $value > 0) {
                    $report = $db->where('id', Wo_Secure($value))->getOne(T_REPORTS);
                    if ($_POST['type'] == 'delete') {
                        if ($report->post_id != 0) {
                            Wo_DeletePost($report->post_id);
                            Wo_DeleteReport($report->id);
                        } else if ($report->profile_id != 0) {
                            Wo_DeleteUser($report->profile_id);
                            Wo_DeleteReport($report->id);
                        } else if ($report->page_id != 0) {
                            Wo_DeletePage($report->page_id);
                            Wo_DeleteReport($report->id);
                        } else if ($report->group_id != 0) {
                            Wo_DeleteGroup($report->group_id);
                            Wo_DeleteReport($report->id);
                        } else if ($report->comment_id != 0) {
                            Wo_DeletePostComment($report->comment_id);
                            Wo_DeleteReport($report->id);
                        }
                    } elseif ($_POST['type'] == 'safe') {
                        Wo_DeleteReport($report->id);
                    } elseif ($_POST['type'] == 'ban') {
                        $update_array = array(
                            'banned' => 1
                        );
                        $info = $db->where('user_id', $report->profile_id)->update(T_USERS, $update_array);
                        cache($report->profile_id, 'users', 'delete');
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    // category
    if ($s == 'add_new_category') {
        $data['status']  = 400;
        $data['message'] = 'Please check your details';
        $types           = array(
            'page' => T_PAGES_CATEGORY,
            'group' => T_GROUPS_CATEGORY,
            'blog' => T_BLOGS_CATEGORY,
            'product' => T_PRODUCTS_CATEGORY,
            'job' => T_JOB_CATEGORY
        );
        if (!empty($_GET['type']) && in_array($_GET['type'], array_keys($types))) {
            $add         = false;
            $insert_data = array();
            foreach (Wo_LangsNamesFromDB() as $key => $lang) {
                if (!empty($_POST[$lang])) {
                    $insert_data[$lang] = Wo_Secure($_POST[$lang]);
                    $add                = true;
                }
            }
            if ($add == true && !empty($insert_data)) {
                $insert_data['type'] = 'category';
                $id                  = $db->insert(T_LANGS, $insert_data);
                $db->insert($types[$_GET['type']], array(
                    'lang_key' => $id
                ));
                $db->where('id', $id)->update(T_LANGS, array(
                    'lang_key' => $id
                ));
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_category_langs' && !empty($_POST['lang_key'])) {
        $data['status'] = 400;
        $html           = '';
        $langs          = Wo_GetLangDetails($_POST['lang_key']);
        if (count($langs) > 0) {
            foreach ($langs as $key => $wo['langs']) {
                foreach ($wo['langs'] as $wo['key_'] => $wo['lang_vlaue']) {
                    $html .= Wo_LoadAdminPage('edit-lang/form-list');
                }
            }
            $data['status'] = 200;
            $data['html']   = $html;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_category' && !empty($_POST['lang_key'])) {
        $types = array(
            'page' => T_PAGES_CATEGORY,
            'group' => T_GROUPS_CATEGORY,
            'blog' => T_BLOGS_CATEGORY,
            'product' => T_PRODUCTS_CATEGORY,
            'job' => T_JOB_CATEGORY
        );
        if (!empty($_GET['type']) && in_array($_GET['type'], array_keys($types))) {
            if ($_POST['lang_key'] != 'other' && $_POST['lang_key'] != 'all_') {
                $lang_key = Wo_Secure($_POST['lang_key']);
                $category = $db->where('lang_key', $lang_key)->getOne($types[$_GET['type']]);
                if (!empty($category)) {
                    $db->where('lang_key', $lang_key)->delete(T_LANGS);
                    $db->where('lang_key', $lang_key)->delete($types[$_GET['type']]);
                    if ($_GET['type'] == 'page') {
                        $db->where('page_category', $category->id)->update(T_PAGES, array(
                            'page_category' => 1
                        ));
                    }
                    if ($_GET['type'] == 'group') {
                        $db->where('category', $category->id)->update(T_GROUPS, array(
                            'category' => 1
                        ));
                        resetCache("groups");
                    }
                    if ($_GET['type'] == 'blog') {
                        $db->where('category', $category->id)->update(T_BLOG, array(
                            'category' => 1
                        ));
                    }
                    if ($_GET['type'] == 'product') {
                        $db->where('category', $category->id)->update(T_PRODUCTS, array(
                            'category' => 0
                        ));
                    }
                    $data['status'] = 200;
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    // category
    // manage packages
    if ($s == 'add_pro_package') {
        $data['status'] = 400;
        if (!empty($_POST['name']) && !empty($_POST['color']) && !empty($_POST['time']) && in_array($_POST['time'], array('day','week','month','year','unlimited')) && !empty($_FILES['icon']) && !empty($_FILES['night_icon']) && !empty($_POST['max_upload'])) {
            $night_icon = '';
            $icon = '';
            if (!empty($_FILES['icon'])) {
                $fileInfo = array(
                    'file' => $_FILES["icon"]["tmp_name"],
                    'name' => $_FILES['icon']['name'],
                    'size' => $_FILES["icon"]["size"],
                    'type' => $_FILES["icon"]["type"],
                    'types' => 'jpeg,png,jpg,gif,svg',
                    'crop' => array(
                        'width' => 32,
                        'height' => 32
                    )
                );
                $media    = Wo_ShareFile($fileInfo);
                if (!empty($media) && !empty($media['filename'])) {
                    $icon = $media['filename'];
                }
                else{
                    $data['message'] = 'please select another icon';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }
            }
            if (!empty($_FILES['night_icon'])) {
                $fileInfo = array(
                    'file' => $_FILES["night_icon"]["tmp_name"],
                    'name' => $_FILES['night_icon']['name'],
                    'size' => $_FILES["night_icon"]["size"],
                    'type' => $_FILES["night_icon"]["type"],
                    'types' => 'jpeg,png,jpg,gif,svg',
                    'crop' => array(
                        'width' => 32,
                        'height' => 32
                    )
                );
                $media    = Wo_ShareFile($fileInfo);
                if (!empty($media) && !empty($media['filename'])) {
                    $night_icon = $media['filename'];
                }
                else{
                    $data['message'] = 'please select another night icon';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }
            }
            if ($_POST['time'] != 'unlimited' && (empty($_POST['count']) || !is_numeric($_POST['count']))) {
                $data['message'] = 'Please select paid time';
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }

            $insert_data = array('price' => (!empty($_POST['price']) && is_numeric($_POST['price']) ? Wo_Secure($_POST['price']) : 0),
                                 'featured_member' => (!empty($_POST['featured_member']) && is_numeric($_POST['featured_member']) ? Wo_Secure($_POST['featured_member']) : 0),
                                 'profile_visitors' => (!empty($_POST['profile_visitors']) && is_numeric($_POST['profile_visitors']) ? Wo_Secure($_POST['profile_visitors']) : 0),
                                 'last_seen' => (!empty($_POST['last_seen']) && is_numeric($_POST['last_seen']) ? Wo_Secure($_POST['last_seen']) : 0),
                                 'verified_badge' => (!empty($_POST['verified_badge']) && is_numeric($_POST['verified_badge']) ? Wo_Secure($_POST['verified_badge']) : 0),
                                 'pages_promotion' => (!empty($_POST['pages_promotion']) && is_numeric($_POST['pages_promotion']) ? Wo_Secure($_POST['pages_promotion']) : 0),
                                 'posts_promotion' => (!empty($_POST['posts_promotion']) && is_numeric($_POST['posts_promotion']) ? Wo_Secure($_POST['posts_promotion']) : 0),
                                 'description' => (!empty($_POST['description']) ? Wo_Secure($_POST['description']) : ''),
                                 'status' => (!empty($_POST['status']) && is_numeric($_POST['status']) ? Wo_Secure($_POST['status']) : 0),
                                 'discount' => (!empty($_POST['discount']) && is_numeric($_POST['discount']) ? Wo_Secure($_POST['discount']) : 0),
                                 'time_count' => (!empty($_POST['count']) && is_numeric($_POST['count']) ? Wo_Secure($_POST['count']) : 0),
                                 'type' => Wo_Secure($_POST['name']),
                                 'color' => Wo_Secure($_POST['color']),
                                 'image' => $icon,
                                 'night_image' => $night_icon,
                                 'time' => Wo_Secure($_POST['time']),
                                 'max_upload' => Wo_Secure($_POST['max_upload']),
                             );
            $db->insert(T_MANAGE_PRO,$insert_data);
            $data['message'] = 'Pro package added successfully';
            $data['status'] = 200;
        }
        else{
            if (empty($_POST['name'])) {
                $data['message'] = 'name can not be empty';
            }
            elseif (empty($_POST['color'])) {
                $data['message'] = 'color can not be empty';
            }
            elseif (empty($_POST['time'])) {
                $data['message'] = 'Please select paid time';
            }
            elseif (empty($_FILES['icon'])) {
                $data['message'] = 'icon can not be empty';
            }
            elseif (empty($_FILES['night_icon'])) {
                $data['message'] = 'night icon can not be empty';
            }
            elseif (empty($_POST['max_upload'])) {
                $data['message'] = 'max upload size can not be empty';
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_pro_member') {
        $data['status'] = 400;
        $html           = '';
        if (in_array($_POST['type'], array_keys($wo["pro_packages"]))) {
            if (!empty($_POST['name']) && !empty($_POST['color']) && !empty($_POST['time']) && in_array($_POST['time'], array('day','week','month','year','unlimited')) && !empty($_POST['max_upload'])) {

                $update_array = array();

                if (!empty($_FILES['icon'])) {
                    $fileInfo = array(
                        'file' => $_FILES["icon"]["tmp_name"],
                        'name' => $_FILES['icon']['name'],
                        'size' => $_FILES["icon"]["size"],
                        'type' => $_FILES["icon"]["type"],
                        'types' => 'jpeg,png,jpg,gif,svg',
                        'crop' => array(
                            'width' => 32,
                            'height' => 32
                        )
                    );
                    $media    = Wo_ShareFile($fileInfo);
                    if (!empty($media) && !empty($media['filename'])) {
                        $update_array['image'] = $media['filename'];
                    }
                    else{
                        $data['message'] = 'please select another icon';
                        header("Content-type: application/json");
                        echo json_encode($data);
                        exit();
                    }
                }
                if (!empty($_FILES['night_icon'])) {
                    $fileInfo = array(
                        'file' => $_FILES["night_icon"]["tmp_name"],
                        'name' => $_FILES['night_icon']['name'],
                        'size' => $_FILES["night_icon"]["size"],
                        'type' => $_FILES["night_icon"]["type"],
                        'types' => 'jpeg,png,jpg,gif,svg',
                        'crop' => array(
                            'width' => 32,
                            'height' => 32
                        )
                    );
                    $media    = Wo_ShareFile($fileInfo);
                    if (!empty($media) && !empty($media['filename'])) {
                        $update_array['night_image'] = $media['filename'];
                    }
                    else{
                        $data['message'] = 'please select another night icon';
                        header("Content-type: application/json");
                        echo json_encode($data);
                        exit();
                    }
                }
                if ($_POST['time'] != 'unlimited' && (empty($_POST['count']) || !is_numeric($_POST['count']))) {
                    $data['message'] = 'Please select paid time';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }

                if (!empty($_POST['icon_to_use']) && $_POST['icon_to_use'] == 1 && in_array($_POST['type'],array(1,2,3,4))) {
                    $link = substr($wo['pro_packages'][$_POST['type']]['image'], strpos($wo['pro_packages'][$_POST['type']]['image'], 'upload/'));
                    if (file_exists($link)) {
                        @unlink(trim($link));
                    } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                        @Wo_DeleteFromToS3($link);
                    }
                    $update_array['image'] = '';
                    $link           = substr($wo['pro_packages'][$_POST['type']]['night_image'], strpos($wo['pro_packages'][$_POST['type']]['night_image'], 'upload/'));
                    if (file_exists($link)) {
                        @unlink(trim($link));
                    } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                        @Wo_DeleteFromToS3($link);
                    }
                    $update_array['night_image'] = '';
                }

                $update_array['price'] = (!empty($_POST['price']) && is_numeric($_POST['price']) ? Wo_Secure($_POST['price']) : 0);
                $update_array['featured_member'] = (!empty($_POST['featured_member']) && is_numeric($_POST['featured_member']) ? Wo_Secure($_POST['featured_member']) : 0);
                $update_array['profile_visitors'] = (!empty($_POST['profile_visitors']) && is_numeric($_POST['profile_visitors']) ? Wo_Secure($_POST['profile_visitors']) : 0);
                $update_array['last_seen'] = (!empty($_POST['last_seen']) && is_numeric($_POST['last_seen']) ? Wo_Secure($_POST['last_seen']) : 0);
                $update_array['verified_badge'] = (!empty($_POST['verified_badge']) && is_numeric($_POST['verified_badge']) ? Wo_Secure($_POST['verified_badge']) : 0);
                $update_array['pages_promotion'] = (!empty($_POST['pages_promotion']) && is_numeric($_POST['pages_promotion']) ? Wo_Secure($_POST['pages_promotion']) : 0);
                $update_array['posts_promotion'] = (!empty($_POST['posts_promotion']) && is_numeric($_POST['posts_promotion']) ? Wo_Secure($_POST['posts_promotion']) : 0);
                $update_array['description'] = (!empty($_POST['description']) ? Wo_Secure($_POST['description']) : '');
                $update_array['status'] = (!empty($_POST['status']) && is_numeric($_POST['status']) ? Wo_Secure($_POST['status']) : 0);
                $update_array['time_count'] = (!empty($_POST['count']) && is_numeric($_POST['count']) ? Wo_Secure($_POST['count']) : 0);
                $update_array['discount'] = (!empty($_POST['discount']) && is_numeric($_POST['discount']) ? Wo_Secure($_POST['discount']) : 0);
                $update_array['type'] = Wo_Secure($_POST['name']);
                $update_array['color'] = Wo_Secure($_POST['color']);
                $update_array['time'] = Wo_Secure($_POST['time']);
                $update_array['max_upload'] = Wo_Secure($_POST['max_upload']);


                $db->where('id',Wo_Secure($_POST['type']))->update(T_MANAGE_PRO,$update_array);
                $data['status'] = 200;

            }
            else{
                if (empty($_POST['name'])) {
                    $data['message'] = 'name can not be empty';
                }
                elseif (empty($_POST['color'])) {
                    $data['message'] = 'color can not be empty';
                }
                elseif (empty($_POST['time'])) {
                    $data['message'] = 'Please select paid time';
                }
                elseif (empty($_POST['max_upload'])) {
                    $data['message'] = 'max upload size can not be empty';
                }
            }
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_pro_package') {
        if (!empty($_GET['id']) && is_numeric($_GET['id']) && in_array($_GET['id'], array_keys($wo["pro_packages"]))) {
            $link           = substr($wo['pro_packages'][$_GET['id']]['night_image'], strpos($wo['pro_packages'][$_GET['id']]['night_image'], 'upload/'));
            if (file_exists($link)) {
                @unlink(trim($link));
            } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                @Wo_DeleteFromToS3($link);
            }
            $link           = substr($wo['pro_packages'][$_GET['id']]['image'], strpos($wo['pro_packages'][$_GET['id']]['image'], 'upload/'));
            if (file_exists($link)) {
                @unlink(trim($link));
            } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                @Wo_DeleteFromToS3($link);
            }
            $db->where('id',Wo_Secure($_GET['id']))->delete(T_MANAGE_PRO);
        }
        $data['status'] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'select_pro_package') {
        $data['status'] = 200;
        if (!empty($_POST['feature_type'])) {
            foreach ($wo["pro_packages"] as $key => $value) {
                if (!empty($value['features']) && in_array('pro_'.$key, array_keys($_POST)) && in_array($_POST['pro_'.$key],array(0,1))) {
                    $js = json_decode($value['features'],true);
                    $js[Wo_Secure($_POST['feature_type'])] = Wo_Secure($_POST['pro_'.$key]);
                    $db->where('id',$key)->update(T_MANAGE_PRO,array('features' => json_encode($js)));
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'select_pro_model') {
        $wo['feature_type'] = Wo_Secure($_GET['type']);
        $data['status'] = 200;
        $data['html']   = Wo_LoadAdminPage('pro-settings/pro_model');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_pro') {
        $html  = '';
        if (in_array($_POST['type'], array_keys($wo["pro_packages"]))) {
            $wo['pro'] = $wo["pro_packages"][$_POST['type']];
            $html .= Wo_LoadAdminPage('pro-settings/pro_form');
        }
        $data['status'] = 200;
        $data['html']   = $html;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'approve_post') {
        if (!empty($_POST['post_id'])) {
            $post = $db->where('id', Wo_Secure($_POST['post_id']))->getOne(T_POSTS);
            if (!empty($post)) {
                $db->where('id', Wo_Secure($_POST['post_id']))->update(T_POSTS, array(
                    'active' => 1
                ));
                if (!empty($post->blog_id)) {
                    $db->where('id', $post->blog_id)->update(T_BLOG, array(
                        'active' => '1'
                    ));
                }
                $notification_data_array = array(
                    'recipient_id' => $post->user_id,
                    'type' => 'admin_notification',
                    'url' => 'index.php?link1=post&id=' . $post->id,
                    'text' => $wo['lang']['approve_post'],
                    'type2' => 'approve_post'
                );
                Wo_RegisterNotification($notification_data_array);
            }
        }
        $data['status'] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    // manage packages
    if ($s == 'test_vision_api') {
        $data['status'] = 400;
        if (!empty($wo['config']['vision_api_key'])) {
            $image_file = Wo_GetMedia('upload/photos/d-avatar.jpg');
            $content    = '{"requests": [{"image": {"source": {"imageUri": "' . $image_file . '"}},"features": [{"type": "SAFE_SEARCH_DETECTION","maxResults": 1},{"type": "WEB_DETECTION","maxResults": 2}]}]}';
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://vision.googleapis.com/v1/images:annotate?key=' . $wo['config']['vision_api_key']);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($content)
                ));
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                curl_close($ch);
                $new_data = json_decode($response);
                if (!empty($new_data->error)) {
                    $data['message'] = $new_data->error->message;
                }
                else if (!empty($new_data->responses[0]->error)) {
                    $data['message'] = $new_data->responses[0]->error->message;
                } elseif ($new_data->responses[0]->safeSearchAnnotation->adult == 'LIKELY' || $new_data->responses[0]->safeSearchAnnotation->adult == 'VERY_LIKELY' || $new_data->responses[0]->safeSearchAnnotation->adult == 'UNKNOWN' || $new_data->responses[0]->safeSearchAnnotation->adult == 'VERY_UNLIKELY' || $new_data->responses[0]->safeSearchAnnotation->adult == 'UNLIKELY' || $new_data->responses[0]->safeSearchAnnotation->adult == 'POSSIBLE') {
                    $data['status']  = 200;
                    $data['message'] = 'Connection was successfully established!';
                } else {
                    $data['message'] = 'Something went wrong, please try again later.';
                }
            }
            catch (Exception $e) {
                $data['message'] = $e->getMessage();
            }
        } else {
            $data['message'] = 'Vision api key can not be empty.';
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'top_up_wallet') {
        if (!empty($_POST['amount'])) {
            $update = Wo_UpdateUserData($wo['user']['user_id'], array(
                'wallet' => $_POST['amount']
            ));
            if ($update) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_followers') {
        $data           = array();
        $data['status'] = 200;
        $data['error']  = false;
        if (empty($_POST['followers']) || empty($_POST['user_id'])) {
            $data['status'] = 500;
            $data['error']  = $wo['lang']['please_check_details'];
        }
        if (!is_numeric($_POST['followers']) || !is_numeric($_POST['user_id'])) {
            $data['status'] = 500;
            $data['error']  = 'Numbers only are allowed';
        }
        if ($_POST['followers'] < 0 || $_POST['user_id'] < 0) {
            $data['status'] = 500;
            $data['error']  = 'Integer numbers only are allowed';
        }
        $userData = Wo_UserData($_POST['user_id']);
        if (empty($data['error']) && $data['status'] != 500) {
            $followers  = floor($_POST['followers']);
            $usersCount = $db->getValue(T_USERS, 'COUNT(*)');
            if ($followers > $usersCount) {
                $data['status'] = 500;
                $data['error']  = "Followers can't be more than your users: $usersCount";
            }
            if ($db->getValue(T_USERS, "MAX(user_id)") <= $userData['last_follow_id']) {
                $data['status'] = 500;
                $data['error']  = "No more users left to follow, all the users are following {$userData['name']}.";
            }
        }
        if (empty($data['error']) && $data['error'] != 500) {
            $users_id = array();
            $users    = $db->where('user_id', $userData['last_follow_id'], ">")->get(T_USERS, $followers, 'user_id');
            foreach ($users as $key => $i) {
                $users_id[] = $i->user_id;
            }
            if (empty($data['error']) && $data['status'] != 500 && !empty($users_id)) {
                ob_end_clean();
                header("Content-Encoding: none");
                header("Connection: close");
                ignore_user_abort();
                ob_start();
                header('Content-Type: application/json');
                echo json_encode(array(
                    'status' => 200
                ));
                $size = ob_get_length();
                header("Content-Length: $size");
                ob_end_flush();
                flush();
                session_write_close();
                if (is_callable('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                if (is_callable('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                $followed    = Wo_RegisterFollow($_POST['user_id'], $users_id);
                $user_data   = Wo_UpdateUserDetails($_POST['user_id'], false, false, true);
                $update_user = $db->where('user_id', $_POST['user_id'])->update(T_USERS, array(
                    "last_follow_id" => Wo_Secure(end($users_id))
                ));
                cache($_POST['user_id'], 'users', 'delete');
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_custom_code') {
        $data    = array(
            'status' => 400
        );
        $theme   = $wo['config']['theme'];
        $request = (isset($_POST['cheader']) && isset($_POST['cfooter']) && isset($_POST['css']));
        if ($request === true) {
            if (is_writable("themes/$theme/custom")) {
                $up_data        = array(
                    $_POST['cheader'],
                    $_POST['cfooter'],
                    $_POST['css']
                );
                $save           = Wo_CustomCode('p', $up_data);
                $data['status'] = 200;
            } else {
                $data['status'] = 500;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'verfiy_apps') {
        $arrContextOptions             = array(
            "ssl" => array(
                "verify_peer" => false,
                "verify_peer_name" => false
            )
        );
        $data['android_status']        = 0;
        $data['windows_status']        = 0;
        $data['android_native_status'] = 0;
        if (!empty($_POST['android_purchase_code'])) {
            $android_code = Wo_Secure($_POST['android_purchase_code']);
            $file         = file_get_contents("http://www.ramza.com/access_token.php?code={$android_code}&type=android", false, stream_context_create($arrContextOptions));
            $check        = json_decode($file, true);
            if (!empty($check['status'])) {
                if ($check['status'] == 'SUCCESS') {
                    $update                 = Wo_SaveConfig('footer_background', '#aaa');
                    $data['android_status'] = 200;
                } else {
                    $data['android_status'] = 400;
                    $data['android_text']   = $check['ERROR_NAME'];
                }
            }
        }
        if (!empty($_POST['android_native_purchase_code'])) {
            $android_code = Wo_Secure($_POST['android_native_purchase_code']);
            $file         = file_get_contents("http://www.ramza.com/access_token.php?code={$android_code}&type=android", false, stream_context_create($arrContextOptions));
            $check        = json_decode($file, true);
            if (!empty($check['status'])) {
                if ($check['status'] == 'SUCCESS') {
                    $update                        = Wo_SaveConfig('footer_background_n', '#aaa');
                    $data['android_native_status'] = 200;
                } else {
                    $data['android_native_status'] = 400;
                    $data['android_text']          = $check['ERROR_NAME'];
                }
            }
        }
        if (!empty($_POST['windows_purchase_code'])) {
            $windows_code = Wo_Secure($_POST['windows_purchase_code']);
            $file         = file_get_contents("http://www.ramza.com/access_token.php?code={$windows_code}&type=windows_desktop", false, stream_context_create($arrContextOptions));
            $check        = json_decode($file, true);
            if (!empty($check['status'])) {
                if ($check['status'] == 'SUCCESS') {
                    $update                 = Wo_SaveConfig('footer_text_color', '#ddd');
                    $data['windows_status'] = 200;
                } else {
                    $data['windows_status'] = 400;
                    $data['windows_text']   = $check['ERROR_NAME'];
                }
            }
        }
        if (!empty($_POST['ios_purchase_code'])) {
            $windows_code = Wo_Secure($_POST['ios_purchase_code']);
            $file         = file_get_contents("http://www.ramza.com/access_token.php?code={$windows_code}&type=ios", false, stream_context_create($arrContextOptions));
            $check        = json_decode($file, true);
            if (!empty($check['status'])) {
                if ($check['status'] == 'SUCCESS') {
                    $update             = Wo_SaveConfig('footer_background_2', '#aaa');
                    $data['ios_status'] = 200;
                } else {
                    $data['ios_status'] = 400;
                    $data['ios_text']   = $check['ERROR_NAME'];
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_lang_key') {
        if (Wo_CheckSession($hash_id) === true) {
            $array_langs = array();
            $lang_key    = Wo_Secure($_POST['id_of_key']);
            $langs       = Wo_LangsNamesFromDB();
            foreach ($_POST as $key => $value) {
                if (in_array($key, $langs)) {
                    $key   = Wo_Secure($key);
                    //$value = Wo_Secure($value);
                    $value = mysqli_real_escape_string($sqlConnect, $value);
                    $query = mysqli_query($sqlConnect, "UPDATE " . T_LANGS . " SET `{$key}` = '{$value}' WHERE `lang_key` = '{$lang_key}'");
                    if ($query) {
                        $data['status'] = 200;
                    }
                }
            }
            $image = '';
            if (!empty($_FILES['icon'])) {
                $fileInfo = array(
                    'file' => $_FILES["icon"]["tmp_name"],
                    'name' => $_FILES['icon']['name'],
                    'size' => $_FILES["icon"]["size"],
                    'type' => $_FILES["icon"]["type"],
                    'types' => 'jpeg,png,jpg,gif,svg',
                    'crop' => array(
                        'width' => 100,
                        'height' => 100
                    )
                );
                $media    = Wo_ShareFile($fileInfo);
                if (!empty($media) && !empty($media['filename'])) {
                    $image = $media['filename'];
                }
                if (!empty($image)) {
                    $gender = $db->where('gender_id', $lang_key)->getOne(T_GENDER);
                    if (!empty($gender)) {
                        $link = $gender->image;
                        if (file_exists($link)) {
                            @unlink(trim($link));
                        } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                            @Wo_DeleteFromToS3($link);
                        }
                        $db->where('gender_id', $lang_key)->update(T_GENDER, array(
                            'image' => $image
                        ));
                    } else {
                        $db->insert(T_GENDER, array(
                            'gender_id' => $lang_key,
                            'image' => $image
                        ));
                    }
                }
            }
            if (!empty($_POST['icon_to_use']) && $_POST['icon_to_use'] == 1) {
                $gender = $db->where('gender_id', $lang_key)->getOne(T_GENDER);
                if (!empty($gender)) {
                    $link = $gender->image;
                    if (file_exists($link)) {
                        @unlink(trim($link));
                    } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                        @Wo_DeleteFromToS3($link);
                    }
                    $db->where('gender_id', $lang_key)->delete(T_GENDER);
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_gender') {
        $image = '';
        if (!empty($_FILES['icon'])) {
            $fileInfo = array(
                'file' => $_FILES["icon"]["tmp_name"],
                'name' => $_FILES['icon']['name'],
                'size' => $_FILES["icon"]["size"],
                'type' => $_FILES["icon"]["type"],
                'types' => 'jpeg,png,jpg,gif,svg',
                'crop' => array(
                    'width' => 100,
                    'height' => 100
                )
            );
            $media    = Wo_ShareFile($fileInfo);
            if (!empty($media) && !empty($media['filename'])) {
                $image = $media['filename'];
            }
        }
        $insert_data         = array();
        $insert_data['type'] = 'gender';
        $add                 = false;
        foreach (Wo_LangsNamesFromDB() as $wo['key_']) {
            if (!empty($_POST[$wo['key_']])) {
                $insert_data[$wo['key_']] = Wo_Secure($_POST[$wo['key_']]);
                $add                      = true;
            }
        }
        if ($add == true) {
            $id = $db->insert(T_LANGS, $insert_data);
            $db->where('id', $id)->update(T_LANGS, array(
                'lang_key' => $id
            ));
            if (!empty($image)) {
                $db->insert(T_GENDER, array(
                    'gender_id' => $id,
                    'image' => $image
                ));
            }
            $data['status'] = 200;
        } else {
            $data['status']  = 400;
            $data['message'] = $wo['lang']['please_check_details'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_gender') {
        if (!empty($_GET['key']) && in_array($_GET['key'], array_keys($wo['genders']))) {
            $db->where('lang_key', Wo_Secure($_GET['key']))->delete(T_LANGS);
            $gender = $db->where('gender_id', Wo_Secure($_GET['key']))->getOne(T_GENDER);
            if (!empty($gender)) {
                $link = $gender->image;
                if (file_exists($link)) {
                    @unlink(trim($link));
                } else if ($wo['config']['amazone_s3'] == 1 || $wo['config']['wasabi_storage'] == 1 || !empty($wo['config']['cloudflare_r2_storage']) || !empty($wo['config']['s3_compatible_storage']) || $wo['config']['ftp_upload'] == 1 || $wo['config']['spaces'] == 1 || $wo['config']['cloud_upload'] == 1 || $wo['config']['backblaze_storage'] == 1) {
                    @Wo_DeleteFromToS3($link);
                }
                $db->where('gender_id', Wo_Secure($_GET['key']))->delete(T_GENDER);
            }
        }
        $data['status'] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_lang') {
        if (Wo_CheckSession($hash_id) === true) {
            $mysqli = Wo_LangsNamesFromDB();
            if (in_array($_POST['lang'], $mysqli)) {
                $data['status']  = 400;
                $data['message'] = 'This lang is already used.';
            } else {
                $lang_name = Wo_Secure($_POST['lang']);
                $lang_name = strtolower($lang_name);
                $first = "module.exports = function(sequelize, DataTypes) {
                          return sequelize.define('Wo_Langs', {
                            id: {
                              autoIncrement: true,
                              type: DataTypes.INTEGER,
                              allowNull: false,
                              primaryKey: true
                            },
                            lang_key: {
                              type: DataTypes.STRING(160),
                              allowNull: true
                            },
                            type: {
                              type: DataTypes.STRING(100),
                              allowNull: false,
                              defaultValue: \"\"
                            }";
                $last = "}, {
                            sequelize,
                            timestamps: false,
                            tableName: 'Wo_Langs'
                          });
                        };";
                $js = '{type: DataTypes.TEXT,
                        allowNull: true
                       }';
                       $tx = '';
                foreach ($mysqli as $key => $value) {
                    $tx .= ','.$value.': '.$js;
                }
                $tx .= ','.$lang_name.': '.$js;
                file_put_contents("nodejs/models/wo_langs.js", $first.$tx.$last);
                $query     = mysqli_query($sqlConnect, "ALTER TABLE " . T_LANGS . " ADD `$lang_name` TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL DEFAULT NULL;");
                if ($query) {
                    $iso = '';
                    $direction = '';
                    if (!empty($_POST["iso"])) {
                        $iso = Wo_Secure($_POST["iso"]);
                    }
                    if (!empty($_POST["direction"])) {
                        $direction = Wo_Secure($_POST["direction"]);
                    }
                    
                    $db->insert(T_LANG_ISO,array('lang_name' => $lang_name,
                                                 'iso' => $iso,
                                                 'direction' => $direction));
                    $content = file_get_contents('assets/languages/extra/english.php');
                    $fp      = fopen("assets/languages/extra/$lang_name.php", "wb");
                    fwrite($fp, $content);
                    fclose($fp);


                    $english = Wo_LangsFromDB('english');
                    foreach ($english as $key => $lang) {
                        $lang  = Wo_Secure($lang);
                        $query = mysqli_query($sqlConnect, "UPDATE " . T_LANGS . " SET `{$lang_name}` = '$lang' WHERE `lang_key` = '{$key}'");
                    }
                    $data['status'] = 200;
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == "update_iso" && !empty($_POST["lang_name"])) {
        $lang_name = Wo_Secure($_POST["lang_name"]);
        
        if (empty($db->where('lang_name',$lang_name)->getOne(T_LANG_ISO))) {
            $db->insert(T_LANG_ISO,array('lang_name' => $lang_name));
        }
        $update_array = [];
        if (!empty($_POST["iso"])) {
            $update_array['iso'] = Wo_Secure($_POST["iso"]);
        }
        if (!empty($_POST["direction"])) {
            $update_array['direction'] = Wo_Secure($_POST["direction"]);
        }
        $db->where('lang_name',$lang_name)->update(T_LANG_ISO,$update_array);
        $data["status"] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_lang_key') {
        if (Wo_CheckSession($hash_id) === true) {
            if (!empty($_POST['lang_key'])) {
                $lang_key  = Wo_Secure($_POST['lang_key']);
                $mysqli    = mysqli_query($sqlConnect, "SELECT COUNT(id) as count FROM " . T_LANGS . " WHERE `lang_key` = '$lang_key'");
                $sql_fetch = mysqli_fetch_assoc($mysqli);
                if ($sql_fetch['count'] == 0) {
                    $mysqli = mysqli_query($sqlConnect, "INSERT INTO " . T_LANGS . " (`lang_key`) VALUE ('$lang_key')");
                    if ($mysqli) {
                        $data['status'] = 200;
                        $data['url']    = Wo_LoadAdminLinkSettings('manage-languages');
                    }
                } else {
                    $data['status']  = 400;
                    $data['message'] = 'This key is already used, please use other one.';
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_lang') {
        if (Wo_CheckMainSession($hash_id) === true) {
            $mysqli = Wo_LangsNamesFromDB();
            if (in_array($_GET['id'], $mysqli)) {
                $lang_name = Wo_Secure($_GET['id']);
                $query     = mysqli_query($sqlConnect, "ALTER TABLE " . T_LANGS . " DROP COLUMN `$lang_name`");
                if ($query) {
                    $mysqli = Wo_LangsNamesFromDB();
                    $first = "module.exports = function(sequelize, DataTypes) {
                          return sequelize.define('Wo_Langs', {
                            id: {
                              autoIncrement: true,
                              type: DataTypes.INTEGER,
                              allowNull: false,
                              primaryKey: true
                            },
                            lang_key: {
                              type: DataTypes.STRING(160),
                              allowNull: true
                            },
                            type: {
                              type: DataTypes.STRING(100),
                              allowNull: false,
                              defaultValue: \"\"
                            }";
                $last = "}, {
                            sequelize,
                            timestamps: false,
                            tableName: 'Wo_Langs'
                          });
                        };";
                $js = '{type: DataTypes.TEXT,
                        allowNull: true
                       }';
                       $tx = '';
                foreach ($mysqli as $key => $value) {
                    $tx .= ','.$value.': '.$js;
                }
                file_put_contents("nodejs/models/wo_langs.js", $first.$tx.$last);
                    $db->where('lang_name',$lang_name)->delete(T_LANG_ISO);
                    unlink("assets/languages/extra/$lang_name.php");
                    $data['status'] = 200;
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'remove_multi_lang') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                if (in_array($value, $langs)) {
                    $lang_name = Wo_Secure($value);
                    $t_langs   = T_LANGS;
                    $query     = mysqli_query($sqlConnect, "ALTER TABLE `$t_langs` DROP COLUMN `$lang_name`");
                    if ($query) {
                        if (file_exists("assets/languages/extra/$lang_name.php")) {
                            unlink("assets/languages/extra/$lang_name.php");
                        }
                    }
                }
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'reset_windows_app_keys') {
        $app_key    = sha1(rand(111111111, 999999999)) . '-' . md5(microtime()) . '-' . rand(11111111, 99999999);
        $data_array = array(
            'widnows_app_api_key' => $app_key
        );
        foreach ($data_array as $key => $value) {
            $saveSetting = Wo_SaveConfig($key, $value);
        }
        if ($saveSetting === true) {
            $data['status']  = 200;
            $data['app_key'] = $app_key;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'cancel_pro') {
        $cancel = Wo_DeleteProMemebership();
        if ($cancel) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_ref_system') {
        $saveSetting = false;
        if (!empty($_POST['affiliate_type'])) {
            $_POST['affiliate_type'] = 1;
        } else {
            $_POST['affiliate_type'] = 0;
        }
        foreach ($_POST as $key => $value) {
            if ($key != 'hash_id') {
                $saveSetting = Wo_SaveConfig($key, $value);
            }
        }
        if ($saveSetting === true) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'mark_as_paid') {
        if (!empty($_GET['id']) && Wo_CheckSession($hash_id)) {
            $get_payment_info = Wo_GetPaymentHistory($_GET['id']);
            if (!empty($get_payment_info)) {
                $id     = $get_payment_info['id'];
                $update = mysqli_query($sqlConnect, "UPDATE " . T_A_REQUESTS . " SET status = '1' WHERE id = {$id}");
                if ($update) {
                    $body                    = Wo_LoadPage('emails/payment-sent');
                    $body                    = str_replace('{{name}}', $get_payment_info['user']['name'], $body);
                    $body                    = str_replace('{{amount}}', $get_payment_info['amount'], $body);
                    $body                    = str_replace('{{site_name}}', $config['siteName'], $body);
                    $send_message_data       = array(
                        'from_email' => $wo['config']['siteEmail'],
                        'from_name' => $wo['config']['siteName'],
                        'to_email' => $get_payment_info['user']['email'],
                        'to_name' => $get_payment_info['user']['name'],
                        'subject' => 'New Payment | ' . $wo['config']['siteName'],
                        'charSet' => 'utf-8',
                        'message_body' => $body,
                        'is_html' => true
                    );
                    $send_message            = Wo_SendMessage($send_message_data);
                    $notification_data_array = array(
                        'recipient_id' => $get_payment_info['user_id'],
                        'type' => 'admin_notification',
                        'url' => 'index.php?link1=setting&page=payments',
                        'text' => $wo['lang']['withdraw_approve'],
                        'type2' => 'withdraw_approve'
                    );
                    Wo_RegisterNotification($notification_data_array);
                    Wo_UpdateBalance($get_payment_info['user_id'], $get_payment_info['amount'], '-','withdrawal');
                    $data['status'] = 200;
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'decline_payment') {
        if (!empty($_GET['id']) && Wo_CheckSession($hash_id)) {
            $get_payment_info = Wo_GetPaymentHistory($_GET['id']);
            if (!empty($get_payment_info)) {
                $id     = $get_payment_info['id'];
                $update = mysqli_query($sqlConnect, "UPDATE " . T_A_REQUESTS . " SET status = '2' WHERE id = {$id}");
                if ($update) {
                    $body                    = Wo_LoadPage('emails/payment-declined');
                    $body                    = str_replace('{{name}}', $get_payment_info['user']['name'], $body);
                    $body                    = str_replace('{{amount}}', $get_payment_info['amount'], $body);
                    $body                    = str_replace('{{site_name}}', $config['siteName'], $body);
                    $send_message_data       = array(
                        'from_email' => $wo['config']['siteEmail'],
                        'from_name' => $wo['config']['siteName'],
                        'to_email' => $get_payment_info['user']['email'],
                        'to_name' => $get_payment_info['user']['name'],
                        'subject' => 'Payment Declined | ' . $wo['config']['siteName'],
                        'charSet' => 'utf-8',
                        'message_body' => $body,
                        'is_html' => true
                    );
                    $send_message            = Wo_SendMessage($send_message_data);
                    $notification_data_array = array(
                        'recipient_id' => $get_payment_info['user_id'],
                        'type' => 'admin_notification',
                        'url' => 'index.php?link1=setting&page=payments',
                        'text' => $wo['lang']['withdraw_declined'],
                        'type2' => 'withdraw_declined'
                    );
                    Wo_RegisterNotification($notification_data_array);
                    if ($send_message) {
                        $data['status'] = 200;
                    }
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_page') {
        if (Wo_CheckSession($hash_id) === true && !empty($_POST['page_name']) && !empty($_POST['page_content']) && !empty($_POST['page_title'])) {
            $page_name    = Wo_Secure($_POST['page_name']);
            $page_content = Wo_Secure(str_replace(array(
                "\r",
                "\n"
            ), "", $_POST['page_content']));
            $page_title   = Wo_Secure($_POST['page_title']);
            $page_type    = 0;
            if (!empty($_POST['page_type'])) {
                $page_type = 1;
            }
            if (!preg_match('/^[\w]+$/', $page_name)) {
                $data = array(
                    'status' => 400,
                    'message' => 'Invalid page name characters'
                );
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
            $data_ = array(
                'page_name' => $page_name,
                'page_content' => $page_content,
                'page_title' => $page_title,
                'page_type' => $page_type
            );
            $add   = Wo_RegisterNewPage($data_);
            if ($add) {
                $data['status'] = 200;
            }
        } else {
            $data = array(
                'status' => 400,
                'message' => 'Please fill all the required fields'
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'edit_page') {
        if (Wo_CheckSession($hash_id) === true && !empty($_POST['page_id']) && !empty($_POST['page_name']) && !empty($_POST['page_content']) && !empty($_POST['page_title'])) {
            $page_name    = $_POST['page_name'];
            $page_content = $_POST['page_content'];
            $page_title   = $_POST['page_title'];
            $page_type    = 0;
            if (!empty($_POST['page_type'])) {
                $page_type = 1;
            }
            if (!preg_match('/^[\w]+$/', $page_name)) {
                $data = array(
                    'status' => 400,
                    'message' => 'Invalid page name characters'
                );
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
            $data_ = array(
                'page_name' => $page_name,
                'page_content' => $page_content,
                'page_title' => $page_title,
                'page_type' => $page_type
            );
            $add   = Wo_UpdateCustomPageData($_POST['page_id'], $data_);
            if ($add) {
                $data['status'] = 200;
            }
        } else {
            $data = array(
                'status' => 400,
                'message' => 'Please fill all the required fields'
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_field') {
        if (Wo_CheckSession($hash_id) === true && !empty($_POST['name']) && !empty($_POST['type']) && !empty($_POST['description'])) {
            $type              = Wo_Secure($_POST['type']);
            $name              = Wo_Secure($_POST['name']);
            $description       = Wo_Secure($_POST['description']);
            $registration_page = 0;
            if (!empty($_POST['registration_page'])) {
                $registration_page = 1;
            }
            $profile_page = 0;
            if (!empty($_POST['profile_page'])) {
                $profile_page = 1;
            }
            $length = 32;
            if (!empty($_POST['length'])) {
                if (is_numeric($_POST['length']) && $_POST['length'] < 1001) {
                    $length = Wo_Secure($_POST['length']);
                }
            }
            $placement_array = array(
                'profile',
                'general',
                'social',
                'none'
            );
            $placement       = 'profile';
            if (!empty($_POST['placement'])) {
                if (in_array($_POST['placement'], $placement_array)) {
                    $placement = Wo_Secure($_POST['placement']);
                }
            }
            $data_ = array(
                'name' => $name,
                'description' => $description,
                'length' => $length,
                'placement' => $placement,
                'registration_page' => $registration_page,
                'profile_page' => $profile_page,
                'active' => 1
            );
            if (!empty($_POST['options'])) {
                $options              = @explode("\n", $_POST['options']);
                $type                 = Wo_Secure(implode(',', $options));
                $data_['select_type'] = 'yes';
            }
            $data_['type'] = $type;
            $add           = Wo_RegisterNewField($data_);
            if ($add) {
                $data['status'] = 200;
            }
        } else {
            $data = array(
                'status' => 400,
                'message' => 'Please fill all the required fields'
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'edit_field') {
        if (Wo_CheckSession($hash_id) === true && !empty($_POST['name']) && !empty($_POST['description']) && !empty($_POST['id'])) {
            $name              = Wo_Secure($_POST['name']);
            $description       = Wo_Secure($_POST['description']);
            $registration_page = 0;
            if (!empty($_POST['registration_page'])) {
                $registration_page = 1;
            }
            $profile_page = 0;
            if (!empty($_POST['profile_page'])) {
                $profile_page = 1;
            }
            $active = 0;
            if (!empty($_POST['active'])) {
                $active = 1;
            }
            $length = 32;
            if (!empty($_POST['length'])) {
                if (is_numeric($_POST['length'])) {
                    $length = Wo_Secure($_POST['length']);
                }
            }
            $placement_array = array(
                'profile',
                'general',
                'social',
                'none'
            );
            $placement       = 'profile';
            if (!empty($_POST['placement'])) {
                if (in_array($_POST['placement'], $placement_array)) {
                    $placement = Wo_Secure($_POST['placement']);
                }
            }
            $data_ = array(
                'name' => $name,
                'description' => $description,
                'length' => $length,
                'placement' => $placement,
                'registration_page' => $registration_page,
                'profile_page' => $profile_page,
                'active' => $active
            );
            if (!empty($_POST['options'])) {
                $options              = @explode("\n", $_POST['options']);
                $data_['type']        = implode(',', $options);
                $data_['select_type'] = 'yes';
            }
            $add = Wo_UpdateField($_POST['id'], $data_);
            if ($add) {
                $data['status'] = 200;
            }
        } else {
            $data = array(
                'status' => 400,
                'message' => 'Please fill all the required fields'
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_field') {
        if (Wo_CheckMainSession($hash_id) === true && !empty($_GET['id'])) {
            $delete = Wo_DeleteField($_GET['id']);
            if ($delete) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'remove_multi_fields') {
        if (!empty($_POST['ids'])) {
            foreach ($_POST['ids'] as $key => $value) {
                Wo_DeleteField(Wo_Secure($value));
            }
            $data = array(
                'status' => 200
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }
    if ($s == 'delete_page') {
        if (Wo_CheckMainSession($hash_id) === true && !empty($_GET['id'])) {
            $delete = Wo_DeleteCustomPage($_GET['id']);
            if ($delete) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'new_backup') {
        $b = Wo_Backup($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
        if ($b) {
            $data['status'] = 200;
            $data['date']   = date('d-m-Y');
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'system_diagnostics') {
        $data = array('status' => 400, 'message' => 'Diagnostic action was not accepted.');
        if (Wo_CheckSession($hash_id) === true && !empty($wo['user']['admin'])) {
            $action = isset($_POST['action']) ? (string) $_POST['action'] : 'collect';
            if ($action === 'send') {
                $feedback = array(
                    'subject' => isset($_POST['feedback_subject']) && !is_array($_POST['feedback_subject']) ? (string) $_POST['feedback_subject'] : '',
                    'message' => isset($_POST['feedback_message']) && !is_array($_POST['feedback_message']) ? (string) $_POST['feedback_message'] : '',
                );
                $result = function_exists('Ramza_DiagnosticsSend') ? Ramza_DiagnosticsSend(true, $feedback) : array('ok' => false, 'message' => 'Diagnostic service is unavailable.');
                $data['status'] = !empty($result['ok']) ? 200 : 400;
                $data['message'] = (string) ($result['message'] ?? 'Diagnostic report completed.');
            } else {
                $data['status'] = 200;
                $data['message'] = 'Runtime scan refreshed.';
                $data['diagnostics'] = function_exists('Ramza_DiagnosticsSnapshot') ? Ramza_DiagnosticsSnapshot(false) : array();
            }
        }
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
    if ($s == 'ffmpeg_debug') {
        $ffmpegStatus = function_exists('Ramza_FfmpegStatus') ? Ramza_FfmpegStatus() : array('available' => false, 'message' => 'FFmpeg helper is unavailable.');
        $ffmpeg_b = function_exists('Ramza_FfmpegCommand') ? Ramza_FfmpegCommand() : '';
        if (empty($ffmpegStatus['available']) || $ffmpeg_b === '') {
            $data['status'] = 200;
            $data['data']   = (string) ($ffmpegStatus['message'] ?? 'FFmpeg is not available.');
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
        $video_output_full_path_240 = dirname(__DIR__) . "/admin-panel/videos/test_240p_converted.mp4";
        @unlink($video_output_full_path_240);
        $video_file_full_path = dirname(__DIR__) . "/admin-panel/videos/test.mp4";
        $shell                = shell_exec($ffmpeg_b . ' -y -i ' . escapeshellarg($video_file_full_path) . ' -vcodec libx264 -preset ' . escapeshellarg((string) $wo['config']['convert_speed']) . ' -filter:v scale=426:-2 -crf 26 ' . escapeshellarg($video_output_full_path_240) . ' 2>&1');
        if (file_exists($video_output_full_path_240)) {
            $data['video_url'] = $wo['config']['site_url'] . '/admin-panel/videos/test_240p_converted.mp4';
        }
        $data['status'] = 200;
        $data['data']   = $shell;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_general_setting' && (Wo_CheckSession($hash_id) === true || Wo_CheckMainSession($hash_id) === true)) {
        $saveSetting         = false;
        $delete_follow_table = 0;
        if (!empty($_FILES) && !empty($_FILES["cloud_file"])) {
            $fileInfo = array(
                'file' => $_FILES["cloud_file"]["tmp_name"],
                'name' => $_FILES['cloud_file']['name'],
                'size' => $_FILES["cloud_file"]["size"],
                'type' => $_FILES["cloud_file"]["type"],
                'types' => 'json',
                'local_upload' => 1
            );
            $media    = Wo_ShareFile($fileInfo);
            if (!empty($media) && !empty($media['filename'])) {
                Wo_SaveConfig('cloud_file_path', $media['filename']);
                resetCache();
            }
        }
        $pwaIconUploads = array(
            'pwa_icon_192' => 192,
            'pwa_icon_512' => 512
        );
        foreach ($pwaIconUploads as $pwaIconKey => $pwaIconSize) {
            if (!empty($_FILES[$pwaIconKey]) && !empty($_FILES[$pwaIconKey]['tmp_name'])) {
                if (!empty($_FILES[$pwaIconKey]['error']) && $_FILES[$pwaIconKey]['error'] !== UPLOAD_ERR_OK) {
                    $data['status'] = 400;
                    $data['message'] = 'Unable to upload the PWA icon. Please choose a valid image file.';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }

                $pwaIconInfo = @getimagesize($_FILES[$pwaIconKey]['tmp_name']);
                if (empty($pwaIconInfo) || (int)$pwaIconInfo[0] !== $pwaIconSize || (int)$pwaIconInfo[1] !== $pwaIconSize) {
                    $data['status'] = 400;
                    $data['message'] = 'The ' . $pwaIconSize . 'x' . $pwaIconSize . ' PWA icon must be exactly ' . $pwaIconSize . 'x' . $pwaIconSize . ' pixels.';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }

                $pwaIconExtension = strtolower(pathinfo($_FILES[$pwaIconKey]['name'], PATHINFO_EXTENSION));
                $pwaIconMime = Wo_NormalizeUploadedMime($_FILES[$pwaIconKey]['type'], $_FILES[$pwaIconKey]['name'], $_FILES[$pwaIconKey]['tmp_name']);
                if (!in_array($pwaIconExtension, array('png', 'jpg', 'jpeg'), true) || !in_array($pwaIconMime, array('image/png', 'image/jpeg', 'image/jpg'), true)) {
                    $data['status'] = 400;
                    $data['message'] = 'PWA icons must be PNG or JPG images.';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }

                $fileInfo = array(
                    'file' => $_FILES[$pwaIconKey]['tmp_name'],
                    'name' => $_FILES[$pwaIconKey]['name'],
                    'size' => $_FILES[$pwaIconKey]['size'],
                    'type' => $_FILES[$pwaIconKey]['type'],
                    'types' => 'png,jpg,jpeg',
                    'compress' => false
                );
                $media = Wo_ShareFile($fileInfo, 0, false);
                if (!empty($media) && !empty($media['filename'])) {
                    $saveSetting = Wo_SaveConfig($pwaIconKey, $media['filename']);
                    $wo['config'][$pwaIconKey] = $media['filename'];
                    resetCache();
                } else {
                    $data['status'] = 400;
                    $data['message'] = 'Unable to save the PWA icon. Please confirm uploads are enabled and try again.';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }
            }
        }
        foreach ($_POST as $key => $value) {
            if (strpos((string)$key, 'algorithm_') === 0 && function_exists('Wo_RamzaAlgorithmValidateAdminSetting')) {
                $validated_algorithm_value = Wo_RamzaAlgorithmValidateAdminSetting($key, $value);
                if ($validated_algorithm_value === null) {
                    continue;
                }
                $value = $validated_algorithm_value;
            }
            if (!empty($value) && in_array($key, $wo['encryptedKeys'])) {
                $value = Wo_EncryptConfigValue($value);
            }
            if ($key == 'bank' || $key == 'p_paypal' || $key == 'skrill' || $key == 'custom') {
                if (in_array($value, array(0,1))) {
                    $p_key = $key;
                    if ($key == 'p_paypal') {
                        $p_key = 'paypal';
                    }
                    $wo['config']['withdrawal_payment_method'][$p_key] = Wo_Secure($value);
                    Wo_SaveConfig('withdrawal_payment_method', json_encode($wo['config']['withdrawal_payment_method']));
                }
            }
            if ($key == 'braintree_payment' || $key == 'braintree_mode' || $key == 'braintree_merchant_id' || $key == 'braintree_public_key' || $key == 'braintree_private_key') {

                if (!empty($wo['config']['braintree_mode']) && !empty($wo['config']['braintree_merchant_id']) && !empty($wo['config']['braintree_public_key']) && !empty($wo['config']['braintree_private_key'])) {
                    
                    require_once 'assets/libraries/braintree/vendor/autoload.php';

                    $gateway = new Braintree\Gateway([
                        'environment' => $wo['config']['braintree_mode'],
                        'merchantId' => $wo['config']['braintree_merchant_id'],
                        'publicKey' => $wo['config']['braintree_public_key'],
                        'privateKey' => $wo['config']['braintree_private_key']
                    ]);

                    $clientToken = $gateway->clientToken()->generate();
                    Wo_SaveConfig('braintree_token', $clientToken);
                } 
            }
            if ($key == 'google_map') {
                if ($wo['config']['yandex_map'] == 1) {
                    Wo_SaveConfig('yandex_map', 0);
                }
            }
            if ($key == 'yandex_map') {
                if ($wo['config']['google_map'] == 1) {
                    Wo_SaveConfig('google_map', 0);
                }
            }
            if ($key == 'website_mode') {
                $futureWebsiteModes = array(
                    'twitter' => '2.0',
                    'askfm' => '2.5',
                    'tiktok' => '3.0',
                );
                if (isset($futureWebsiteModes[$value]) && version_compare((string)($wo['config']['version'] ?? '1.0'), $futureWebsiteModes[$value], '<')) {
                    $data['status'] = 400;
                    $data['message'] = 'This website mode unlocks in RACSocial v' . $futureWebsiteModes[$value] . '.';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }
                if (!empty($wo['website_modes_off'][$wo['config']['website_mode']])) {
                    foreach ($wo['website_modes_off'][$wo['config']['website_mode']] as $key5 => $value5) {
                        if ($value5 != 'second_post_button') {
                            Wo_SaveConfig($value5, 1);
                        }
                    }
                }
                if ($value == 'linkedin') {
                    Wo_SaveConfig('events', 1);
                    Wo_SaveConfig('blogs', 1);
                    Wo_SaveConfig('job_system', 1);
                    Wo_SaveConfig('funding_system', 1);
                    Wo_SaveConfig('pages', 1);
                    Wo_SaveConfig('groups', 1);
                    Wo_SaveConfig('forum', 1);
                    foreach ($wo['website_modes_off']['linkedin'] as $key2 => $value2) {
                        Wo_SaveConfig($value2, 0);
                    }
                    Wo_SaveConfig('second_post_button', 'reaction');
                    resetCache();
                } elseif ($value == 'instagram') {
                    Wo_SaveConfig('classified', 1);
                    Wo_SaveConfig('user_status', 1);
                    Wo_SaveConfig('offer_system', 1);
                    foreach ($wo['website_modes_off']['instagram'] as $key2 => $value2) {
                        Wo_SaveConfig($value2, 0);
                    }
                    Wo_SaveConfig('second_post_button', 'disabled');
                    resetCache();
                } elseif ($value == 'twitter') {
                    foreach ($wo['website_modes_off']['twitter'] as $key2 => $value2) {
                        Wo_SaveConfig($value2, 0);
                    }
                    Wo_SaveConfig('second_post_button', 'disabled');
                    resetCache();
                } elseif ($value == 'askfm') {
                    foreach ($wo['website_modes_off']['askfm'] as $key2 => $value2) {
                        Wo_SaveConfig($value2, 0);
                    }
                    Wo_SaveConfig('second_post_button', 'disabled');
                    resetCache();
                } elseif ($value == 'patreon') {
                    foreach ($wo['website_modes_off']['patreon'] as $key2 => $value2) {
                        Wo_SaveConfig($value2, 0);
                    }
                    Wo_SaveConfig('second_post_button', 'disabled');
                    resetCache();
                } else {
                    Wo_SaveConfig('second_post_button', 'reaction');
                }
            }
            if ($key == 'ffmpeg_binary_file') {
                if (empty($value)) {
                    $value = '';
                } elseif (file_exists($value)) {
                    $value = $value;
                } else {
                    $value = '';
                }
            }
            if (isset($wo['config'][$key]) || $key == 'googleAnalytics_en' || $key == 'post_views' || $key == 'post_view_type') {
                if (in_array($key, array('pwa_theme_color', 'pwa_background_color'), true) && !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$value)) {
                    $value = ($key == 'pwa_theme_color') ? '#c94b57' : '#f6f7f9';
                }
                if ($key == 'pwa_display' && !in_array($value, array('standalone', 'minimal-ui', 'fullscreen', 'browser'), true)) {
                    $value = 'standalone';
                }
                if ($key == 'pwa_cache_strategy' && !in_array($value, array('network_first', 'cache_first'), true)) {
                    $value = 'network_first';
                }
                if (in_array($key, array('pwa_start_url', 'pwa_scope'), true)) {
                    $value = trim((string)$value);
                    if ($value === '') {
                        $value = '/';
                    }
                }
                if ($key == 'yandex_translate') {
                    if ($value == 1) {
                        $saveSetting = Wo_SaveConfig('google_translate', 0);
                    }
                }
                if ($key == 'google_translate') {
                    if ($value == 1) {
                        $saveSetting = Wo_SaveConfig('yandex_translate', 0);
                    }
                }
                if ($key == 'agora_chat_video') {
                    if ($config['twilio_video_chat'] == 1) {
                        $saveSetting = Wo_SaveConfig('twilio_video_chat', 0);
                    }
                }
                if ($key == 'twilio_video_chat') {
                    if ($config['agora_chat_video'] == 1) {
                        $saveSetting = Wo_SaveConfig('agora_chat_video', 0);
                    }
                }
                if ($key == 'googleAnalytics_en') {
                    $key   = 'googleAnalytics';
                    $value = base64_decode($value);
                }
                if ($key == 'connectivitySystem') {
                    if (isset($_POST['connectivitySystem'])) {
                        if ($config['connectivitySystem'] == 1 && $_POST['connectivitySystem'] != 1) {
                            $delete_follow_table = 1;
                        } else if ($config['connectivitySystem'] != 1 && $_POST['connectivitySystem'] == 1) {
                            $delete_follow_table = 1;
                        }
                    }
                }
                $storage_switches = array('ftp_upload', 'amazone_s3', 'spaces', 'cloud_upload', 'wasabi_storage', 'backblaze_storage', 'cloudflare_r2_storage', 's3_compatible_storage');
                if (in_array($key, $storage_switches, true) && $value == 1) {
                    foreach ($storage_switches as $storage_switch) {
                        if ($storage_switch !== $key && !empty($wo['config'][$storage_switch]) && $wo['config'][$storage_switch] == 1) {
                            $saveSetting = Wo_SaveConfig($storage_switch, 0);
                        }
                    }
                    resetCache();
                }
                if ($key == 'ftp_upload') {
                    if ($value == 1) {
                        if ($wo['config']['amazone_s3'] == 1) {
                            $saveSetting = Wo_SaveConfig('amazone_s3', 0);
                        }
                        if ($wo['config']['wasabi_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('wasabi_storage', 0);
                        }
                        if ($wo['config']['spaces'] == 1) {
                            $saveSetting = Wo_SaveConfig('spaces', 0);
                        }
                        if ($wo['config']['cloud_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('cloud_upload', 0);
                        }
                        if ($wo['config']['backblaze_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('backblaze_storage', 0);
                        }
                        resetCache();
                    }
                }
                if ($key == 'amazone_s3') {
                    if ($value == 1) {
                        if ($wo['config']['ftp_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('ftp_upload', 0);
                        }
                        if ($wo['config']['wasabi_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('wasabi_storage', 0);
                        }
                        if ($wo['config']['spaces'] == 1) {
                            $saveSetting = Wo_SaveConfig('spaces', 0);
                        }
                        if ($wo['config']['cloud_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('cloud_upload', 0);
                        }
                        if ($wo['config']['backblaze_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('backblaze_storage', 0);
                        }
                        resetCache();
                    }
                }
                if ($key == 'spaces') {
                    if ($value == 1) {
                        if ($wo['config']['ftp_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('ftp_upload', 0);
                        }
                        if ($wo['config']['wasabi_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('wasabi_storage', 0);
                        }
                        if ($wo['config']['amazone_s3'] == 1) {
                            $saveSetting = Wo_SaveConfig('amazone_s3', 0);
                        }
                        if ($wo['config']['cloud_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('cloud_upload', 0);
                        }
                        if ($wo['config']['backblaze_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('backblaze_storage', 0);
                        }
                        resetCache();
                    }
                }
                if ($key == 'cloud_upload') {
                    if ($value == 1) {
                        if ($wo['config']['ftp_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('ftp_upload', 0);
                        }
                        if ($wo['config']['wasabi_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('wasabi_storage', 0);
                        }
                        if ($wo['config']['amazone_s3'] == 1) {
                            $saveSetting = Wo_SaveConfig('amazone_s3', 0);
                        }
                        if ($wo['config']['spaces'] == 1) {
                            $saveSetting = Wo_SaveConfig('spaces', 0);
                        }
                        if ($wo['config']['backblaze_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('backblaze_storage', 0);
                        }
                        resetCache();
                    }
                }
                if ($key == 'wasabi_storage') {
                    if ($value == 1) {
                        if ($wo['config']['ftp_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('ftp_upload', 0);
                        }
                        if ($wo['config']['cloud_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('cloud_upload', 0);
                        }
                        if ($wo['config']['amazone_s3'] == 1) {
                            $saveSetting = Wo_SaveConfig('amazone_s3', 0);
                        }
                        if ($wo['config']['spaces'] == 1) {
                            $saveSetting = Wo_SaveConfig('spaces', 0);
                        }
                        if ($wo['config']['backblaze_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('backblaze_storage', 0);
                        }
                        resetCache();
                    }
                }
                if ($key == 'backblaze_storage') {
                    if ($value == 1) {
                        if ($wo['config']['ftp_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('ftp_upload', 0);
                        }
                        if ($wo['config']['cloud_upload'] == 1) {
                            $saveSetting = Wo_SaveConfig('cloud_upload', 0);
                        }
                        if ($wo['config']['amazone_s3'] == 1) {
                            $saveSetting = Wo_SaveConfig('amazone_s3', 0);
                        }
                        if ($wo['config']['spaces'] == 1) {
                            $saveSetting = Wo_SaveConfig('spaces', 0);
                        }
                        if ($wo['config']['wasabi_storage'] == 1) {
                            $saveSetting = Wo_SaveConfig('wasabi_storage', 0);
                        }
                        resetCache();
                    }
                }
                if ($key == 'millicast_live_video') {
                    if ($value == 1) {
                        if ($wo['config']['agora_live_video'] == 1) {
                            $saveSetting = Wo_SaveConfig('agora_live_video', 0);
                        }
                        $saveSetting = Wo_SaveConfig('live_video', 1);
                    } else {
                        if ($wo['config']['agora_live_video'] != 1) {
                            $saveSetting = Wo_SaveConfig('live_video', 0);
                        }
                    }
                }
                if ($key == 'agora_live_video') {
                    if ($value == 1) {
                        if ($wo['config']['millicast_live_video'] == 1) {
                            $saveSetting = Wo_SaveConfig('millicast_live_video', 0);
                        }
                        $saveSetting = Wo_SaveConfig('live_video', 1);
                    } else {
                        if ($wo['config']['millicast_live_video'] != 1) {
                            $saveSetting = Wo_SaveConfig('live_video', 0);
                        }
                    }
                }
                if ($key == 'free_day_limit' && (!is_numeric($value) || $value < 1)) {
                    $value = 1000;
                }
                if ($key == 'pro_day_limit' && (!is_numeric($value) || $value < 1)) {
                    $value = 10000;
                }
                if ($key == 'smtp_password') {
                    if ($value === '') {
                        continue;
                    }
                    $encryptedSmtpPassword = openssl_encrypt($value, "AES-128-ECB", $siteEncryptKey);
                    $value = $encryptedSmtpPassword !== false ? '$Ap1_' . $encryptedSmtpPassword : openssl_encrypt($value, "AES-128-ECB", 'mysecretkey1234');
                }
                // if ($key == 'two_factor_type' && $wo['config']['two_factor_type'] != $value) {
                //     $db->where('two_factor_verified',1)->update(T_USERS,array('two_factor_email_verified' => 0,
                //                                                               'two_factor'          => 0));
                // }
                $saveSetting = Wo_SaveConfig($key, $value);
            }
        }
        if ($saveSetting === true) {
            if ($delete_follow_table == 1) {
                mysqli_query($sqlConnect, "DELETE FROM " . T_FOLLOWERS);
                mysqli_query($sqlConnect, "DELETE FROM " . T_NOTIFICATION . " WHERE type='following'");
                resetCache();
            }
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_ftp') {
        include_once('assets/libraries/ftp/vendor/autoload.php');
        try {
            $array = array(
                'upload/photos/d-avatar.jpg',
                $wo["userDefaultBlur"],
                'upload/photos/f-avatar.jpg',
                'upload/photos/d-cover.jpg',
                'upload/photos/d-group.jpg',
                'upload/photos/d-page.jpg',
                'upload/photos/d-blog.jpg',
                'upload/photos/game-icon.png',
                'upload/photos/d-film.jpg',
                'upload/photos/app-default-icon.png',
                'upload/photos/index.html',
                'upload/photos/incognito.png',
                'upload/.htaccess',
                'upload/files/2022/09/EAufYfaIkYQEsYzwvZha_01_4bafb7db09656e1ecb54d195b26be5c3_file.svg',
                'upload/files/2022/09/2MRRkhb7rDhUNuClfOfc_01_76c3c700064cfaef049d0bb983655cd4_file.svg',
                'upload/files/2022/09/D91CP5YFfv74GVAbYtT7_01_288940ae12acf0198d590acbf11efae0_file.svg',
                'upload/files/2022/09/cFNOXZB1XeWRSdXXEdlx_01_7d9c4adcbe750bfc8e864c69cbed3daf_file.svg',
                'upload/files/2022/09/yKmDaNA7DpA7RkCRdoM6_01_eb391ca40102606b78fef1eb70ce3c0f_file.svg',
                'upload/files/2022/09/iZcVfFlay3gkABhEhtVC_01_771d67d0b8ae8720f7775be3a0cfb51a_file.svg'
            );
            foreach ($array as $key => $value) {
                $upload = Wo_UploadToS3($value, array(
                    'delete' => 'no'
                ));
            }
            $data['status'] = 200;
        }
        catch (Exception $e) {
            $data['status']  = 400;
            $data['message'] = $e->getMessage();
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'auto_friend' && Wo_CheckMainSession($hash_id) === true) {
        if (!empty($_GET['users'])) {
            $save = Wo_SaveConfig('auto_friend_users', $_GET['users']);
            if ($save) {
                $data['status'] = 200;
            }
        } else {
            $save = Wo_SaveConfig('auto_friend_users', '');
            if ($save) {
                $data['status'] = 200;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'auto_page_like' && Wo_CheckMainSession($hash_id) === true) {
        if (!empty($_GET['users'])) {
            $save = Wo_SaveConfig('auto_page_like', $_GET['users']);
            if ($save) {
                $data['status'] = 200;
            }
        } else {
            $save = Wo_SaveConfig('auto_page_like', '');
            if ($save) {
                $data['status'] = 200;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'auto_group_like' && Wo_CheckMainSession($hash_id) === true) {
        if (!empty($_GET['users'])) {
            $save = Wo_SaveConfig('auto_group_join', $_GET['users']);
            if ($save) {
                $data['status'] = 200;
            }
        } else {
            $save = Wo_SaveConfig('auto_group_join', '');
            if ($save) {
                $data['status'] = 200;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'generate_fake_users') {
        require "assets/libraries/fake-users/vendor/autoload.php";
        $faker = Faker\Factory::create();
        if (empty($_POST['password'])) {
            $_POST['password'] = '123456789';
        }
        $count_users = $_POST['count_users'];
        $password    = $_POST['password'];
        $avatar      = $_POST['avatar'];
        ob_end_clean();
        header("Content-Encoding: none");
        header("Connection: close");
        ignore_user_abort();
        ob_start();
        header('Content-Type: application/json');
        echo json_encode(array(
            'status' => 200
        ));
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush();
        session_write_close();
        if (is_callable('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (is_callable('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        for ($i = 0; $i < $count_users; $i++) {
            $genders     = array_keys($wo['genders']);
            $random_keys = array_rand($genders, 1);
            $gender      = array_rand(array(
                "male",
                "female"
            ), 1);
            $gender      = $genders[$random_keys];
            $re_data     = array(
                'email' => Wo_Secure(str_replace(".", "_", $faker->userName) . '_' . rand(111, 999) . "@yahoo.com", 0),
                'username' => Wo_Secure(str_replace(".", "_", $faker->userName) . '_' . rand(111, 999), 0),
                'password' => Wo_Secure($password, 0),
                'email_code' => Wo_Secure(md5($faker->userName . '_' . rand(111, 999)), 0),
                'src' => 'Fake',
                'gender' => Wo_Secure($gender),
                'lastseen' => time(),
                'active' => 1,
                'first_name' => $faker->firstName($gender),
                'last_name' => $faker->lastName
            );
            if ($avatar == 1) {
                $urls = array(
                    "https://placeimg.com/" . $wo['profile_picture_width_crop'] . "/" . $wo['profile_picture_height_crop'] . "/people",
                    "https://loremflickr.com/" . $wo['profile_picture_width_crop'] . "/" . $wo['profile_picture_height_crop'],
                    "https://picsum.photos/" . $wo['profile_picture_width_crop'] . "/" . $wo['profile_picture_height_crop']
                );
                $rand = rand(0, 2);
                for ($ii = 0; $ii < 5; $ii++) {
                    $url = $urls[$rand];
                    $a   = Wo_ImportImageFromFile($url, '_url_image', 'avatar');
                    if (!empty($a)) {
                        $re_data['avatar'] = $a;
                        break;
                    }
                    $rand = rand(0, 2);
                }
            }
            $add_user = Wo_RegisterUser($re_data);
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_fake_users' && Wo_CheckMainSession($hash_id) === true) {
        ob_end_clean();
        header("Content-Encoding: none");
        header("Connection: close");
        ignore_user_abort();
        ob_start();
        header('Content-Type: application/json');
        echo json_encode(array(
            'status' => 200
        ));
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush();
        session_write_close();
        if (is_callable('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
        $query = mysqli_query($sqlConnect, "SELECT user_id FROM " . T_USERS . " WHERE src = 'Fake'");
        while ($row = mysqli_fetch_assoc($query)) {
            Wo_DeleteUser($row['user_id']);
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'auto_delete' && Wo_CheckMainSession($hash_id) === true) {
        if (!empty($_GET['delete'])) {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 200
            ));
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $delete_data = Wo_DeleteAllData($_GET['delete']);
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_wasabi') {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        try {
            $s3Client = S3Client::factory(array(
                'version' => 'latest',
                'endpoint' => 'https://s3.'.$wo['config']['wasabi_bucket_region'].'.wasabisys.com',
                'region' => $wo['config']['wasabi_bucket_region'],
                'credentials' => array(
                    'key' => $wo['config']['wasabi_access_key'],
                    'secret' => $wo['config']['wasabi_secret_key']
                )
            ));
            $buckets  = $s3Client->listBuckets();
            
            if (!empty($buckets)) {
                if ($s3Client->doesBucketExist($wo['config']['wasabi_bucket_name'])) {
                    $data['status'] = 200;
                    $array          = array(
                        'upload/photos/d-avatar.jpg',
                        $wo["userDefaultBlur"],
                        'upload/photos/f-avatar.jpg',
                        'upload/photos/d-cover.jpg',
                        'upload/photos/d-group.jpg',
                        'upload/photos/d-page.jpg',
                        'upload/photos/d-blog.jpg',
                        'upload/photos/game-icon.png',
                        'upload/photos/d-film.jpg',
                        'upload/photos/incognito.png',
                        'upload/photos/app-default-icon.png',
                        'upload/files/2022/09/EAufYfaIkYQEsYzwvZha_01_4bafb7db09656e1ecb54d195b26be5c3_file.svg',
                        'upload/files/2022/09/2MRRkhb7rDhUNuClfOfc_01_76c3c700064cfaef049d0bb983655cd4_file.svg',
                        'upload/files/2022/09/D91CP5YFfv74GVAbYtT7_01_288940ae12acf0198d590acbf11efae0_file.svg',
                        'upload/files/2022/09/cFNOXZB1XeWRSdXXEdlx_01_7d9c4adcbe750bfc8e864c69cbed3daf_file.svg',
                        'upload/files/2022/09/yKmDaNA7DpA7RkCRdoM6_01_eb391ca40102606b78fef1eb70ce3c0f_file.svg',
                        'upload/files/2022/09/iZcVfFlay3gkABhEhtVC_01_771d67d0b8ae8720f7775be3a0cfb51a_file.svg'
                    );
                    foreach ($array as $key => $value) {
                        $upload = Wo_UploadToS3($value, array(
                            'delete' => 'no'
                        ));
                    }
                } else {
                    $data['status'] = 300;
                }
            } else {
                $data['status'] = 500;
            }
        }
        catch (Exception $e) {
            $data['status']  = 400;
            $data['message'] = $e->getMessage();
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'save_cloudflare_r2') {
        $data = array('status' => 400, 'message' => 'Cloudflare R2 settings could not be saved.');
        $r2_admin_session_valid = Wo_CheckSession($hash_id) === true || Wo_CheckMainSession($hash_id) === true;
        if (!$r2_admin_session_valid) {
            http_response_code(403);
            $data = array('status' => 403, 'message' => 'Your admin session has expired. Reload the page and try again.');
        } else {
            $accountId = trim((string) ($_POST['cloudflare_r2_account_id'] ?? ''));
            $bucketName = trim((string) ($_POST['cloudflare_r2_bucket_name'] ?? ''));
            $accessKey = trim((string) ($_POST['cloudflare_r2_access_key'] ?? ''));
            $secretKey = trim((string) ($_POST['cloudflare_r2_secret_key'] ?? ''));
            $publicUrl = rtrim(trim((string) ($_POST['cloudflare_r2_public_url'] ?? '')), '/');
            $enabled = !empty($_POST['cloudflare_r2_storage']) ? 1 : 0;

            if ($accountId === '' || $bucketName === '' || $accessKey === '') {
                $data['message'] = 'Account ID, bucket name, and access key are required.';
            } elseif ($secretKey === '' && trim((string) ($wo['config']['cloudflare_r2_secret_key'] ?? '')) === '') {
                $data['message'] = 'The R2 secret access key is required.';
            } elseif (!filter_var($publicUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($publicUrl, PHP_URL_SCHEME)) !== 'https') {
                $data['message'] = 'Enter a valid HTTPS R2 public URL or custom domain.';
            } else {
                $settings = array(
                    'cloudflare_r2_account_id' => $accountId,
                    'cloudflare_r2_bucket_name' => $bucketName,
                    'cloudflare_r2_access_key' => $accessKey,
                    'cloudflare_r2_public_url' => $publicUrl
                );
                if ($secretKey !== '') {
                    $settings['cloudflare_r2_secret_key'] = Wo_EncryptConfigValue($secretKey);
                }

                $saved = true;
                foreach ($settings as $settingName => $settingValue) {
                    if (!Wo_SaveConfig($settingName, $settingValue)) {
                        $saved = false;
                        break;
                    }
                }
                if (!$saved) {
                    $data['message'] = 'The R2 configuration could not be written to the database.';
                } else {
                    $wo['config']['cloudflare_r2_account_id'] = $accountId;
                    $wo['config']['cloudflare_r2_bucket_name'] = $bucketName;
                    $wo['config']['cloudflare_r2_access_key'] = $accessKey;
                    $wo['config']['cloudflare_r2_public_url'] = $publicUrl;
                    if ($secretKey !== '') {
                        $wo['config']['cloudflare_r2_secret_key'] = $secretKey;
                    }

                    $probe = function_exists('Ramza_CloudflareR2Probe')
                        ? Ramza_CloudflareR2Probe()
                        : array('ok' => false, 'message' => 'The R2 verifier is unavailable.');
                    if (empty($probe['ok'])) {
                        Wo_SaveConfig('cloudflare_r2_storage', 0);
                        $data['message'] = (string) ($probe['message'] ?? 'R2 verification failed.');
                    } else {
                        Wo_SaveConfig('cloudflare_r2_storage', $enabled);
                        if ($enabled === 1) {
                            foreach (array('ftp_upload', 'amazone_s3', 'spaces', 'cloud_upload', 'wasabi_storage', 'backblaze_storage', 's3_compatible_storage') as $storageSwitch) {
                                Wo_SaveConfig($storageSwitch, 0);
                            }
                        }
                        resetCache();
                        $data = array(
                            'status' => 200,
                            'message' => $enabled === 1
                                ? 'Cloudflare R2 is saved, verified, and active for media uploads.'
                                : 'Cloudflare R2 is saved and verified. Enable it when you are ready.'
                        );
                    }
                }
            }
        }
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_cloudflare_r2') {
        if (empty($wo['config']['cloudflare_r2_storage'])) {
            $data['status'] = 400;
            $data['message'] = 'Enable Cloudflare R2 and save the settings first.';
        } else {
            $probe = function_exists('Ramza_CloudflareR2Probe')
                ? Ramza_CloudflareR2Probe()
                : array('ok' => false, 'message' => 'The R2 verifier is unavailable.');
            $data['status'] = !empty($probe['ok']) ? 200 : 400;
            $data['message'] = (string) ($probe['message'] ?? '');
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_s3_compatible') {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        $data['status'] = 404;
        if (empty($wo['config']['s3_compatible_storage']) || empty($wo['config']['s3_compatible_endpoint']) || empty($wo['config']['s3_compatible_bucket']) || empty($wo['config']['s3_compatible_access_key']) || empty($wo['config']['s3_compatible_secret_key'])) {
            $data['status'] = 400;
            $data['message'] = 'Please enable S3-compatible storage and fill endpoint, bucket, access key and secret key.';
        } else {
            try {
                $s3Client = S3Client::factory(array(
                    'version' => 'latest',
                    'region' => !empty($wo['config']['s3_compatible_region']) ? $wo['config']['s3_compatible_region'] : 'us-east-1',
                    'endpoint' => rtrim($wo['config']['s3_compatible_endpoint'], '/'),
                    'use_path_style_endpoint' => !empty($wo['config']['s3_compatible_path_style']),
                    'signature_version' => 'v4',
                    'credentials' => array(
                        'key' => $wo['config']['s3_compatible_access_key'],
                        'secret' => $wo['config']['s3_compatible_secret_key']
                    )
                ));
                if ($s3Client->doesBucketExist($wo['config']['s3_compatible_bucket'])) {
                    $data['status'] = 200;
                    $array = array(
                        'upload/photos/d-avatar.jpg',
                        $wo["userDefaultBlur"],
                        'upload/photos/f-avatar.jpg',
                        'upload/photos/d-cover.jpg',
                        'upload/photos/d-group.jpg',
                        'upload/photos/d-page.jpg',
                        'upload/photos/d-blog.jpg',
                        'upload/photos/game-icon.png',
                        'upload/photos/d-film.jpg',
                        'upload/photos/incognito.png',
                        'upload/photos/app-default-icon.png',
                        'upload/files/2022/09/EAufYfaIkYQEsYzwvZha_01_4bafb7db09656e1ecb54d195b26be5c3_file.svg',
                        'upload/files/2022/09/2MRRkhb7rDhUNuClfOfc_01_76c3c700064cfaef049d0bb983655cd4_file.svg',
                        'upload/files/2022/09/D91CP5YFfv74GVAbYtT7_01_288940ae12acf0198d590acbf11efae0_file.svg',
                        'upload/files/2022/09/cFNOXZB1XeWRSdXXEdlx_01_7d9c4adcbe750bfc8e864c69cbed3daf_file.svg',
                        'upload/files/2022/09/yKmDaNA7DpA7RkCRdoM6_01_eb391ca40102606b78fef1eb70ce3c0f_file.svg',
                        'upload/files/2022/09/iZcVfFlay3gkABhEhtVC_01_771d67d0b8ae8720f7775be3a0cfb51a_file.svg'
                    );
                    foreach ($array as $key => $value) {
                        $upload = Wo_UploadToS3($value, array(
                            'delete' => 'no',
                            's3_compatible' => 'yes'
                        ));
                    }
                } else {
                    $data['status'] = 300;
                }
            }
            catch (Exception $e) {
                $data['status'] = 400;
                $data['message'] = $e->getMessage();
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_s3') {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        try {
            $s3Client = S3Client::factory(array(
                'version' => 'latest',
                'region' => $wo['config']['region'],
                'credentials' => array(
                    'key' => $wo['config']['amazone_s3_key'],
                    'secret' => $wo['config']['amazone_s3_s_key']
                )
            ));
            $buckets  = $s3Client->listBuckets();
            $result   = $s3Client->putBucketCors(array(
                'Bucket' => $wo['config']['bucket_name'], // REQUIRED
                'CORSConfiguration' => array( // REQUIRED
                    'CORSRules' => array( // REQUIRED
                        array(
                            'AllowedHeaders' => array(
                                'Authorization'
                            ),
                            'AllowedMethods' => array(
                                'POST',
                                'GET',
                                'PUT'
                            ), // REQUIRED
                            'AllowedOrigins' => array(
                                '*'
                            ), // REQUIRED
                            'ExposeHeaders' => array(),
                            'MaxAgeSeconds' => 3000
                        )
                    )
                )
            ));
            if (!empty($buckets)) {
                if ($s3Client->doesBucketExist($wo['config']['bucket_name'])) {
                    $data['status'] = 200;
                    $array          = array(
                        'upload/photos/d-avatar.jpg',
                        $wo["userDefaultBlur"],
                        'upload/photos/f-avatar.jpg',
                        'upload/photos/d-cover.jpg',
                        'upload/photos/d-group.jpg',
                        'upload/photos/d-page.jpg',
                        'upload/photos/d-blog.jpg',
                        'upload/photos/game-icon.png',
                        'upload/photos/d-film.jpg',
                        'upload/photos/incognito.png',
                        'upload/photos/app-default-icon.png',
                        'upload/files/2022/09/EAufYfaIkYQEsYzwvZha_01_4bafb7db09656e1ecb54d195b26be5c3_file.svg',
                        'upload/files/2022/09/2MRRkhb7rDhUNuClfOfc_01_76c3c700064cfaef049d0bb983655cd4_file.svg',
                        'upload/files/2022/09/D91CP5YFfv74GVAbYtT7_01_288940ae12acf0198d590acbf11efae0_file.svg',
                        'upload/files/2022/09/cFNOXZB1XeWRSdXXEdlx_01_7d9c4adcbe750bfc8e864c69cbed3daf_file.svg',
                        'upload/files/2022/09/yKmDaNA7DpA7RkCRdoM6_01_eb391ca40102606b78fef1eb70ce3c0f_file.svg',
                        'upload/files/2022/09/iZcVfFlay3gkABhEhtVC_01_771d67d0b8ae8720f7775be3a0cfb51a_file.svg'
                    );
                    foreach ($array as $key => $value) {
                        $upload = Wo_UploadToS3($value, array(
                            'delete' => 'no'
                        ));
                    }
                } else {
                    $data['status'] = 300;
                }
            } else {
                $data['status'] = 500;
            }
        }
        catch (Throwable $e) {
            $data['status']  = 400;
            $data['message'] = $e->getMessage();
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_s3_2') {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        try {
            $s3Client = S3Client::factory(array(
                'version' => 'latest',
                'region' => $wo['config']['region_2'],
                'credentials' => array(
                    'key' => $wo['config']['amazone_s3_key_2'],
                    'secret' => $wo['config']['amazone_s3_s_key_2']
                )
            ));
            $buckets  = $s3Client->listBuckets();
            $result   = $s3Client->putBucketCors(array(
                'Bucket' => $wo['config']['bucket_name_2'], // REQUIRED
                'CORSConfiguration' => array( // REQUIRED
                    'CORSRules' => array( // REQUIRED
                        array(
                            'AllowedHeaders' => array(
                                'Authorization'
                            ),
                            'AllowedMethods' => array(
                                'POST',
                                'GET',
                                'PUT'
                            ), // REQUIRED
                            'AllowedOrigins' => array(
                                '*'
                            ), // REQUIRED
                            'ExposeHeaders' => array(),
                            'MaxAgeSeconds' => 3000
                        )
                    )
                )
            ));
            if (!empty($buckets)) {
                if ($s3Client->doesBucketExist($wo['config']['bucket_name_2'])) {
                    $data['status'] = 200;
                } else {
                    $data['status'] = 300;
                }
            } else {
                $data['status'] = 500;
            }
        }
        catch (Exception $e) {
            $data['status']  = 400;
            $data['message'] = $e->getMessage();
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_spaces') {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        $key        = $wo['config']['spaces_key'];
        $secret     = $wo['config']['spaces_secret'];
        $spaceName = $wo['config']['space_name'];
        $region     = $wo['config']['space_region'];
        $host = "digitaloceanspaces.com";
        try {
            // if(!empty($spaceName)) {
            //   $endpoint = "https://".$spaceName.".".$region.".".$host;
            // }
            // else {
              $endpoint = "https://".$region.".".$host;
            // }
            $s3Client = S3Client::factory(array(
            'region' => $region,
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => array(
                      'key'    => $key,
                      'secret' => $secret,
                  ),
            'bucket_endpoint' => true,
          ));
            $buckets  = $s3Client->listBuckets();
            if (!empty($buckets)) {
                $exists = 0;
                foreach ($buckets->toArray()['Buckets'] as $key => $value) {
                    if ($value['Name'] == $wo['config']['space_name']) {
                        $exists = 1;
                        break;
                    }
                }
                
                if ($exists) {
                    $data['status'] = 200;
                    $array          = array(
                        'upload/photos/d-avatar.jpg',
                        $wo["userDefaultBlur"],
                        'upload/photos/f-avatar.jpg',
                        'upload/photos/d-cover.jpg',
                        'upload/photos/d-group.jpg',
                        'upload/photos/d-page.jpg',
                        'upload/photos/d-blog.jpg',
                        'upload/photos/game-icon.png',
                        'upload/photos/d-film.jpg',
                        'upload/photos/incognito.png',
                        'upload/photos/app-default-icon.png',
                        'upload/files/2022/09/EAufYfaIkYQEsYzwvZha_01_4bafb7db09656e1ecb54d195b26be5c3_file.svg',
                        'upload/files/2022/09/2MRRkhb7rDhUNuClfOfc_01_76c3c700064cfaef049d0bb983655cd4_file.svg',
                        'upload/files/2022/09/D91CP5YFfv74GVAbYtT7_01_288940ae12acf0198d590acbf11efae0_file.svg',
                        'upload/files/2022/09/cFNOXZB1XeWRSdXXEdlx_01_7d9c4adcbe750bfc8e864c69cbed3daf_file.svg',
                        'upload/files/2022/09/yKmDaNA7DpA7RkCRdoM6_01_eb391ca40102606b78fef1eb70ce3c0f_file.svg',
                        'upload/files/2022/09/iZcVfFlay3gkABhEhtVC_01_771d67d0b8ae8720f7775be3a0cfb51a_file.svg'
                    );
                    foreach ($array as $key => $value) {
                        $upload = Wo_UploadToS3($value, array(
                            'delete' => 'no'
                        ));
                    }
                } else {
                    $data['status'] = 300;
                }
            } else {
                $data['status'] = 500;
            }
        }
        catch (Exception $e) {
            $data['status']  = 400;
            $data['message'] = $e->getMessage();
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_terms_setting') {
        if (!empty($_POST['lang_key'])) {
            $lang_key = Wo_Secure($_POST['lang_key']);
            $langs    = Wo_LangsNamesFromDB();
            foreach ($_POST as $key => $value) {
                if (in_array($key, $langs)) {
                    $key   = Wo_Secure($key);
                    //$value = base64_decode($value);
                    $value = mysqli_real_escape_string($sqlConnect, $value);
                    $query = mysqli_query($sqlConnect, "UPDATE " . T_LANGS . " SET `{$key}` = '{$value}' WHERE `lang_key` = '{$lang_key}'");
                    if ($query) {
                        $data['status'] = 200;
                    }
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_html_emails') {
        header('Content-Type: application/json; charset=UTF-8');
        if (Wo_CheckSession($hash_id) !== true) {
            echo json_encode(array('status' => 403, 'message' => 'Your admin session has expired. Reload the page and try again.'));
            exit();
        }
        if (function_exists('Ramza_IsDemoMode') && Ramza_IsDemoMode()) {
            echo json_encode(array('status' => 403, 'message' => 'Email templates cannot be changed in demo mode.'));
            exit();
        }

        $templateKeys = array(
            'activate',
            'invite',
            'login_with',
            'notification',
            'payment_declined',
            'payment_approved',
            'recover',
            'unusual_login',
            'account_deleted'
        );
        $submitted = 0;
        $failed = array();
        $templateEncoding = (string) ($_POST['template_encoding'] ?? 'plain');
        if (!in_array($templateEncoding, array('plain', 'base64'), true)) {
            echo json_encode(array('status' => 400, 'message' => 'Unsupported email-template encoding.'));
            exit();
        }
        mysqli_begin_transaction($sqlConnect);
        try {
            foreach ($templateKeys as $templateKey) {
                if (!array_key_exists($templateKey, $_POST)) {
                    continue;
                }
                $templateValue = (string) $_POST[$templateKey];
                if ($templateEncoding === 'base64') {
                    if (strlen($templateValue) > 2800000) {
                        throw new RuntimeException('The ' . str_replace('_', ' ', $templateKey) . ' template is too large.');
                    }
                    $decodedTemplate = base64_decode($templateValue, true);
                    if ($decodedTemplate === false) {
                        throw new RuntimeException('The ' . str_replace('_', ' ', $templateKey) . ' template could not be decoded.');
                    }
                    $templateValue = $decodedTemplate;
                }
                if (strlen($templateValue) > 2000000) {
                    throw new RuntimeException('The ' . str_replace('_', ' ', $templateKey) . ' template is too large.');
                }
                $submitted++;
                if (!Wo_SaveHTMLEmails($templateKey, $templateValue)) {
                    $failed[] = $templateKey;
                }
            }
            if ($submitted === 0) {
                throw new RuntimeException('No email templates were received.');
            }
            if (!empty($failed)) {
                throw new RuntimeException('Could not save: ' . implode(', ', $failed) . '.');
            }
            mysqli_commit($sqlConnect);
            echo json_encode(array('status' => 200, 'message' => 'Email templates saved successfully.'));
        } catch (Throwable $error) {
            mysqli_rollback($sqlConnect);
            error_log('Ramza email-template save failed: ' . preg_replace('/[\r\n]+/', ' ', $error->getMessage()));
            echo json_encode(array('status' => 400, 'message' => $error->getMessage()));
        }
        exit();
    }
    if ($s == 'email_debug') {
        if (!Wo_IsAdmin() || Wo_CheckSession($hash_id) !== true) {
            header("Content-type: text/plain; charset=UTF-8");
            echo 'Your admin session has expired. Reload the page and try again.';
            exit();
        }
        $send_message_data = array(
            'from_email' => $wo['config']['siteEmail'],
            'from_name' => $wo['config']['siteName'],
            'to_email' => $wo['user']['email'],
            'to_name' => $wo['user']['name'],
            'subject' => 'Test Message From ' . $wo['config']['siteName'],
            'charSet' => 'utf-8',
            'message_body' => 'If you can see this message, then your SMTP configuration is working fine.',
            'is_html' => false,
            'return' => 'debug',
        );
        $send_message      = Wo_SendMessage($send_message_data);
        
        header("Content-type: application/json");
        exit();
    }
    if ($s == 'save_test_message') {
        header("Content-type: application/json");
        if (!Wo_IsAdmin() || Wo_CheckSession($hash_id) !== true) {
            echo json_encode(array('status' => 403, 'error' => 'Your admin session has expired. Reload the page and try again.'));
            exit();
        }
        if (function_exists('Ramza_IsDemoMode') && Ramza_IsDemoMode()) {
            echo json_encode(array('status' => 403, 'error' => 'Email settings are locked in demo mode.'));
            exit();
        }

        $mailSettings = array(
            'smtp_or_mail' => strtolower(trim((string)($_POST['smtp_or_mail'] ?? ''))),
            'siteEmail' => trim((string)($_POST['siteEmail'] ?? '')),
            'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
            'smtp_username' => trim((string)($_POST['smtp_username'] ?? '')),
            'smtp_port' => (int)($_POST['smtp_port'] ?? 0),
            'smtp_encryption' => strtolower(trim((string)($_POST['smtp_encryption'] ?? ''))),
        );
        if (!in_array($mailSettings['smtp_or_mail'], array('smtp', 'mail'), true) || !filter_var($mailSettings['siteEmail'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(array('status' => 400, 'error' => 'Enter a valid email server and sender address.'));
            exit();
        }
        if ($mailSettings['smtp_or_mail'] === 'smtp' && ($mailSettings['smtp_host'] === '' || $mailSettings['smtp_port'] < 1 || $mailSettings['smtp_port'] > 65535 || !in_array($mailSettings['smtp_encryption'], array('tls', 'ssl'), true))) {
            echo json_encode(array('status' => 400, 'error' => 'Enter a valid SMTP host, port, and encryption method.'));
            exit();
        }
        foreach ($mailSettings as $mailKey => $mailValue) {
            Wo_SaveConfig($mailKey, $mailValue);
            $wo['config'][$mailKey] = $mailValue;
        }
        $smtpPassword = (string)($_POST['smtp_password'] ?? '');
        if ($smtpPassword !== '') {
            $encryptedSmtpPassword = openssl_encrypt($smtpPassword, "AES-128-ECB", $siteEncryptKey);
            if ($encryptedSmtpPassword === false) {
                echo json_encode(array('status' => 500, 'error' => 'The SMTP password could not be protected.'));
                exit();
            }
            $storedSmtpPassword = '$Ap1_' . $encryptedSmtpPassword;
            Wo_SaveConfig('smtp_password', $storedSmtpPassword);
            $wo['config']['smtp_password'] = $storedSmtpPassword;
        }

        $send_message_data = array(
            'from_email' => $wo['config']['siteEmail'],
            'from_name' => $wo['config']['siteName'],
            'to_email' => $wo['user']['email'],
            'to_name' => $wo['user']['name'],
            'subject' => 'Test Message From ' . $wo['config']['siteName'],
            'charSet' => 'utf-8',
            'message_body' => 'If you can see this message, your email delivery configuration is working.',
            'is_html' => false,
            'return' => 'error',
        );
        $send_message = Wo_SendMessage($send_message_data);
        if ($send_message === true) {
            echo json_encode(array('status' => 200, 'message' => 'Settings saved and test email delivered.'));
        } else {
            echo json_encode(array('status' => 400, 'error' => (string)$send_message));
        }
        exit();
    }
    if ($s == 'test_message') {
        if (!Wo_IsAdmin() || Wo_CheckSession($hash_id) !== true) {
            header("Content-type: application/json");
            echo json_encode(array('status' => 403, 'error' => 'Your admin session has expired. Reload the page and try again.'));
            exit();
        }
        $send_message_data = array(
            'from_email' => $wo['config']['siteEmail'],
            'from_name' => $wo['config']['siteName'],
            'to_email' => $wo['user']['email'],
            'to_name' => $wo['user']['name'],
            'subject' => 'Test Message From ' . $wo['config']['siteName'],
            'charSet' => 'utf-8',
            'message_body' => 'If you can see this message, then your SMTP configuration is working fine.',
            'is_html' => false,
            'return' => 'error',
        );
        $send_message      = Wo_SendMessage($send_message_data);
        if ($send_message === true) {
            $data['status'] = 200;
        } else {
            $data['status'] = 400;
            if (!empty($send_message)) {
                $data['error']  = $send_message;
            }
            else{
                $data['error']  = "Error found while sending the email, the information you provided are not correct, please test the email settings on your local device and make sure they are correct. ";
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_sms_setting') {
        $saveSetting = false;
        foreach ($_POST as $key => $value) {
            if ($key != 'hash_id') {
                $saveSetting = Wo_SaveConfig($key, $value);
            }
        }
        if ($saveSetting === true) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_sms_message') {
        $message      = 'This is a test message from ' . $wo['config']['siteName'];
        $send_message = Wo_SendSMSMessage($wo['config']['sms_phone_number'], $message);
        if ($send_message === true) {
            $data['status'] = 200;
        } else {
            $data['status'] = 400;
            $data['error']  = $send_message;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_design_setting') {
        $saveSetting = false;
        if (isset($_FILES['logo']['name'])) {
            $fileInfo = array(
                'file' => $_FILES["logo"]["tmp_name"],
                'name' => $_FILES['logo']['name'],
                'size' => $_FILES["logo"]["size"]
            );
            $media    = Wo_UploadLogo($fileInfo);
        }
        if (isset($_FILES['night_logo']['name'])) {
            $fileInfo = array(
                'file' => $_FILES["night_logo"]["tmp_name"],
                'name' => $_FILES['night_logo']['name'],
                'size' => $_FILES["night_logo"]["size"]
            );
            $media    = Wo_UploadNightLogo($fileInfo);
        }
        if (isset($_FILES['background']['name'])) {
            $fileInfo = array(
                'file' => $_FILES["background"]["tmp_name"],
                'name' => $_FILES['background']['name'],
                'size' => $_FILES["background"]["size"]
            );
            $media    = Wo_UploadBackground($fileInfo);
        }
        if (isset($_FILES['favicon']['name'])) {
            $fileInfo = array(
                'file' => $_FILES["favicon"]["tmp_name"],
                'name' => $_FILES['favicon']['name'],
                'size' => $_FILES["favicon"]["size"]
            );
            $media    = Wo_UploadFavicon($fileInfo);
        }
        foreach ($_POST as $key => $value) {
            if ($key != 'hash_id') {
                $saveSetting = Wo_SaveConfig($key, $value);
            }
        }
        if ($saveSetting === true) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'updateTheme' && isset($_POST['theme'])) {
        $_SESSION['theme'] = '';
        $selectedTheme = Wo_Secure($_POST['theme'], 0);
        if ($selectedTheme !== 'ramza-light') {
            $data['status'] = 400;
            $data['message'] = 'Ramza New is coming soon and cannot be enabled yet.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
        $saveSetting = Wo_SaveConfig('theme', $selectedTheme);
        if ($saveSetting === true) {
            $data['status'] = 200;
        }
        $files = glob('cache/*'); // get all file names
        foreach ($files as $file) { // iterate files
            if (is_file($file))
                unlink($file); // delete file
        }
        if (!file_exists('cache/index.html')) {
            $f = @fopen("cache/index.html", "a+");
            @fclose($f);
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_user' && isset($_GET['user_id']) && Wo_CheckMainSession($hash_id) === true) {
        if (Wo_DeleteUser($_GET['user_id']) === true) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_job' && isset($_POST['job_id'])) {
        $job_id = Wo_Secure($_POST['job_id']);
        $job    = $db->where('id', $job_id)->getOne(T_JOB);
        if (!empty($job)) {
            if ($job->image_type != 'cover') {
                @unlink($job->image);
                Wo_DeleteFromToS3($job->image);
            }
        }
        $db->where('id', $job_id)->delete(T_JOB);
        $db->where('job_id', $job_id)->delete(T_JOB_APPLY);
        $post = $db->where('job_id', $job_id)->getOne(T_POSTS);
        if (!empty($post)) {
            Wo_DeletePost($post->id);
        }
        $data['status'] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_offer' && isset($_POST['offer_id'])) {
        $offer_id = Wo_Secure($_POST['offer_id']);
        $offer    = $db->where('id', $offer_id)->getOne(T_OFFER);
        if (!empty($offer)) {
            if (!empty($offer->image)) {
                @unlink($offer->image);
                Wo_DeleteFromToS3($offer->image);
            }
        }
        $db->where('id', $offer_id)->delete(T_OFFER);
        $data['status'] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_user_page' && isset($_GET['page_id'])) {
        if (Wo_DeletePage($_GET['page_id']) === true) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_group' && isset($_GET['group_id'])) {
        if (Wo_DeleteGroup($_GET['group_id']) === true) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'filter_all_users') {
        $html  = '';
        $after = (isset($_GET['after_user_id']) && is_numeric($_GET['after_user_id']) && $_GET['after_user_id'] > 0) ? $_GET['after_user_id'] : 0;
        foreach (Wo_GetAllUsers(20, 'ManageUsers', $_POST, $after) as $wo['userlist']) {
            $html .= Wo_LoadAdminPage('manage-users/list');
        }
        $data = array(
            'status' => 200,
            'html' => $html
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_more_pages') {
        $html  = '';
        $after = (isset($_GET['after_page_id']) && is_numeric($_GET['after_page_id']) && $_GET['after_page_id'] > 0) ? $_GET['after_page_id'] : 0;
        foreach (Wo_GetAllPages(20, $after) as $wo['pagelist']) {
            $html .= Wo_LoadAdminPage('manage-pages/list');
            ;
        }
        $data = array(
            'status' => 200,
            'html' => $html
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_more_groups') {
        $html  = '';
        $after = (isset($_GET['after_group_id']) && is_numeric($_GET['after_group_id']) && $_GET['after_group_id'] > 0) ? $_GET['after_group_id'] : 0;
        foreach (Wo_GetAllGroups(20, $after) as $wo['grouplist']) {
            $html .= Wo_LoadAdminPage('manage-groups/list');
            ;
        }
        $data = array(
            'status' => 200,
            'html' => $html
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_users_setting' && isset($_POST['user_lastseen'])) {
        $delete_follow_table = 0;
        $saveSetting         = false;
        foreach ($_POST as $key => $value) {
            $saveSetting = Wo_SaveConfig($key, $value);
        }
        if ($saveSetting === true) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_more_posts') {
        $html      = '';
        $postsData = array(
            'limit' => 10,
            'after_post_id' => Wo_Secure($_GET['after_post_id'])
        );
        foreach (Wo_GetAllPosts($postsData) as $wo['story']) {
            $html .= Wo_LoadAdminPage('manage-posts/list');
        }
        $data = array(
            'status' => 200,
            'html' => $html
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_fund' && Wo_CheckSession($hash_id) === true) {
        if (!empty($_POST['fund_id'])) {
            $id   = Wo_Secure($_POST['fund_id']);
            $fund = $db->where('id', $id)->getOne(T_FUNDING);
            if (!empty($fund)) {
                @Wo_DeleteFromToS3($fund->image);
                if (file_exists($fund->image)) {
                    try {
                        unlink($fund->image);
                    }
                    catch (Exception $e) {
                    }
                }
                $db->where('id', $id)->delete(T_FUNDING);
                $raise = $db->where('funding_id', $id)->get(T_FUNDING_RAISE);
                $db->where('funding_id', $id)->delete(T_FUNDING_RAISE);
                $posts = $db->where('fund_id', $id)->get(T_POSTS);
                if (!empty($posts)) {
                    foreach ($posts as $key => $value) {
                        $db->where('parent_id', $value->id)->delete(T_POSTS);
                    }
                }
                $db->where('fund_id', $id)->delete(T_POSTS);
                foreach ($raise as $key => $value) {
                    $raise_posts = $db->where('fund_raise_id', $value->id)->get(T_POSTS);
                    if (!empty($raise_posts)) {
                        foreach ($posts as $key => $value1) {
                            $db->where('parent_id', $value1->id)->delete(T_POSTS);
                        }
                    }
                    $db->where('fund_raise_id', $value->id)->delete(T_POSTS);
                }
                $data['status'] = 200;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_post' && Wo_CheckSession($hash_id) === true) {
        if (!empty($_POST['post_id'])) {
            if (Wo_DeletePost($_POST['post_id'])) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_reported_content' && (Wo_IsAdmin() || Wo_IsModerator())) {
        if (!empty($_GET['id']) && !empty($_GET['type']) && !empty($_GET['report_id'])) {
            $type   = Wo_Secure($_GET['type']);
            $id     = Wo_Secure($_GET['id']);
            $report = Wo_Secure($_GET['report_id']);
            if ($type == 'post' && Wo_DeletePost($id) === true) {
                $deleteReport = Wo_DeleteReport($report);
                if ($deleteReport === true) {
                    $data = array(
                        'status' => 200,
                        'html' => Wo_CountUnseenReports()
                    );
                }
            }
            if ($type == 'user' && Wo_DeleteUser($id) === true) {
                $deleteReport = Wo_DeleteReport($report);
                if ($deleteReport === true) {
                    $data = array(
                        'status' => 200,
                        'html' => Wo_CountUnseenReports()
                    );
                }
            }
            if ($type == 'page' && Wo_DeletePage($id) === true) {
                $deleteReport = Wo_DeleteReport($report);
                if ($deleteReport === true) {
                    $data = array(
                        'status' => 200,
                        'html' => Wo_CountUnseenReports()
                    );
                }
            }
            if ($type == 'group' && Wo_DeleteGroup($id) === true) {
                $deleteReport = Wo_DeleteReport($report);
                if ($deleteReport === true) {
                    $data = array(
                        'status' => 200,
                        'html' => Wo_CountUnseenReports()
                    );
                }
            }
            if ($type == 'comment' && Wo_DeletePostComment($id) === true) {
                $deleteReport = Wo_DeleteReport($report);
                if ($deleteReport === true) {
                    $data = array(
                        'status' => 200,
                        'html' => Wo_CountUnseenReports()
                    );
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'mark_as_safe') {
        if (!empty($_GET['report_id'])) {
            $deleteReport = Wo_DeleteReport($_GET['report_id']);
            if ($deleteReport === true) {
                $data = array(
                    'status' => 200,
                    'html' => Wo_CountUnseenReports()
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_verification') {
        if (!empty($_GET['id'])) {
            if (Wo_DeleteVerificationRequest($_GET['id']) === true) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_game') {
        if (!empty($_GET['game_id'])) {
            if (Wo_DeleteGame($_GET['game_id']) === true) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_gift') {
        if (!empty($_GET['gift_id'])) {
            if (Wo_DeleteGift($_GET['gift_id']) === true) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_sticker') {
        if (!empty($_GET['sticker_id'])) {
            if (Wo_DeleteSticker($_GET['sticker_id']) === true) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'verify_user' && Wo_CheckMainSession($hash_id) === true) {
        if (!empty($_GET['id'])) {
            $type = '';
            if (!empty($_GET['type'])) {
                $type = $_GET['type'];
            }
            if (Wo_VerifyUser($_GET['id'], $_GET['verification_id'], $type) === true) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'send_mail_to_all_users') {
        $isset_test = 'off';
        if (empty($_POST['message']) || empty($_POST['subject'])) {
            $send_errors = $error_icon . $wo['lang']['please_check_details'];
        } else {
            if (!empty($_POST['test_message'])) {
                if ($_POST['test_message'] == 'on') {
                    $isset_test = 'on';
                }
            }
            if ($isset_test == 'on') {
                $send_message_data = array(
                    'from_email' => $wo['config']['siteEmail'],
                    'from_name' => $wo['config']['siteName'],
                    'to_email' => $wo['user']['email'],
                    'to_name' => $wo['user']['name'],
                    'subject' => $_POST['subject'],
                    'charSet' => 'utf-8',
                    'message_body' => $_POST['message'],
                    'is_html' => true
                );
                $send              = Wo_SendMessage($send_message_data);
            } else {
                $users_type = 'all';
                $users      = array();
                if (isset($_POST['selected_emails']) && strlen($_POST['selected_emails']) > 0) {
                    $user_ids = explode(',', $_POST['selected_emails']);
                    if (is_array($user_ids) && count($user_ids) > 0) {
                        foreach ($user_ids as $user_id) {
                            $users[] = Wo_UserData($user_id);
                        }
                    }
                } else if ($_POST['send_to'] == 'active') {
                    $users = Wo_GetAllUsersByType('active');
                } else if ($_POST['send_to'] == 'inactive') {
                    $users = Wo_GetAllUsersByType('inactive');
                }
                ob_end_clean();
                header("Content-Encoding: none");
                header("Connection: close");
                ignore_user_abort();
                ob_start();
                header('Content-Type: application/json');
                echo json_encode(array(
                    'status' => 300
                ));
                $size = ob_get_length();
                header("Content-Length: $size");
                ob_end_flush();
                flush();
                session_write_close();
                if (is_callable('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
                foreach ($users as $user) {
                    $send_message_data = array(
                        'from_email' => $wo['config']['siteEmail'],
                        'from_name' => $wo['config']['siteName'],
                        'to_email' => $user['email'],
                        'to_name' => $user['name'],
                        'subject' => $_POST['subject'],
                        'charSet' => 'utf-8',
                        'message_body' => $_POST['message'],
                        'is_html' => true
                    );
                    $send              = Wo_SendMessage($send_message_data);
                    $mail->ClearAddresses();
                }
            }
        }
        header("Content-type: application/json");
        if (!empty($send_errors)) {
            $send_errors_data = array(
                'status' => 400,
                'message' => $send_errors
            );
            echo json_encode($send_errors_data);
        } else {
            $data = array(
                'status' => 200
            );
            echo json_encode($data);
        }
        exit();
    }
    if ($s == 'send_mail_to_mock_users') {
        $isset_test = 'off';
        $types      = array(
            'week',
            'month',
            '3month',
            '6month',
            '9month',
            'year',
            'all',
            'active',
            'inactive',
        );
        if (empty($_POST['message']) || empty($_POST['subject']) || empty($_POST['send_to']) || !in_array($_POST['send_to'], $types)) {
            $send_errors = $error_icon . $wo['lang']['please_check_details'];
        } else {
            if (!empty($_POST['test_message'])) {
                if ($_POST['test_message'] == 'on') {
                    $isset_test = 'on';
                }
            }
            if ($isset_test == 'on') {
                $send_message_data = array(
                    'from_email' => $wo['config']['siteEmail'],
                    'from_name' => $wo['config']['siteName'],
                    'to_email' => $wo['user']['email'],
                    'to_name' => $wo['user']['name'],
                    'subject' => $_POST['subject'],
                    'charSet' => 'utf-8',
                    'message_body' => $_POST['message'],
                    'is_html' => true
                );
                $send              = Wo_SendMessage($send_message_data);
            } else {
                $users = array();
                if (isset($_POST['selected_emails']) && strlen($_POST['selected_emails']) > 0) {
                    $user_ids = explode(',', $_POST['selected_emails']);
                    if (is_array($user_ids) && count($user_ids) > 0) {
                        foreach ($user_ids as $user_id) {
                            $users[] = Wo_UserData($user_id);
                        }
                    }
                }
                 else if ($_POST['send_to'] == 'active') {
                    $users = Wo_GetAllUsersByType('active');
                } else if ($_POST['send_to'] == 'inactive') {
                    $users = Wo_GetAllUsersByType('inactive');
                } else if ($_POST['send_to'] == 'all') {
                    $users = Wo_GetAllUsersByType('all');
                } else {
                    $users = Wo_GetUsersByTime($_POST['send_to']);
                }
                ob_end_clean();
                header("Content-Encoding: none");
                header("Connection: close");
                ignore_user_abort();
                ob_start();
                header('Content-Type: application/json');
                echo json_encode(array(
                    'status' => 300
                ));
                $size = ob_get_length();
                header("Content-Length: $size");
                ob_end_flush();
                flush();
                session_write_close();
                if (is_callable('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                if (is_callable('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                foreach ($users as $user) {
                    $send_message_data = array(
                        'from_email' => $wo['config']['siteEmail'],
                        'from_name' => $wo['config']['siteName'],
                        'to_email' => $user['email'],
                        'to_name' => $user['name'],
                        'subject' => $_POST['subject'],
                        'charSet' => 'utf-8',
                        'message_body' => $_POST['message'],
                        'is_html' => true
                    );
                    $send              = Wo_SendMessage($send_message_data);
                }
            }
        }
        header("Content-type: application/json");
        if (!empty($send_errors)) {
            $send_errors_data = array(
                'status' => 400,
                'message' => $send_errors
            );
            echo json_encode($send_errors_data);
        } else {
            $data = array(
                'status' => 200
            );
            echo json_encode($data);
        }
        exit();
    }
    if ($s == 'get_users_emails' && isset($_GET['name'])) {
        $html  = '';
        $data  = array(
            'status' => 404
        );
        if (!empty($_GET['name'])) {
            $name  = Wo_Secure($_GET['name']);
            $users = Wo_GetUsersByName($name, false, 20);
            if (count($users) > 0) {
                foreach ($users as $user) {
                    $html .= "<p data-user='" . $user['user_id'] . "'>" . $user['username'] . "</p>";
                }
                $data['status'] = 200;
                $data['html']   = $html;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_announcement') {
        if (!empty($_POST['announcement_text'])) {
            $html = '';
            $id   = Wo_AddNewAnnouncement(base64_decode($_POST['announcement_text']));
            if ($id > 0) {
                $wo['activeAnnouncement'] = Wo_GetAnnouncement($id);
                $html .= Wo_LoadAdminPage('manage-announcements/active-list');
                $data = array(
                    'status' => 200,
                    'text' => $html
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_announcement') {
        if (!empty($_GET['id'])) {
            $DeleteAnnouncement = Wo_DeleteAnnouncement($_GET['id']);
            if ($DeleteAnnouncement === true) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'disable_announcement') {
        if (!empty($_GET['id'])) {
            $html                = '';
            $DisableAnnouncement = Wo_DisableAnnouncement($_GET['id']);
            if ($DisableAnnouncement === true) {
                $wo['inactiveAnnouncement'] = Wo_GetAnnouncement($_GET['id']);
                $html .= Wo_LoadAdminPage('manage-announcements/inactive-list');
                $data = array(
                    'status' => 200,
                    'html' => $html
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'activate_announcement') {
        if (!empty($_GET['id'])) {
            $html                 = '';
            $ActivateAnnouncement = Wo_ActivateAnnouncement($_GET['id']);
            if ($ActivateAnnouncement === true) {
                $wo['activeAnnouncement'] = Wo_GetAnnouncement($_GET['id']);
                $html .= Wo_LoadAdminPage('manage-announcements/active-list');
                $data = array(
                    'status' => 200,
                    'html' => $html
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_ads') {
        $updated = false;
        foreach ($_POST as $key => $ads) {
            if ($key != 'hash_id') {
                $ad_data = array(
                    'type' => $key,
                    'code' => base64_decode($ads),
                    'active' => (empty($ads)) ? 0 : 1
                );
                $update  = Wo_UpdateAdsCode($ad_data);
                if ($update) {
                    $updated = true;
                }
            }
        }
        if ($updated == true) {
            $data = array(
                'status' => 200
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_ads_status') {
        if (!empty($_GET['type'])) {
            if (Wo_UpdateAdActivation($_GET['type']) == 'active') {
                $data = array(
                    'status' => 200
                );
            } else {
                $data = array(
                    'status' => 300
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_reaction') {
        $data = array('status' => 400, 'message' => 'Enter a name and choose an icon.');
        if (Wo_CheckMainSession($hash_id) === true) {
            $upload_check = Ramza_ValidateReactionIconUpload(isset($_FILES['ramza']) ? $_FILES['ramza'] : array());
            $insert_data = array();
            $primary_label = '';
            $language_names = Wo_LangsNamesFromDB();
            foreach ($language_names as $lang) {
                $label = isset($_POST[$lang]) ? trim((string) $_POST[$lang]) : '';
                if ($label !== '') {
                    $insert_data[$lang] = Wo_Secure($label);
                    if ($primary_label === '') {
                        $primary_label = $insert_data[$lang];
                    }
                }
            }
            if ($primary_label !== '') {
                foreach ($language_names as $lang) {
                    if (empty($insert_data[$lang])) {
                        $insert_data[$lang] = $primary_label;
                    }
                }
            }

            if (!$upload_check['valid']) {
                $data['message'] = $upload_check['message'];
            }
            elseif ($primary_label === '') {
                $data['message'] = 'Enter a reaction name.';
            }
            else {
                $file_info = array(
                    'file' => $_FILES['ramza']['tmp_name'],
                    'name' => $_FILES['ramza']['name'],
                    'size' => $_FILES['ramza']['size'],
                    'type' => $upload_check['mime'],
                    'types' => 'jpeg,png,jpg,gif'
                );
                $media = Wo_ShareFile($file_info, true);
                if (empty($media['filename'])) {
                    $data['message'] = 'The reaction icon could not be stored.';
                }
                else {
                    $lang_id = $db->insert(T_LANGS, $insert_data);
                    if (!empty($lang_id)) {
                        $db->where('id', $lang_id)->update(T_LANGS, array('lang_key' => $lang_id));
                        $reaction_id = $db->insert(T_REACTIONS_TYPES, array(
                            'name' => $lang_id,
                            'ramza_icon' => $media['filename'],
                            'sunshine_icon' => '',
                            'status' => 1
                        ));
                        if (!empty($reaction_id)) {
                            $data = array('status' => 200, 'id' => (int) $reaction_id, 'message' => 'Reaction added.');
                        }
                        else {
                            $db->where('id', $lang_id)->delete(T_LANGS);
                            Ramza_DeleteReactionIcon($media['filename']);
                            $data['message'] = 'The reaction could not be saved.';
                        }
                    }
                    else {
                        Ramza_DeleteReactionIcon($media['filename']);
                        $data['message'] = 'The reaction name could not be saved.';
                    }
                }
            }
        }
        else {
            $data['message'] = 'Your admin session expired. Refresh and try again.';
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'reaction_status') {
        $data['status']  = 400;
        $data['message'] = 'Please check your details';
        if (Wo_CheckMainSession($hash_id) === true && !empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $active_reactions = $db->where('status', 1)->getValue(T_REACTIONS_TYPES, 'COUNT(*)');
            if ($active_reactions > 0) {
                $id       = Wo_Secure($_POST['id']);
                $reaction = $db->where('id', $id)->getOne(T_REACTIONS_TYPES);
                if (!empty($reaction)) {
                    $status = 1;
                    if ($reaction->status == 1) {
                        $status = 0;
                    }
                    if ($active_reactions == 1 && $status == 0) {
                        $data['message'] = 'You cant disable all reactions';
                    } else {
                        $db->where('id', $id)->update(T_REACTIONS_TYPES, array(
                            'status' => $status
                        ));
                        $data = array('status' => 200, 'enabled' => (int) $status, 'message' => $status ? 'Reaction enabled.' : 'Reaction disabled.');
                    }
                }
            } else {
                $data['message'] = 'You cant disable all reactions';
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_reaction') {
        $data['status']  = 400;
        $data['message'] = 'Please check your details';
        if (Wo_CheckMainSession($hash_id) === true && !empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $id       = Wo_Secure($_POST['id']);
            $reaction = $db->where('id', $id)->getOne(T_REACTIONS_TYPES);
            if ($id > 6 && !empty($reaction)) {
                $active_reactions = (int) $db->where('status', 1)->getValue(T_REACTIONS_TYPES, 'COUNT(*)');
                if ((int) $reaction->status === 1 && $active_reactions <= 1) {
                    $data['message'] = 'Enable another reaction before deleting this one.';
                }
                else {
                    $db->where('lang_key', $reaction->name)->delete(T_LANGS);
                    $db->where('reaction', $id)->delete(T_REACTIONS);
                    $db->where('reaction', $id)->delete(T_BLOG_REACTION);
                    $deleted = $db->where('id', $id)->delete(T_REACTIONS_TYPES);
                    if ($deleted) {
                        Ramza_DeleteReactionIcon($reaction->ramza_icon);
                        $data = array('status' => 200, 'message' => 'Reaction deleted.');
                    }
                }
            }
            elseif ((int) $id <= 6) {
                $data['message'] = 'Built-in reactions cannot be deleted.';
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_reaction_form') {
        $data['status']  = 400;
        $data['message'] = 'Please check your details';
        if (Wo_CheckMainSession($hash_id) === true && !empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $id       = Wo_Secure($_POST['id']);
            $reaction = $db->where('id', $id)->getOne(T_REACTIONS_TYPES);
            $html     = '';
            if (!empty($reaction)) {
                $lang_html = '';
                $langs     = Wo_GetLangDetails($reaction->name);
                if (count($langs) > 0) {
                    foreach ($langs as $key => $wo['langs']) {
                        foreach ($wo['langs'] as $wo['key_'] => $wo['lang_vlaue']) {
                            $wo['is_editale'] = true;
                            $lang_html .= Wo_LoadAdminPage('edit-lang/form-list');
                        }
                    }
                }
                $wo['reaction_name'] = $lang_html;
                $wo['reaction_id']   = $reaction->id;
                $wo['ramza_icon'] = $reaction->ramza_icon;
                $wo['reaction_icon_url'] = Ramza_ReactionIconUrl(!empty($reaction->ramza_icon) ? $reaction->ramza_icon : $reaction->sunshine_icon);
                $wo['reaction_has_custom_icon'] = ((string) $reaction->ramza_icon !== '' && (string) $reaction->ramza_icon !== (string) $reaction->sunshine_icon);
                $html                = Wo_LoadAdminPage('manage-reactions/form');
                $data                = array(
                    'status' => 200,
                    'html' => $html
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'edit_reaction') {
        $data['status']  = 400;
        $data['message'] = 'Please check your details';
        if (Wo_CheckMainSession($hash_id) === true && !empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            $id       = Wo_Secure($_POST['id']);
            $reaction = $db->where('id', $id)->getOne(T_REACTIONS_TYPES);
            if (!empty($reaction)) {
                $lang_key = $reaction->name;
                $langs    = Wo_LangsNamesFromDB();
                $language_updates = array();
                foreach ($_POST as $key => $value) {
                    if (in_array($key, $langs, true)) {
                        $language_updates[$key] = $value;
                    }
                }
                $update_data = array();
                $new_icon = '';
                if (!empty($_FILES['ramza']['tmp_name'])) {
                    $upload_check = Ramza_ValidateReactionIconUpload($_FILES['ramza']);
                    if (!$upload_check['valid']) {
                        $data['message'] = $upload_check['message'];
                        header("Content-type: application/json");
                        echo json_encode($data);
                        exit();
                    }
                    $file_info = array(
                        'file' => $_FILES['ramza']['tmp_name'],
                        'name' => $_FILES['ramza']['name'],
                        'size' => $_FILES['ramza']['size'],
                        'type' => $upload_check['mime'],
                        'types' => 'jpeg,png,jpg,gif'
                    );
                    $media = Wo_ShareFile($file_info, true);
                    if (empty($media['filename'])) {
                        $data['message'] = 'The reaction icon could not be stored.';
                        header("Content-type: application/json");
                        echo json_encode($data);
                        exit();
                    }
                    $new_icon = $media['filename'];
                    $update_data['ramza_icon'] = $new_icon;
                }
                elseif (!empty($_POST['ramza_to_use']) && (int) $_POST['ramza_to_use'] === 1 && (int) $id <= 6) {
                    $update_data['ramza_icon'] = '';
                }

                mysqli_begin_transaction($sqlConnect);
                $updated = true;
                if (!empty($update_data)) {
                    $updated = $db->where('id', $id)->update(T_REACTIONS_TYPES, $update_data);
                }
                if ($updated) {
                    $safe_lang_key = Wo_Secure($lang_key);
                    foreach ($language_updates as $key => $value) {
                        $safe_key = Wo_Secure($key);
                        $safe_value = Wo_Secure($value);
                        if (!mysqli_query($sqlConnect, "UPDATE " . T_LANGS . " SET `{$safe_key}` = '{$safe_value}' WHERE `lang_key` = '{$safe_lang_key}'")) {
                            $updated = false;
                            break;
                        }
                    }
                }
                if (!$updated) {
                    mysqli_rollback($sqlConnect);
                    if ($new_icon !== '') {
                        Ramza_DeleteReactionIcon($new_icon);
                    }
                    $data['message'] = 'The reaction could not be updated.';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }
                mysqli_commit($sqlConnect);
                if (!empty($update_data) && (string) $reaction->ramza_icon !== '' && (string) $reaction->ramza_icon !== (string) $reaction->sunshine_icon) {
                    Ramza_DeleteReactionIcon($reaction->ramza_icon);
                }
                $data = array('status' => 200, 'message' => 'Reaction updated.');
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_new_sub_category') {
        $data['status']  = 400;
        $data['message'] = 'Please check your details';
        $types           = array(
            'page',
            'group',
            'product'
        );
        $all_categories  = array(
            'page' => $wo['page_categories'],
            'group' => $wo['group_categories'],
            'product' => $wo['products_categories']
        );
        if (!empty($_GET['type']) && in_array($_GET['type'], $types) && in_array($_GET['type'], array_keys($all_categories)) && !empty($_POST['category_id']) && in_array($_POST['category_id'], array_keys($all_categories[$_GET['type']]))) {
            $type        = Wo_Secure($_GET['type']);
            $add         = false;
            $insert_data = array();
            foreach (Wo_LangsNamesFromDB() as $key => $lang) {
                if (!empty($_POST[$lang])) {
                    $insert_data[$lang] = Wo_Secure($_POST[$lang]);
                    $add                = true;
                }
            }
            if ($add == true && !empty($insert_data)) {
                $id = $db->insert(T_LANGS, $insert_data);
                $db->insert(T_SUB_CATEGORIES, array(
                    'lang_key' => $id,
                    'category_id' => Wo_Secure($_POST['category_id']),
                    'type' => $type
                ));
                $db->where('id', $id)->update(T_LANGS, array(
                    'lang_key' => $id
                ));
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_sub_category' && !empty($_POST['lang_key'])) {
        $types = array(
            'page',
            'group',
            'product'
        );
        if (!empty($_GET['type']) && in_array($_GET['type'], $types)) {
            $lang_key = Wo_Secure($_POST['lang_key']);
            $category = $db->where('lang_key', $lang_key)->where('type', Wo_Secure($_GET['type']))->getOne(T_SUB_CATEGORIES);
            if (!empty($category)) {
                $db->where('lang_key', $lang_key)->delete(T_LANGS);
                $db->where('id', $category->id)->delete(T_SUB_CATEGORIES);
                if ($_GET['type'] == 'page') {
                    $db->where('sub_category', $category->id)->update(T_PAGES, array(
                        'sub_category' => ''
                    ));
                }
                if ($_GET['type'] == 'group') {
                    $db->where('sub_category', $category->id)->update(T_GROUPS, array(
                        'sub_category' => ''
                    ));
                    resetCache("groups");
                }
                if ($_GET['type'] == 'product') {
                    $db->where('sub_category', $category->id)->update(T_PRODUCTS, array(
                        'sub_category' => ''
                    ));
                }
                $data['status'] = 200;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'add_custom_field_form') {
        $placement_array = array(
            'page',
            'group',
            'product'
        );
        $types_array     = array(
            'textbox',
            'textarea',
            'selectbox'
        );
        if (Wo_CheckSession($hash_id) === true && !empty($_POST['name']) && !empty($_POST['type']) && !empty($_POST['description']) && !empty($_POST['placement']) && in_array($_POST['type'], $types_array)) {
            $type        = Wo_Secure($_POST['type']);
            $name        = Wo_Secure($_POST['name']);
            $description = Wo_Secure($_POST['description']);
            $placement   = Wo_Secure($_POST['placement']);
            $length      = 32;
            if (!empty($_POST['length'])) {
                if (is_numeric($_POST['length']) && $_POST['length'] < 1001) {
                    $length = Wo_Secure($_POST['length']);
                }
            }
            $required = 'on';
            if (!empty($_POST['required']) && in_array($_POST['required'], array(
                'on',
                'off'
            ))) {
                $required = Wo_Secure($_POST['required']);
            }
            $data_ = array(
                'name' => $name,
                'description' => $description,
                'length' => $length,
                'placement' => $placement,
                'required' => $required,
                'type' => $type,
                'active' => 1
            );
            if (!empty($_POST['options'])) {
                $options          = @explode("\n", $_POST['options']);
                $data_['options'] = Wo_Secure(implode(',', $options));
            }
            $add = Wo_RegisterNewCustomField($data_);
            if ($add) {
                $data['status'] = 200;
            }
        } else {
            $data = array(
                'status' => 400,
                'message' => 'Please fill all the required fields'
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_custom_field') {
        $placement_array = array(
            'page',
            'group',
            'product'
        );
        if (Wo_CheckMainSession($hash_id) === true && !empty($_GET['id']) && !empty($_GET['type']) && in_array($_GET['type'], $placement_array)) {
            $delete = Wo_DeleteCustomField($_GET['id'], $_GET['type']);
            if ($delete) {
                $data = array(
                    'status' => 200
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_custom_field_info') {
        $placement_array = array(
            'page',
            'group',
            'product'
        );
        if (Wo_CheckMainSession($hash_id) === true && !empty($_POST['id']) && !empty($_POST['type']) && in_array($_POST['type'], $placement_array)) {
            $field = $db->where('id', Wo_Secure($_POST['id']))->where('placement', Wo_Secure($_POST['type']))->getOne(T_CUSTOM_FIELDS);
            $html  = '';
            if (!empty($field)) {
                $wo['field'] = $field;
                $html        = Wo_LoadAdminPage('pages-fields/form');
            }
            $data = array(
                'status' => 200,
                'html' => $html
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'edit_custom_field_form') {
        $placement_array = array(
            'page',
            'group',
            'product'
        );
        $types_array     = array(
            'textbox',
            'textarea',
            'selectbox'
        );
        if (Wo_CheckSession($hash_id) === true && !empty($_POST['name']) && !empty($_POST['description']) && !empty($_POST['id']) && !empty($_POST['type']) && !empty($_POST['placement']) && in_array($_POST['type'], $types_array)) {
            $field = $db->where('id', Wo_Secure($_POST['id']))->where('placement', Wo_Secure($_POST['placement']))->getOne(T_CUSTOM_FIELDS);
            if (!empty($field)) {
                $name        = Wo_Secure($_POST['name']);
                $description = Wo_Secure($_POST['description']);
                $type        = Wo_Secure($_POST['type']);
                $placement   = Wo_Secure($_POST['placement']);
                $length      = 32;
                if (!empty($_POST['length'])) {
                    if (is_numeric($_POST['length']) && $_POST['length'] < 1001) {
                        $length = Wo_Secure($_POST['length']);
                    }
                }
                $required = 'on';
                if (!empty($_POST['required']) && in_array($_POST['required'], array(
                    'on',
                    'off'
                ))) {
                    $required = Wo_Secure($_POST['required']);
                }
                $data_ = array(
                    'name' => $name,
                    'description' => $description,
                    'length' => $length,
                    'placement' => $placement,
                    'required' => $required,
                    'type' => $type,
                    'active' => 1
                );
                if (!empty($_POST['options'])) {
                    $options          = @explode("\n", $_POST['options']);
                    $data_['options'] = Wo_Secure(implode(',', $options));
                }
                $add = Wo_UpdateCustomField(Wo_Secure($_POST['id']), $data_);
                if ($add) {
                    $data['status'] = 200;
                } else {
                    $data = array(
                        'status' => 400,
                        'message' => 'Please fill all the required fields'
                    );
                }
            } else {
                $data = array(
                    'status' => 400,
                    'message' => 'Please fill all the required fields'
                );
            }
        } else {
            $data = array(
                'status' => 400,
                'message' => 'Please fill all the required fields'
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'approve_blog') {
        if (!empty($_POST['blog_id'])) {
            $post = $db->where('id', Wo_Secure($_POST['blog_id']))->getOne(T_BLOG);
            if (!empty($post)) {
                $db->where('id', Wo_Secure($_POST['blog_id']))->update(T_BLOG, array(
                    'active' => '1'
                ));
                $db->where('blog_id', Wo_Secure($_POST['blog_id']))->update(T_POSTS, array(
                    'active' => 1
                ));
                $b_post = $db->where('blog_id', Wo_Secure($_POST['blog_id']))->getOne(T_POSTS);
                if (!empty($b_post)) {
                    Wo_RegisterPoint($b_post->id, "createblog", '+', $b_post->user_id);
                }
                $notification_data_array = array(
                    'recipient_id' => $post->user,
                    'type' => 'admin_notification',
                    'url' => 'index.php?link1=read-blog&id=' . $post->id,
                    'text' => $wo['lang']['approve_blog'],
                    'type2' => 'approve_blog'
                );
                Wo_RegisterNotification($notification_data_array);
            }
        }
        $data['status'] = 200;
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_refund') {
        if (!empty($_GET['id'])) {
            $request = $db->where('id', Wo_Secure($_GET['id']))->getOne(T_REFUND);
            $db->where('id', Wo_Secure($_GET['id']))->delete(T_REFUND);
            $data = array(
                'status' => 200
            );
            if (empty($request->order_hash_id)) {
                $notification_data_array = array(
                    'recipient_id' => $request->user_id,
                    'type' => 'admin_notification',
                    'url' => 'index.php?link1=home',
                    'text' => $wo['lang']['refund_decline'],
                    'type2' => 'refund_decline'
                );
                Wo_RegisterNotification($notification_data_array);
            } else {
                $notification_data_array = array(
                    'recipient_id' => $request->user_id,
                    'type' => 'admin_notification',
                    'url' => 'index.php?link1=customer_order&id=' . $request->order_hash_id,
                    'text' => $wo['lang']['refund_decline'],
                    'type2' => 'refund_decline'
                );
                Wo_RegisterNotification($notification_data_array);
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'approve_refund') {
        if (!empty($_GET['id'])) {
            $request = $db->where('id', Wo_Secure($_GET['id']))->getOne(T_REFUND);
            if (!empty($request)) {
                if (empty($request->order_hash_id)) {
                    $price = $wo['pro_packages'][$request->pro_type]['price'];
                    $db->where('user_id', $request->user_id)->update(T_USERS, array(
                        'balance' => $db->inc($price),
                        'is_pro' => 0
                    ));
                    $db->where('id', Wo_Secure($_GET['id']))->delete(T_REFUND);
                    $notification_data_array = array(
                        'recipient_id' => $request->user_id,
                        'type' => 'admin_notification',
                        'url' => 'index.php?link1=setting&page=payments',
                        'text' => $wo['lang']['refund_approve'],
                        'type2' => 'refund_approve'
                    );
                    Wo_RegisterNotification($notification_data_array);
                } else {
                    $total_final_price = 0;
                    $price             = 0;
                    $orders            = $db->where('hash_id', $request->order_hash_id)->get(T_USER_ORDERS);
                    foreach ($orders as $key => $order) {
                        $db->where('id', $order->product_id)->update(T_PRODUCTS, array(
                            'units' => $db->inc($order->units)
                        ));
                        $total_final_price += $order->final_price;
                        $price += $order->price;
                    }
                    $order = $db->where('hash_id', $request->order_hash_id)->getOne(T_USER_ORDERS);
                    $user  = $db->where('user_id', $order->product_owner_id)->update(T_USERS, array(
                        'balance' => $db->dec($total_final_price)
                    ));
                    $user  = $db->where('user_id', $request->user_id)->update(T_USERS, array(
                        'wallet' => $db->inc($price)
                    ));
                    $db->where('hash_id', $request->order_hash_id)->update(T_USER_ORDERS, array(
                        'status' => 'canceled'
                    ));

                    cache($request->user_id, 'users', 'delete');
                    cache($order->product_owner_id, 'users', 'delete');

                    $notification_data_array = array(
                        'recipient_id' => $request->user_id,
                        'type' => 'admin_notification',
                        'url' => 'index.php?link1=customer_order&id=' . $request->order_hash_id,
                        'text' => $wo['lang']['refund_approve'],
                        'type2' => 'refund_approve'
                    );
                    Wo_RegisterNotification($notification_data_array);
                    $db->where('id', Wo_Secure($_GET['id']))->delete(T_REFUND);
                }
            }
            $data = array(
                'status' => 200
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_cloud') {
        if ($wo['config']['cloud_upload'] == 0 || empty($wo['config']['cloud_file_path']) || empty($wo['config']['cloud_bucket_name'])) {
            $data['message'] = 'Please enable Google Cloud Storage and fill all fields.';
        } elseif (!file_exists($wo['config']['cloud_file_path'])) {
            $data['message'] = 'Google Cloud File not found on your server Please upload it to your server.';
        } else {
            require_once 'assets/libraries/google-lib/vendor/autoload.php';
            try {
                $storage = new StorageClient(array(
                    'keyFilePath' => $wo['config']['cloud_file_path']
                ));
                // set which bucket to work in
                $bucket  = $storage->bucket($wo['config']['cloud_bucket_name']);
                if ($bucket) {
                    $array = array(
                        'upload/photos/d-avatar.jpg',
                        $wo["userDefaultBlur"],
                        'upload/photos/f-avatar.jpg',
                        'upload/photos/d-cover.jpg',
                        'upload/photos/d-group.jpg',
                        'upload/photos/d-page.jpg',
                        'upload/photos/d-blog.jpg',
                        'upload/photos/game-icon.png',
                        'upload/photos/d-film.jpg',
                        'upload/photos/incognito.png',
                        'upload/photos/app-default-icon.png'
                    );
                    foreach ($array as $key => $value) {
                        $fileContent   = file_get_contents($value);
                        // upload/replace file
                        $storageObject = $bucket->upload($fileContent, array(
                            'name' => $value
                        ));
                    }
                    $data['status'] = 200;
                } else {
                    $data['message'] = 'Error in connection';
                }
            }
            catch (Exception $e) {
                $data['message'] = "" . $e;
                // maybe invalid private key ?
                // print $e;
                // exit();
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_ban') {
        if (!empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0) {
            if (Wo_DeleteBanned(Wo_Secure($_POST['id'])) === true) {
                $data = array(
                    'status' => 200
                );
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
        }
    }
    if ($s == 'new_ban') {
        if (!empty($_POST['id'])) {
            if (Wo_BanNewIp(Wo_Secure($_POST['id']))) {
                $data = array(
                    'status' => 200
                );
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
        }
    }
    if ($s == 'ReadNotify') {
        $db->where('recipient_id', 0)->where('admin', 1)->where('seen', 0)->update(T_NOTIFICATION, array(
            'seen' => time()
        ));
    }
    if ($s == 'change_mode') {
        if (!empty($_COOKIE['mode'])) {
            if ($_COOKIE['mode'] == 'night') {
                setcookie("mode", 'day', time() + (10 * 365 * 24 * 60 * 60), '/');
                $_COOKIE['mode'] = 'day';
            } else {
                setcookie("mode", 'night', time() + (10 * 365 * 24 * 60 * 60), '/');
                $_COOKIE['mode'] = 'night';
            }
        } else {
            setcookie("mode", 'night', time() + (10 * 365 * 24 * 60 * 60), '/');
            $_COOKIE['mode'] = 'night';
        }
    }
    if ($s == 'permission') {
        header("Content-type: application/json");
        if (!Wo_IsAdmin()) {
            http_response_code(403);
            echo json_encode(array('status' => 403, 'message' => 'Only a full administrator can change account roles.'));
            exit();
        }
        if (!Wo_CheckMainSession($hash_id)) {
            http_response_code(403);
            echo json_encode(array('status' => 403, 'message' => 'Your admin session has expired. Reload the page and try again.'));
            exit();
        }
        if (!empty($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0 && !empty($_GET['type']) && in_array($_GET['type'], array(
            'normal',
            'moderator',
            'admin'
        ))) {
            $target_user_id = (int) $_GET['user_id'];
            if ($target_user_id === (int) $wo['user']['user_id']) {
                http_response_code(400);
                echo json_encode(array('status' => 400, 'message' => 'You cannot change your own administrator role.'));
                exit();
            }
            $target_user = $db->where('user_id', $target_user_id)->getOne(T_USERS);
            if (empty($target_user)) {
                http_response_code(404);
                echo json_encode(array('status' => 404, 'message' => 'The selected user no longer exists.'));
                exit();
            }
            $update = array(
                'admin' => '0'
            );
            if ($_GET['type'] == 'admin') {
                $update = array(
                    'admin' => '1'
                );
            }
            if ($_GET['type'] == 'moderator') {
                $update = array(
                    'admin' => '2'
                );
            }
            $updated = $db->where('user_id', $target_user_id)->update(T_USERS, $update);
            if (!$updated) {
                http_response_code(500);
                echo json_encode(array('status' => 500, 'message' => 'The role could not be saved.'));
                exit();
            }
            $data = array(
                'status' => 200
            );
            cache($target_user_id, 'users', 'delete');
            echo json_encode($data);
            exit();
        }
        http_response_code(400);
        echo json_encode(array('status' => 400, 'message' => 'The permission request is invalid.'));
        exit();
    }
    if ($s == 'update_moderator_permission') {
        header("Content-type: application/json");
        if (!Wo_IsAdmin()) {
            http_response_code(403);
            echo json_encode(array('status' => 403, 'message' => 'Only a full administrator can manage moderator permissions.'));
            exit();
        }
        if (!Wo_CheckMainSession($hash_id)) {
            http_response_code(403);
            echo json_encode(array('status' => 403, 'message' => 'Your admin session has expired. Reload the page and try again.'));
            exit();
        }
        if (!empty($_GET['permission']) && !empty($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0 && in_array($_GET['permission_val'], array(
            0,
            1
        ))) {
            $wo['mod_pages'] = array(
                'dashboard',
                'post-settings',
                'manage-stickers',
                'manage-gifts',
                'manage-users',
                'online-users',
                'manage-stories',
                'manage-pages',
                'manage-groups',
                'manage-posts',
                'manage-articles',
                'manage-events',
                'manage-forum-threads',
                'manage-forum-messages',
                'manage-movies',
                'manage-games',
                'add-new-game',
                'manage-user-ads',
                'manage-reports',
                'edit-movie',
                'bank-receipts',
                'job-categories',
                'manage-jobs'
            );
            $permission_name = (string) $_GET['permission'];
            if (!preg_match('/^[a-z0-9_-]+$/i', $permission_name) || !is_dir('admin-panel/pages/' . $permission_name)) {
                http_response_code(400);
                echo json_encode(array('status' => 400, 'message' => 'The selected permission is not valid.'));
                exit();
            }
            $target_user_id  = (int) $_GET['user_id'];
            $user            = $db->where('user_id', $target_user_id)->where('admin', '2')->getOne(T_USERS);
            if (!empty($user)) {
                $wo['all_pages'] = scandir('admin-panel/pages');
                unset($wo['all_pages'][0]);
                unset($wo['all_pages'][1]);
                unset($wo['all_pages'][2]);
                if (!empty($user->permission)) {
                    $permission                                 = json_decode($user->permission, true);
                    $permission[$permission_name] = (int) $_GET['permission_val'];
                } else {
                    $permission = array();
                    if (!empty($wo['all_pages'])) {
                        foreach ($wo['all_pages'] as $key => $value) {
                            if (in_array($value, $wo['mod_pages'])) {
                                $permission[$value] = 1;
                            } else {
                                $permission[$value] = 0;
                            }
                        }
                    }
                    $permission[$permission_name] = (int) $_GET['permission_val'];
                }
                $permission = json_encode($permission);
                $updated = $db->where('user_id', $target_user_id)->update(T_USERS, array(
                    'permission' => $permission
                ));
                if (!$updated) {
                    http_response_code(500);
                    echo json_encode(array('status' => 500, 'message' => 'The permission could not be saved.'));
                    exit();
                }
                cache($target_user_id, 'users', 'delete');
            }
            else {
                http_response_code(404);
                echo json_encode(array('status' => 404, 'message' => 'The selected account is not a moderator.'));
                exit();
            }
        }
        else {
            http_response_code(400);
            echo json_encode(array('status' => 400, 'message' => 'The permission request is invalid.'));
            exit();
        }
        $data = array(
            'status' => 200
        );
        echo json_encode($data);
        exit();
    }
    if ($s == 'exchange') {
        if ($wo['config']['exchange_update'] < time()) {
            $request                                                              = fetchDataFromURL("https://v6.exchangerate-api.com/v6/".$wo['config']['exchangerate_key']."/latest/".$wo['config']['currency']);
            $exchange                                                             = json_decode($request, true);
            if (!empty($exchange) && $exchange['result'] == 'success' && !empty($exchange['conversion_rates'])) {
                Wo_SaveConfig('exchange', json_encode($exchange['conversion_rates']));
                Wo_SaveConfig('exchange_update', (time() + (60 * 60 * 12)));
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'edit-forum') {
        if (empty($_POST['name']) || empty($_POST['description']) || empty($_POST['section']) || empty($_POST['id']) || !is_numeric($_POST['id'])) {
            $error = $error_icon . $wo['lang']['please_check_details'];
        } else {
            if (strlen($_POST['name']) < 5) {
                $error = $error_icon . $wo['lang']['title_more_than10'];
            }
            if (strlen($_POST['name']) > 100) {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
            if (strlen($_POST['description']) < 5) {
                $error = $error_icon . $wo['lang']['desc_more_than32'];
            }
            if (strlen($_POST['description']) > 190) {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
        }
        if (empty($error)) {
            $forum = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_FORUMS);
            if (!empty($forum)) {
               $registration_data = array(
                    'name' => Wo_Secure($_POST['name']),
                    'description' => Wo_Secure($_POST['description']),
                    'sections' => Wo_Secure($_POST['section'])
                );
               $db->where('id',Wo_Secure($_POST['id']))->update(T_FORUMS,$registration_data);
               $data = array(
                    'status' => 200
                );
            } else {
                $data = array(
                    'status' => 500,
                    'message' => $wo['lang']['please_check_details']
                );
            }
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'edit-forum-section') {
        if (empty($_POST['name']) || empty($_POST['description'])) {
            $error = $error_icon . $wo['lang']['please_check_details'];
        } else {
            if (strlen($_POST['name']) < 5) {
                $error = $error_icon . $wo['lang']['title_more_than10'];
            }
            if (strlen($_POST['name']) > 145) {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
            if (strlen($_POST['description']) < 5) {
                $error = $error_icon . $wo['lang']['desc_more_than32'];
            }
        }
        if (empty($error)) {
            $forum = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_FORUM_SEC);
            if (!empty($forum)) {
                $registration_data = array(
                    'section_name' => Wo_Secure($_POST['name']),
                    'description' => Wo_Secure($_POST['description'])
                );
                $db->where('id',Wo_Secure($_POST['id']))->update(T_FORUM_SEC,$registration_data);
                $data = array(
                    'status' => 200
                );

            }else {
                $data = array(
                    'status' => 500,
                    'message' => $wo['lang']['please_check_details']
                );
            }
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'insert-invitation') {
        $data             = array(
            'status' => 200,
            'html' => ''
        );
        $wo['invitation'] = Wo_InsertAdminInvitation();
        if ($wo['invitation'] && is_array($wo['invitation'])) {
            $data['html']   = Wo_LoadAdminPage('manage-invitation-keys/list');
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'rm-invitation' && isset($_GET['id']) && is_numeric($_GET['id'])) {
        $data = array(
            'status' => 304
        );
        if (Wo_DeleteAdminInvitation('id', $_GET['id'])) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update-sitemap') {
        $rate = (isset($_POST['rate']) && strlen($_POST['rate']) > 0) ? $_POST['rate'] : false;
        $data = array(
            'status' => 304
        );
        if (Wo_GenirateSiteMap($rate)) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'rm-user-invitation' && isset($_GET['id']) && is_numeric($_GET['id'])) {
        $data = array(
            'status' => 304
        );
        if (Wo_DeleteUserInvitation('id', $_GET['id'])) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'test_backblaze') {
        $server_output = BackblazeConnect(array('apiUrl' => 'https://api.backblazeb2.com',
                                               'uri' => '/b2api/v2/b2_authorize_account',
                                            ));
        $data['status'] = 404;
        if (!empty($server_output)) {
            $result = json_decode($server_output,true);
            if (!empty($result['authorizationToken']) && !empty($result['apiUrl']) && !empty($result['accountId'])) {

                $info = BackblazeConnect(array('apiUrl' => $result['apiUrl'],
                                               'uri' => '/b2api/v2/b2_list_buckets',
                                               'accountId' => $result['accountId'],
                                               'authorizationToken' => $result['authorizationToken'],
                                        ));
                if (!empty($info)) {
                    $info = json_decode($info,true);
                    if (!empty($info) && !empty($info['buckets'])) {
                        $bucketId = '';
                        foreach ($info['buckets'] as $key => $value) {
                            if ($value['bucketId'] == $wo['config']['backblaze_bucket_id']) {
                                $db->where('name', 'backblaze_bucket_name')->update(T_CONFIG, array('value' => $value['bucketName']));
                                $bucketId = $value['bucketId'];
                                break;
                            }
                        }

                        if (!empty($bucketId)) {
                            $data['status'] = 200;
                            $array = array(
                                'upload/photos/d-avatar.jpg',
                                $wo["userDefaultBlur"],
                                'upload/photos/f-avatar.jpg',
                                'upload/photos/d-cover.jpg',
                                'upload/photos/d-group.jpg',
                                'upload/photos/d-page.jpg',
                                'upload/photos/d-blog.jpg',
                                'upload/photos/game-icon.png',
                                'upload/photos/d-film.jpg',
                                'upload/photos/app-default-icon.png',
                                'upload/photos/incognito.png',
                                'upload/.htaccess',
                                'upload/files/2022/09/EAufYfaIkYQEsYzwvZha_01_4bafb7db09656e1ecb54d195b26be5c3_file.svg',
                                'upload/files/2022/09/2MRRkhb7rDhUNuClfOfc_01_76c3c700064cfaef049d0bb983655cd4_file.svg',
                                'upload/files/2022/09/D91CP5YFfv74GVAbYtT7_01_288940ae12acf0198d590acbf11efae0_file.svg',
                                'upload/files/2022/09/cFNOXZB1XeWRSdXXEdlx_01_7d9c4adcbe750bfc8e864c69cbed3daf_file.svg',
                                'upload/files/2022/09/yKmDaNA7DpA7RkCRdoM6_01_eb391ca40102606b78fef1eb70ce3c0f_file.svg',
                                'upload/files/2022/09/iZcVfFlay3gkABhEhtVC_01_771d67d0b8ae8720f7775be3a0cfb51a_file.svg'
                            );
                            foreach ($array as $key => $value) {
                                $upload = Wo_UploadToS3($value, array(
                                    'delete' => 'no'
                                ));
                            }
                        }
                    }
                    else{
                        $data['status'] = 300;
                    }
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'uploadFiles') {
        if (!empty($_GET['file']) && !empty($_GET['path'])) {
            $file = Wo_Secure(base64_decode($_GET['path']));
            $storage = Wo_Secure($_GET['file']);
            $checkIfFileExistsInUpload = $db->where('filename', Wo_Secure($file))->where('storage', $storage)->getOne(T_UPLOADED_MEDIA);
            if (empty($checkIfFileExistsInUpload)) {
               try {
                    $uploadToS3 = Wo_UploadToS3($file, ["delete" => "no"]);
                    if ($uploadToS3) {
                        $insert = $db->insert(T_UPLOADED_MEDIA, ['filename' => Wo_Secure($file), 'storage' => $storage, 'time' => time()]);
                        $data = ['status' => 200, 'fullPath' => Wo_GetMedia(str_replace("\\", "/", $file))];
                    } else {
                        $data = ['status' => 400, 'message' => "Error found while uploading, please check settings."];
                    }
               } catch (Throwable $e) {
                   $data = ['status' => 400, 'message' => $e->getMessage()];
               }
            } else {
                $data = ['status' => 400, 'message' => "File already uploaded."];
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit();
    }
}
