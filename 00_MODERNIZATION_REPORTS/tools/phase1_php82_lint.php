<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reportDir = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS';
$inventoryFile = $reportDir . DIRECTORY_SEPARATOR . '03_PHP82_LINT_INVENTORY.csv';
$exclusionFile = $reportDir . DIRECTORY_SEPARATOR . '03_PHP82_LINT_EXCLUSIONS.csv';
$resultFile = $reportDir . DIRECTORY_SEPARATOR . '03_PHP82_LINT_RESULTS.csv';

function rel_path(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $real = str_replace('\\', '/', realpath($path) ?: $path);
    if (strpos($real, $root . '/') === 0) {
        return substr($real, strlen($root) + 1);
    }
    return $real;
}

function is_php_candidate(string $relative): bool
{
    return (bool) preg_match('/\.(php|phtml)$/i', $relative);
}

function is_nodejs_template(string $relative): bool
{
    return (bool) preg_match('#^themes/[^/]+/layout/nodejs/#i', $relative);
}

function is_excluded_path(string $relative): bool
{
    $blockedPrefixes = [
        '.git/',
        '00_MODERNIZATION_REPORTS/',
        'assets/libraries/',
        'backup/',
        'backups/',
        'cache/',
        'changes/',
        'logs/',
        'node_modules/',
        'upload/',
    ];

    foreach ($blockedPrefixes as $prefix) {
        if (stripos($relative, $prefix) === 0) {
            return true;
        }
    }

    return is_nodejs_template($relative);
}

function batch_for(string $relative): string
{
    if (strpos($relative, '/') === false) {
        if (preg_match('/^(cron|expire_pro|update|updater)/i', basename($relative))) {
            return 'cron-updater';
        }
        return 'root';
    }

    if (stripos($relative, 'assets/') === 0) {
        return 'assets-first-party';
    }
    if (stripos($relative, 'sources/') === 0) {
        return 'sources';
    }
    if (stripos($relative, 'xhr/') === 0) {
        return 'xhr';
    }
    if (stripos($relative, 'api/') === 0) {
        return 'api';
    }
    if (stripos($relative, 'admin-panel/') === 0) {
        return 'admin-panel';
    }
    if (stripos($relative, 'themes/wowonder/') === 0) {
        return 'themes-wowonder';
    }
    if (stripos($relative, 'themes/sunshine/') === 0) {
        return 'themes-sunshine';
    }
    if (stripos($relative, 'install/') === 0 || stripos($relative, 'installer/') === 0) {
        return 'installer';
    }

    return 'other-first-party';
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

function build_inventory(string $root, string $inventoryFile, string $exclusionFile): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $candidates = [];
    $exclusions = [];

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $relative = rel_path($root, $fileInfo->getPathname());
        $relative = str_replace('\\', '/', $relative);

        if (!is_php_candidate($relative)) {
            continue;
        }

        if (is_nodejs_template($relative)) {
            $exclusions[] = [$relative, 'Excluded Phase 1 Handlebars/nodejs theme template, not proven PHP-executable.'];
            continue;
        }

        if (is_excluded_path($relative)) {
            if (stripos($relative, 'assets/libraries/') === 0) {
                $exclusions[] = [$relative, 'Excluded third-party assets/libraries dependency code.'];
            }
            continue;
        }

        $candidates[] = [batch_for($relative), $relative];
    }

    usort($candidates, static function (array $a, array $b): int {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    });

    usort($exclusions, static function (array $a, array $b): int {
        return $a[0] <=> $b[0];
    });

    write_csv($inventoryFile, ['batch', 'path'], $candidates);
    write_csv($exclusionFile, ['path', 'reason'], $exclusions);

    return $candidates;
}

function load_inventory(string $inventoryFile): array
{
    $rows = [];
    $handle = fopen($inventoryFile, 'rb');
    if ($handle === false) {
        return $rows;
    }

    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 2) {
            continue;
        }
        $rows[] = [$row[0], $row[1]];
    }
    fclose($handle);

    return $rows;
}

function append_results(string $resultFile, array $rows, bool $reset): void
{
    $exists = file_exists($resultFile);
    $handle = fopen($resultFile, $reset || !$exists ? 'wb' : 'ab');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open $resultFile for writing\n");
        exit(1);
    }

    if ($reset || !$exists) {
        fputcsv($handle, ['batch', 'path', 'status', 'error']);
    }

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);
}

function arg_value(array $argv, string $name, string $default): string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $name . '=') === 0) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return $default;
}

$mode = $argv[1] ?? 'inventory';

if ($mode === 'inventory') {
    $candidates = build_inventory($root, $inventoryFile, $exclusionFile);
    $byBatch = [];
    foreach ($candidates as $candidate) {
        $byBatch[$candidate[0]] = ($byBatch[$candidate[0]] ?? 0) + 1;
    }
    ksort($byBatch);
    echo json_encode([
        'inventory' => str_replace('\\', '/', rel_path($root, $inventoryFile)),
        'exclusions' => str_replace('\\', '/', rel_path($root, $exclusionFile)),
        'total' => count($candidates),
        'by_batch' => $byBatch,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

if ($mode !== 'lint') {
    fwrite(STDERR, "Usage: php phase1_php82_lint.php inventory|lint [--offset=N] [--limit=N] [--reset=1]\n");
    exit(1);
}

if (!file_exists($inventoryFile)) {
    build_inventory($root, $inventoryFile, $exclusionFile);
}

$inventory = load_inventory($inventoryFile);
$offset = max(0, (int) arg_value($argv, '--offset', '0'));
$limit = max(1, (int) arg_value($argv, '--limit', '100'));
$reset = arg_value($argv, '--reset', '0') === '1';
$chunk = array_slice($inventory, $offset, $limit);
$php = PHP_BINARY ?: 'php';
$results = [];

foreach ($chunk as $candidate) {
    [$batch, $relative] = $candidate;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$php, '-l', $path], $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        $results[] = [$batch, $relative, 'fail', 'Unable to start PHP lint process.'];
        continue;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $message = trim(preg_replace('/\s+/', ' ', $stderr !== '' ? $stderr : $stdout));
    if ($exitCode === 0) {
        $results[] = [$batch, $relative, 'pass', ''];
    } else {
        $results[] = [$batch, $relative, 'fail', $message];
    }
}

append_results($resultFile, $results, $reset);

$passes = 0;
$fails = 0;
foreach ($results as $result) {
    if ($result[2] === 'pass') {
        $passes++;
    } else {
        $fails++;
    }
}

echo json_encode([
    'results' => str_replace('\\', '/', rel_path($root, $resultFile)),
    'offset' => $offset,
    'limit' => $limit,
    'processed' => count($results),
    'pass' => $passes,
    'fail' => $fails,
], JSON_PRETTY_PRINT) . PHP_EOL;
