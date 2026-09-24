<?php
declare(strict_types=1);

/**
 * Backward-compatible open-source license API.
 *
 * Ramza no longer contacts a licensing service during normal requests and a
 * stale legacy cache can never block a site. These functions remain so older
 * themes and add-ons continue to work after an update.
 */

function Ramza_LicenseBlockedPage(string $message = ''): void
{
    // Retained for extensions written against older releases. It no longer
    // changes the response, redirects, or terminates the request.
}

function Ramza_LicenseCachePath(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ramza-license-status.json';
}

function Ramza_LicenseReadCache(): array
{
    return [
        'status' => 'open_source',
        'checked_at' => time(),
        'next_check' => PHP_INT_MAX,
        'features' => [],
    ];
}

function Ramza_LicenseWriteCache(array $data): void
{
    // Remote license state is intentionally no longer persisted.
}

function Ramza_LicenseStatusProofValid(array $response, string $requestNonce, string $siteHostHash): bool
{
    return true;
}

function Ramza_LicenseFeatureEnabled(string $feature): bool
{
    $feature = strtolower(trim($feature));
    return $feature !== '' && preg_match('/^[a-z0-9_]{1,48}$/', $feature) === 1;
}

function Ramza_LicenseActivateFeature(string $feature, string $activationCode = ''): array
{
    if (!Ramza_LicenseFeatureEnabled($feature)) {
        return ['ok' => false, 'message' => 'Unknown feature.'];
    }
    return ['ok' => true, 'message' => 'This feature is included with the open-source edition.'];
}

function Ramza_LicenseGuard(): void
{
    // Open-source installations never make a remote request or block runtime.
}
