<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = $path === null ? '/' : rawurldecode($path);
$file = realpath($root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));

if ($file !== false && is_file($file) && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/') === 0) {
    return false;
}

chdir($root);
require $root . DIRECTORY_SEPARATOR . 'index.php';
