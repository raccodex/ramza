<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime_config' . DIRECTORY_SEPARATOR . 'config.php';

$mysqli = mysqli_connect($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name, 3306);
if (!$mysqli) {
    fwrite(STDERR, 'Unable to connect to disposable runtime database.' . PHP_EOL);
    exit(1);
}

mysqli_set_charset($mysqli, 'utf8mb4');

$password = 'Phase1Test!2026';
$users = [
    [
        'username' => 'phase1user',
        'email' => 'phase1user@example.test',
        'first_name' => 'Phase',
        'last_name' => 'User',
        'admin' => '0',
    ],
    [
        'username' => 'phase1admin',
        'email' => 'phase1admin@example.test',
        'first_name' => 'Phase',
        'last_name' => 'Admin',
        'admin' => '1',
    ],
];

$sql = "INSERT INTO wo_users
    (username, email, password, first_name, last_name, avatar, cover, background_image,
     relationship_id, address, working, working_link, school, gender, birthday, country_id,
     website, facebook, google, twitter, linkedin, youtube, vk, instagram, language,
     email_code, src, follow_privacy, friend_privacy, post_privacy, message_privacy,
     confirm_followers, show_activities_privacy, birth_privacy, visit_privacy, verified,
     lastseen, showlastseen, emailNotification, e_liked, e_wondered, e_shared, e_followed,
     e_commented, e_visited, e_liked_page, e_mentioned, e_joined_group, e_accepted,
     e_profile_wall_post, e_sentme_msg, e_last_notif, notification_settings, status,
     active, admin, type, registered, start_up, start_up_info, startup_follow,
     startup_image, last_email_sent, phone_number, sms_code, is_pro, pro_time, pro_type,
     pro_remainder, joined, css_file, timezone, referrer, ref_user_id, ref_level,
     balance, paypal_email, notifications_sound, order_posts_by, social_login,
     android_m_device_id, ios_m_device_id, android_n_device_id, ios_n_device_id,
     web_device_id, wallet, lat, lng, last_location_update, share_my_location,
     last_data_update, details, sidebar_data, last_avatar_mod, last_cover_mod,
     points, daily_points, converted_points, point_day_expire, last_follow_id,
     share_my_data, last_login_data, two_factor, two_factor_hash, new_email,
     two_factor_verified, new_phone, info_file, city, state, zip, school_completed,
     weather_unit, paystack_ref, code_sent, time_code_sent, permission, skills,
     languages, currently_working, banned, banned_reason, credits, authy_id,
     google_secret, two_factor_method, phone_privacy, have_monetization)
VALUES
    (?, ?, ?, ?, ?, 'upload/photos/d-avatar.jpg', 'upload/photos/d-cover.jpg', '',
     0, '', '', '', '', 'male', '0000-00-00', 0,
     '', '', '', '', '', '', '', '', 'english',
     '', 'Undefined', '0', '0', 'ifollow', '0',
     '0', '1', '0', '0', '1',
     0, '1', '1', '1', '1', '1', '1',
     '1', '1', '1', '1', '1', '1',
     '1', '0', '0',
     '{\"e_liked\":1,\"e_shared\":1,\"e_wondered\":0,\"e_commented\":1,\"e_followed\":1,\"e_accepted\":1,\"e_mentioned\":1,\"e_joined_group\":1,\"e_liked_page\":1,\"e_visited\":1,\"e_profile_wall_post\":1,\"e_memory\":1}',
     '0', '1', ?, 'user', ?, '1', '1', '1',
     '1', 0, '', 0, '0', 0, 0,
     '', ?, '', '', 0, 0, NULL,
     '0', '', '0', '1', '0',
     '', '', '', '',
     '', '0.00', '0', '0', '0', 1,
     0, '{\"post_count\":0,\"album_count\":0,\"following_count\":0,\"followers_count\":0,\"groups_count\":0,\"likes_count\":0}', NULL, 0, 0,
     0, 0, 0, '', 0,
     1, NULL, 0, '', '',
     0, '', '', '', '', '', 0,
     'us', '', 0, 0, NULL, NULL,
     NULL, '', 0, '', 0, '',
     '', 'two_factor', '0', 0)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    active = '1',
    admin = VALUES(admin),
    type = 'user',
    two_factor = 0,
    banned = 0";

$seeded = [];
foreach ($users as $user) {
    $values = [
        mysqli_real_escape_string($mysqli, $user['username']),
        mysqli_real_escape_string($mysqli, $user['email']),
        mysqli_real_escape_string($mysqli, password_hash($password, PASSWORD_DEFAULT)),
        mysqli_real_escape_string($mysqli, $user['first_name']),
        mysqli_real_escape_string($mysqli, $user['last_name']),
        mysqli_real_escape_string($mysqli, $user['admin']),
        mysqli_real_escape_string($mysqli, date('n/Y')),
        (string) time(),
    ];

    $userSql = preg_replace_callback('/\?/', static function () use (&$values): string {
        $value = array_shift($values);
        if ($value === null) {
            throw new RuntimeException('Disposable user seed placeholder underflow.');
        }
        return "'" . $value . "'";
    }, $sql);

    if ($userSql === null || count($values) !== 0) {
        fwrite(STDERR, 'Disposable user seed placeholder mismatch.' . PHP_EOL);
        exit(1);
    }

    if (!mysqli_query($mysqli, $userSql)) {
        fwrite(STDERR, 'Unable to seed disposable user ' . $user['username'] . ': ' . mysqli_error($mysqli) . PHP_EOL);
        exit(1);
    }
    $seeded[] = [
        'username' => $user['username'],
        'admin' => $user['admin'],
    ];
}

echo json_encode([
    'database' => $sql_db_name,
    'seeded' => $seeded,
    'password_label' => 'disposable Phase 1 runtime password',
], JSON_PRETTY_PRINT) . PHP_EOL;
