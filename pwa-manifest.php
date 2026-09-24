<?php
require_once('assets/init.php');

header('Content-Type: application/manifest+json; charset=utf-8');

$siteUrl = rtrim($wo['config']['site_url'], '/');

$pwaText = static function ($key, $default = '') use ($wo) {
    $value = trim((string)($wo['config'][$key] ?? $default));
    return $value !== '' ? $value : $default;
};

$pwaColor = static function ($key, $default) use ($wo) {
    $value = trim((string)($wo['config'][$key] ?? $default));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $default;
};

$pwaUrl = static function ($value, $default = '/') use ($siteUrl) {
    $value = trim((string)$value);
    if ($value === '') {
        $value = $default;
    }
    if (preg_match('/^https?:\/\//i', $value)) {
        return $value;
    }
    if ($value[0] !== '/') {
        $value = '/' . $value;
    }
    return $siteUrl . $value;
};

$pwaIcon = static function ($key) use ($wo) {
    if (!empty($wo['config'][$key])) {
        return Wo_GetMedia($wo['config'][$key]);
    }
    return $wo['config']['theme_url'] . '/img/icon.png';
};

$pwaIconType = static function ($key) use ($wo) {
    $path = !empty($wo['config'][$key]) ? $wo['config'][$key] : 'icon.png';
    $extension = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    if (in_array($extension, array('jpg', 'jpeg'), true)) {
        return 'image/jpeg';
    }
    return 'image/png';
};

$display = $pwaText('pwa_display', 'standalone');
if (!in_array($display, array('standalone', 'minimal-ui', 'fullscreen', 'browser'), true)) {
    $display = 'standalone';
}

$manifest = array(
    'name' => $pwaText('pwa_app_name', $wo['config']['siteName'] ?? 'Ramza'),
    'short_name' => substr($pwaText('pwa_short_name', $wo['config']['siteName'] ?? 'Ramza'), 0, 24),
    'description' => $pwaText('pwa_description', $wo['config']['siteDesc'] ?? 'Ramza social community'),
    'start_url' => $pwaUrl($wo['config']['pwa_start_url'] ?? '/', '/'),
    'scope' => $pwaUrl($wo['config']['pwa_scope'] ?? '/', '/'),
    'display' => $display,
    'background_color' => $pwaColor('pwa_background_color', '#f6f7f9'),
    'theme_color' => $pwaColor('pwa_theme_color', '#c94b57'),
    'orientation' => 'portrait-primary',
    'icons' => array(
        array(
            'src' => $pwaIcon('pwa_icon_192'),
            'sizes' => '192x192',
            'type' => $pwaIconType('pwa_icon_192'),
            'purpose' => 'any maskable'
        ),
        array(
            'src' => $pwaIcon('pwa_icon_512'),
            'sizes' => '512x512',
            'type' => $pwaIconType('pwa_icon_512'),
            'purpose' => 'any maskable'
        )
    )
);

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
