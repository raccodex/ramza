<?php
declare(strict_types=1);
require_once __DIR__ . '/../assets/includes/ramza_updater.php';

function expectVersions(array $result, string $current, array $expected): void
{
    $actual = array_column(Ramza_UpdateAvailableReleases($result, $current), 'version');
    if ($actual !== $expected) {
        throw new RuntimeException(json_encode(['expected' => $expected, 'actual' => $actual]));
    }
}

expectVersions(['releases' => [
    ['version' => '1.0.10'], ['version' => '1.0.3'], ['version' => '1.0.2'],
    ['version' => '1.0.2'], ['version' => '1.0'], ['version' => 'invalid'], [],
]], '1.0', ['1.0.2', '1.0.3', '1.0.10']);
expectVersions(['releases' => [['version' => '1.0.7'], ['version' => '1.0.7-beta.10'], ['version' => '1.0.7-beta.2']]], '1.0.7-beta.1', ['1.0.7-beta.2', '1.0.7-beta.10', '1.0.7']);
expectVersions(['release' => ['version' => '1.0.5']], '1.0', ['1.0.5']);
expectVersions(['release' => ['version' => '1.0.5']], '1.0.5', []);
expectVersions(['releases' => [], 'release' => ['version' => '1.0.5']], '1.0', []);
echo "PASS chronological release queue, semantic versions, duplicates, invalid versions, legacy fallback and installed filtering\n";
