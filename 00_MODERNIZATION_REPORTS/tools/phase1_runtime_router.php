<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = $path === null ? '/' : rawurldecode($path);

if ($path === '/') {
    header('Location: /index.php?link1=welcome', true, 302);
    exit;
}

$file = realpath($root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));

if ($file !== false && is_file($file) && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/') === 0) {
    return false;
}

if ($file !== false && is_dir($file)) {
    return false;
}

$segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
$route = $segments[0] ?? '';

if (in_array($route, ['admin-cp', 'admincp'], true)) {
    if (isset($segments[1])) {
        $_GET['page'] = $segments[1];
        $_REQUEST['page'] = $segments[1];
    }
    chdir($root);
    require $root . DIRECTORY_SEPARATOR . 'admincp.php';
    return true;
}

if ($route !== '') {
    $_GET['link1'] = $route;
    $_REQUEST['link1'] = $route;

    if (isset($segments[1])) {
        $second = $segments[1];
        if ($route === 'post') {
            $_GET['id'] = $second;
            $_REQUEST['id'] = $second;
        } elseif ($route === 'watch') {
            $_GET['id'] = $second;
            $_REQUEST['id'] = $second;
        } elseif ($route === 'password-reset') {
            $_GET['link1'] = 'welcome';
            $_REQUEST['link1'] = 'welcome';
            $_GET['link2'] = 'password_reset';
            $_REQUEST['link2'] = 'password_reset';
            $_GET['user_id'] = $second;
            $_REQUEST['user_id'] = $second;
        } else {
            $_GET['link2'] = $second;
            $_REQUEST['link2'] = $second;
        }
    }
}

chdir($root);
require $root . DIRECTORY_SEPARATOR . 'index.php';
