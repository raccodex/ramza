<?php
declare(strict_types=1);

// Isolate the manifest handler so tests never contact a real portal or license.
$path = __DIR__ . '/../developer-tools/license-server-one-click/app/main.php';
$source = (string) file_get_contents($path);
if (!preg_match('/function ramza_license_handle_update_manifest\(\): never\s*\{.*?(?=\nfunction ramza_license_handle_update_verify)/s', $source, $match)) {
    throw new RuntimeException('Manifest handler not found.');
}
eval($match[0]);

final class ManifestResponse extends RuntimeException
{
    public function __construct(public array $payload) { parent::__construct('response'); }
}
function ramza_license_authorize_update_request(): array
{
    return ['license_id' => 'test-license', 'claim' => ['update_channel' => $GLOBALS['test_channel']]];
}
function ramza_license_post(string $key, int $limit): string { return $GLOBALS['test_current']; }
function ramza_license_update_catalog(): array { return $GLOBALS['test_catalog']; }
function ramza_license_update_download_url(array $entry, string $license): string { return 'https://example.test/download/' . $entry['version']; }
function ramza_license_json(int $status, array $payload): never { throw new ManifestResponse($payload); }

$test_catalog = [
    ['version' => '1.0.10', 'channel' => 'public', 'file' => '/private/release.zip'],
    ['version' => '1.0.4-beta.1', 'channel' => 'beta', 'file' => '/private/beta.zip'],
    ['version' => '1.0.3', 'channel' => 'public', 'file' => '/private/third.zip'],
    ['version' => '1.0.2', 'channel' => 'public', 'file' => '/private/second.zip'],
];
foreach ([['1.0', 'public', ['1.0.2', '1.0.3', '1.0.10']], ['1.0.2', 'beta', ['1.0.3', '1.0.4-beta.1', '1.0.10']], ['1.0.10', 'public', []]] as [$test_current, $test_channel, $expected]) {
    try { ramza_license_handle_update_manifest(); } catch (ManifestResponse $response) {
        $data = $response->payload;
        if (array_column($data['releases'], 'version') !== $expected || ($data['release']['version'] ?? null) !== ($expected[0] ?? null)) {
            throw new RuntimeException('Incorrect next release or chronological list.');
        }
        foreach ($data['releases'] as $release) {
            if (isset($release['file']) || empty($release['url'])) throw new RuntimeException('Private path exposed or download URL absent.');
        }
    }
}
echo "PASS portal manifest order, next release, channel filtering, installed filtering and private-path exclusion\n";
