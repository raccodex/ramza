<?php
declare(strict_types=1);

const RACSOCIAL_INSTALLER_VERSION = '2.0.0-php82';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

foreach (glob(__DIR__ . '/src/*.php') ?: [] as $installerSource) {
    require_once $installerSource;
}

function rac_installer_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rac_installer_post_string(string $key, int $maxLength = 4096): ?string
{
    if (!array_key_exists($key, $_POST)) {
        return null;
    }
    if (is_array($_POST[$key])) {
        throw new RACInstallerUserException('Invalid form input shape. Please reload the installer and try again.');
    }
    $value = (string) $_POST[$key];
    if (strlen($value) > $maxLength) {
        throw new RACInstallerUserException('One of the submitted values is too long.');
    }
    return $value;
}

function rac_installer_redirect(string $step): never
{
    header('Location: ?step=' . rawurlencode($step), true, 303);
    exit;
}

function rac_installer_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        return true;
    }
    return false;
}
