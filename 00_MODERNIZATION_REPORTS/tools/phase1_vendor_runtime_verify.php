<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reportDir = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS';
$summaryFile = $reportDir . DIRECTORY_SEPARATOR . '08_VENDOR_INVENTORY_SUMMARY.csv';
$outputFile = $reportDir . DIRECTORY_SEPARATOR . '12_VENDOR_RUNTIME_VERIFICATION.csv';

function rel_path(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $real = str_replace('\\', '/', realpath($path) ?: $path);
    if (strpos($real, $root . '/') === 0) {
        return substr($real, strlen($root) + 1);
    }
    return $real;
}

function run_php_autoload(string $autoload): array
{
    $code = 'error_reporting(E_ALL); ini_set("display_errors","1"); require ' . var_export($autoload, true) . '; echo "autoload-ok";';
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['status' => 'fail', 'exit_code' => -1, 'output' => 'Unable to start PHP process'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $output = trim($stdout . "\n" . $stderr);
    $marker = preg_match('/Fatal error|Parse error|Deprecated:|Warning:|Notice:|Uncaught|Stack trace/i', $output) ? 'yes' : 'no';
    return [
        'status' => ($exit === 0 && $marker === 'no') ? 'pass' : 'fail',
        'exit_code' => $exit,
        'output' => $output,
        'marker' => $marker,
    ];
}

$autoloads = [];
$handle = fopen($summaryFile, 'rb');
if ($handle !== false) {
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $lockPath = $row[0] ?? '';
        if ($lockPath === '') {
            continue;
        }
        $dir = dirname($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $lockPath));
        $autoloads[$dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'] = 'composer-lock-root';
    }
    fclose($handle);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'libraries', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getFilename() !== 'autoload.php') {
        continue;
    }
    $path = $fileInfo->getPathname();
    $relative = str_replace('\\', '/', rel_path($root, $path));
    if (preg_match('#^assets/libraries/[^/]+/vendor/autoload\.php$#', $relative) || preg_match('#^assets/libraries/[^/]+/autoload\.php$#', $relative)) {
        $autoloads[$path] = $autoloads[$path] ?? 'extra-runtime-autoload';
    }
}

ksort($autoloads, SORT_NATURAL | SORT_FLAG_CASE);

$rows = [];
foreach ($autoloads as $autoload => $source) {
    if (!is_file($autoload)) {
        $relativeMissing = rel_path($root, $autoload);
        $status = $relativeMissing === 'assets/libraries/vendor/autoload.php' ? 'stale-lock-no-runtime-autoload' : 'missing-autoload';
        $rows[] = [$relativeMissing, $source, $status, '', '', '', ''];
        continue;
    }
    $result = run_php_autoload($autoload);
    $preview = preg_replace('/\s+/', ' ', substr($result['output'], 0, 220)) ?? '';
    $rows[] = [
        rel_path($root, $autoload),
        $source,
        $result['status'],
        (string) $result['exit_code'],
        $result['marker'] ?? 'no',
        hash('sha256', $result['output']),
        $preview,
    ];
}

$out = fopen($outputFile, 'wb');
if ($out === false) {
    fwrite(STDERR, "Unable to write $outputFile\n");
    exit(1);
}
fputcsv($out, ['autoload_path', 'source', 'status', 'exit_code', 'php_error_marker', 'output_sha256', 'output_preview']);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);

$summary = ['pass' => 0, 'fail' => 0, 'missing-autoload' => 0, 'stale-lock-no-runtime-autoload' => 0];
foreach ($rows as $row) {
    if (isset($summary[$row[2]])) {
        $summary[$row[2]]++;
    }
}

echo json_encode([
    'results' => str_replace('\\', '/', rel_path($root, $outputFile)),
    'summary' => $summary,
], JSON_PRETTY_PRINT) . PHP_EOL;
