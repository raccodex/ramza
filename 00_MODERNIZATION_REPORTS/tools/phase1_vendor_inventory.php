<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reportDir = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS';
$outputFile = $reportDir . DIRECTORY_SEPARATOR . '08_VENDOR_INVENTORY.csv';
$summaryFile = $reportDir . DIRECTORY_SEPARATOR . '08_VENDOR_INVENTORY_SUMMARY.csv';

function rel_path(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $real = str_replace('\\', '/', realpath($path) ?: $path);
    if (strpos($real, $root . '/') === 0) {
        return substr($real, strlen($root) + 1);
    }
    return $real;
}

function write_csv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open $path for writing\n");
        exit(1);
    }
    fputcsv($handle, $header);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
}

$locks = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getFilename() !== 'composer.lock') {
        continue;
    }
    $relative = rel_path($root, $fileInfo->getPathname());
    if (stripos($relative, '00_MODERNIZATION_REPORTS/') === 0) {
        continue;
    }
    $locks[] = $fileInfo->getPathname();
}

sort($locks, SORT_NATURAL | SORT_FLAG_CASE);

$rows = [];
$summary = [];

foreach ($locks as $lockPath) {
    $relativeLock = rel_path($root, $lockPath);
    $json = json_decode((string) file_get_contents($lockPath), true);
    if (!is_array($json)) {
        $rows[] = [$relativeLock, '', '', '', '', '', 'invalid-json'];
        continue;
    }

    $packages = array_merge($json['packages'] ?? [], $json['packages-dev'] ?? []);
    $summary[$relativeLock] = count($packages);

    foreach ($packages as $package) {
        $require = $package['require'] ?? [];
        $phpRequire = is_array($require) && isset($require['php']) ? (string) $require['php'] : '';
        $abandoned = $package['abandoned'] ?? '';
        if (is_bool($abandoned)) {
            $abandoned = $abandoned ? 'true' : 'false';
        } elseif (is_string($abandoned)) {
            $abandoned = $abandoned === '' ? 'false' : $abandoned;
        } else {
            $abandoned = 'false';
        }

        $rows[] = [
            $relativeLock,
            (string) ($package['name'] ?? ''),
            (string) ($package['version'] ?? ''),
            $phpRequire,
            (string) ($package['type'] ?? ''),
            $abandoned,
            'locked-no-upgrade',
        ];
    }
}

$summaryRows = [];
foreach ($summary as $lock => $count) {
    $summaryRows[] = [$lock, (string) $count];
}

write_csv($outputFile, ['lock_path', 'package', 'version', 'php_requirement', 'type', 'abandoned', 'phase1_action'], $rows);
write_csv($summaryFile, ['lock_path', 'package_count'], $summaryRows);

echo json_encode([
    'locks' => count($locks),
    'packages' => count($rows),
    'inventory' => str_replace('\\', '/', rel_path($root, $outputFile)),
    'summary' => str_replace('\\', '/', rel_path($root, $summaryFile)),
], JSON_PRETTY_PRINT) . PHP_EOL;
