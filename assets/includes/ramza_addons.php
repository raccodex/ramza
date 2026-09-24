<?php
declare(strict_types=1);

/**
 * Central catalogue and local enablement checks for bundled Ramza features.
 * The open-source edition does not depend on a remote entitlement service.
 */

function Ramza_AddonCatalog(): array
{
    return [
        'algorithm_pro' => [
            'name' => 'Algorithm Pro',
            'description' => 'Personalized ranking for home feeds and reels.',
            'setting' => 'algorithm_system',
        ],
        'site_assistant' => [
            'name' => 'Site Assistant',
            'description' => 'Site-aware AI search and confirmed account actions.',
            'setting' => 'site_assistant_system',
        ],
        'webrtc_pro' => [
            'name' => 'WebRTC Pro',
            'description' => 'Browser audio/video calls and small-session live streaming.',
            'setting' => '',
        ],
        'cloudflare_image' => [
            'name' => 'Cloudflare Image',
            'description' => 'Image generation through Cloudflare Workers AI.',
            'setting' => 'cloudflare_ai_image_system',
        ],
    ];
}

function Ramza_AddonValidId(string $feature): bool
{
    return array_key_exists(strtolower(trim($feature)), Ramza_AddonCatalog());
}

function Ramza_AddonLicensed(string $feature): bool
{
    $feature = strtolower(trim($feature));
    return Ramza_AddonValidId($feature);
}

function Ramza_AddonEnabled(string $feature): bool
{
    global $wo;
    $feature = strtolower(trim($feature));
    $catalog = Ramza_AddonCatalog();
    if (!isset($catalog[$feature]) || !Ramza_AddonLicensed($feature)) {
        return false;
    }
    $setting = (string) ($catalog[$feature]['setting'] ?? '');
    return $setting === '' || (string) ($wo['config'][$setting] ?? '0') === '1';
}

function Ramza_AddonPurchaseUrl(string $feature): string
{
    global $wo;
    $feature = strtolower(trim($feature));
    $catalog = Ramza_AddonCatalog();
    $name = (string) ($catalog[$feature]['name'] ?? 'Ramza add-on');
    $site = (string) ($wo['config']['site_url'] ?? '');
    $message = "Hello RACCodex, I want to purchase {$name}.";
    if ($site !== '') {
        $message .= " Site: {$site}";
    }
    return 'https://wa.me/923464056113?text=' . rawurlencode($message);
}

function Ramza_AddonState(string $feature): array
{
    global $wo;
    $feature = strtolower(trim($feature));
    $catalog = Ramza_AddonCatalog();
    $definition = $catalog[$feature] ?? ['name' => 'Add-on', 'description' => '', 'setting' => ''];
    $licensed = Ramza_AddonLicensed($feature);
    $setting = (string) ($definition['setting'] ?? '');
    $configured = $setting === '' || (string) ($wo['config'][$setting] ?? '0') === '1';
    return $definition + [
        'id' => $feature,
        'licensed' => $licensed,
        'enabled' => $licensed && $configured,
        'purchase_url' => Ramza_AddonPurchaseUrl($feature),
    ];
}

function Ramza_AddonRequire(string $feature, bool $json = true): void
{
    if (Ramza_AddonLicensed($feature)) {
        return;
    }
    $state = Ramza_AddonState($feature);
    if ($json) {
        http_response_code(402);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'status' => 402,
            'error' => 'addon_license_required',
            'addon' => $state['id'],
            'message' => $state['name'] . ' license required.',
            'purchase_url' => $state['purchase_url'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: ' . $state['purchase_url']);
    exit;
}

function Ramza_AddonAdminBanner(string $feature): string
{
    $state = Ramza_AddonState($feature);
    $label = 'Included';
    $class = 'is-licensed';
    $action = '';
    return '<section class="ramza-addon-banner ' . $class . '"><div><strong>'
        . htmlspecialchars((string) $state['name'], ENT_QUOTES, 'UTF-8')
        . '</strong><span>' . htmlspecialchars((string) $state['description'], ENT_QUOTES, 'UTF-8')
        . '</span></div><div class="ramza-addon-status"><em>' . $label . '</em>' . $action . '</div></section>';
}
