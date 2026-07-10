<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reportDir = $root . DIRECTORY_SEPARATOR . '00_MODERNIZATION_REPORTS';
$inventoryFile = $reportDir . DIRECTORY_SEPARATOR . '03_PHP82_LINT_INVENTORY.csv';
$candidateFile = $reportDir . DIRECTORY_SEPARATOR . '04_PHP82_STATIC_SCAN_CANDIDATES.csv';
$summaryFile = $reportDir . DIRECTORY_SEPARATOR . '04_PHP82_STATIC_SCAN_SUMMARY.csv';

function read_inventory(string $inventoryFile): array
{
    $rows = [];
    $handle = fopen($inventoryFile, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Missing inventory file: $inventoryFile\n");
        exit(1);
    }
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 2) {
            $rows[] = ['batch' => $row[0], 'path' => $row[1]];
        }
    }
    fclose($handle);
    return $rows;
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

function category_status(string $category, string $sourceLine): string
{
    if ($category === 'deprecated_dollar_brace_interpolation' && preg_match("/'[^']*\\$\\{[^']*'/", $sourceLine)) {
        return 'false-positive-literal-placeholder';
    }

    $needsFix = [
        'removed_api',
        'curly_string_offset',
        'deprecated_dollar_brace_interpolation',
        'filter_sanitize_string',
        'required_after_optional_parameter',
        'implode_legacy_order',
    ];

    if (in_array($category, $needsFix, true)) {
        return 'needs-fix-or-explicit-false-positive';
    }

    return 'needs-review';
}

function add_candidate(array &$rows, string $category, string $path, string $batch, int $line, string $evidence, string $sourceLine): void
{
    $rows[] = [
        $category,
        $path,
        (string) $line,
        $batch,
        $evidence,
        category_status($category, $sourceLine),
        hash('sha256', $sourceLine),
    ];
}

function php_code_lines(string $source): array
{
    $tokens = token_get_all($source);
    $lines = [];
    $currentLine = 1;

    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$id, $text, $startLine] = $token;
            if ($startLine > $currentLine) {
                $currentLine = $startLine;
            }
            $include = !in_array($id, [T_INLINE_HTML, T_COMMENT, T_DOC_COMMENT], true);
        } else {
            $text = $token;
            $include = true;
        }

        $parts = preg_split('/\R/', $text);
        if ($parts === false) {
            continue;
        }

        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($include && $part !== '') {
                $lines[$currentLine] = ($lines[$currentLine] ?? '') . $part;
            }
            if ($index < $last) {
                $currentLine++;
            }
        }
    }

    ksort($lines);
    return $lines;
}

function has_required_after_optional(string $line): bool
{
    if (!preg_match('/function\s+&?\s*[A-Za-z_][A-Za-z0-9_]*\s*\(([^)]*)\)/', $line, $match)) {
        return false;
    }

    $params = array_map('trim', explode(',', $match[1]));
    $sawOptional = false;
    foreach ($params as $param) {
        if ($param === '' || strpos($param, '...') !== false) {
            continue;
        }
        $hasDefault = strpos($param, '=') !== false;
        if ($hasDefault) {
            $sawOptional = true;
            continue;
        }
        if ($sawOptional && strpos($param, '$') !== false) {
            return true;
        }
    }

    return false;
}

$inventory = read_inventory($inventoryFile);
$rows = [];

$patterns = [
    'removed_api' => [
        '/\b(mysql_(connect|pconnect|query|fetch_[A-Za-z0-9_]*|num_[A-Za-z0-9_]*|real_escape_string|select_db)|ereg(_replace)?|split|create_function|each|get_magic_quotes_gpc|set_magic_quotes_runtime|mcrypt_[A-Za-z0-9_]*)\s*\(/i' => 'removed PHP API function call',
    ],
    'curly_string_offset' => [
        '/\$[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]+\]|->[A-Za-z_][A-Za-z0-9_]*)?\s*\{\s*[^$}][^}]*\}/' => 'curly brace offset access',
    ],
    'deprecated_dollar_brace_interpolation' => [
        '/\$\{[^}]+\}/' => 'deprecated ${} interpolation candidate',
    ],
    'static_call' => [
        '/\b[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*\b/' => 'static call candidate',
    ],
    'callback_signature' => [
        '/\b(call_user_func|call_user_func_array|array_map|array_filter|array_walk|usort|uasort|uksort|preg_replace_callback|set_error_handler|set_exception_handler|register_shutdown_function)\s*\(/i' => 'callback/callable candidate',
    ],
    'count_nullable' => [
        '/\bcount\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*|[A-Za-z_][A-Za-z0-9_]*\s*\()/i' => 'count() nullable/non-countable candidate',
    ],
    'null_scalar_array_access' => [
        '/\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)?\s*\[[^\]]+\]/' => 'array offset on possibly null/scalar value',
    ],
    'null_internal_function_argument' => [
        '/\b(trim|strlen|strtolower|strtoupper|ucfirst|ucwords|htmlspecialchars|htmlentities|strip_tags|number_format|explode|strpos|str_replace|preg_match|preg_replace|json_decode)\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*|\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES))/i' => 'internal function nullable argument candidate',
    ],
    'implode_legacy_order' => [
        '/\bimplode\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*|\[[^\)]*\])\s*,/i' => 'implode() legacy argument order candidate',
    ],
    'filter_sanitize_string' => [
        '/\bFILTER_SANITIZE_STRING\b/' => 'FILTER_SANITIZE_STRING removed/deprecated candidate',
    ],
    'utf8_encode_decode' => [
        '/\butf8_(encode|decode)\s*\(/i' => 'utf8_encode/decode deprecation candidate',
    ],
    'dynamic_property_write' => [
        '/\$[A-Za-z_][A-Za-z0-9_]*->[A-Za-z_][A-Za-z0-9_]*\s*=/' => 'dynamic property write candidate',
    ],
    'interface_or_signature' => [
        '/\b(class|interface|trait)\b|\b(implements|extends)\b|\bfunction\s+__/' => 'class/interface/signature candidate',
    ],
    'direct_request_array' => [
        '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[[^\]]+\]/' => 'direct request superglobal access',
    ],
    'request_value_string_function' => [
        '/\b(trim|strlen|strtolower|strtoupper|htmlspecialchars|htmlentities|strip_tags|explode|strpos|str_replace|preg_match|preg_replace)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[/i' => 'string/internal function called directly on request value',
    ],
    'loose_comparison_security' => [
        '/(?<![=!<>])(?:==|!=|<>)(?![=])/' => 'loose comparison candidate',
        '/\bin_array\s*\([^;\n]*\)(?!\s*,\s*true)/i' => 'in_array strict flag review candidate',
        '/\barray_search\s*\(/i' => 'array_search strict flag review candidate',
    ],
    'error_suppression' => [
        '/(?<![A-Za-z0-9_])@(?=\$|[A-Za-z_])/' => 'error suppression operator candidate',
    ],
    'raw_mysqli' => [
        '/\bmysqli_[A-Za-z0-9_]+\s*\(|->\s*(query|prepare|multi_query|real_escape_string|set_charset)\s*\(/i' => 'raw mysqli/query call candidate',
    ],
];

foreach ($inventory as $item) {
    $path = $item['path'];
    $batch = $item['batch'];
    $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $source = @file_get_contents($fullPath);
    if ($source === false) {
        add_candidate($rows, 'scan_error', $path, $batch, 0, 'file unreadable during scan', '');
        continue;
    }

    foreach (php_code_lines($source) as $lineNo => $line) {

        foreach ($patterns as $category => $regexes) {
            foreach ($regexes as $regex => $evidence) {
                if (preg_match($regex, $line)) {
                    add_candidate($rows, $category, $path, $batch, $lineNo, $evidence, $line);
                }
            }
        }

        if (has_required_after_optional($line)) {
            add_candidate($rows, 'required_after_optional_parameter', $path, $batch, $lineNo, 'required parameter after optional parameter candidate', $line);
        }
    }
}

usort($rows, static function (array $a, array $b): int {
    return [$a[0], $a[1], (int) $a[2], $a[4]] <=> [$b[0], $b[1], (int) $b[2], $b[4]];
});

$summary = [];
foreach ($rows as $row) {
    $key = $row[0] . '|' . $row[5];
    if (!isset($summary[$key])) {
        $summary[$key] = [$row[0], $row[5], 0];
    }
    $summary[$key][2]++;
}

usort($summary, static function (array $a, array $b): int {
    return [$a[0], $a[1]] <=> [$b[0], $b[1]];
});

write_csv($candidateFile, ['category', 'path', 'line', 'batch', 'evidence', 'classification', 'line_sha256'], $rows);
write_csv($summaryFile, ['category', 'classification', 'count'], $summary);

echo json_encode([
    'candidates' => str_replace('\\', '/', substr($candidateFile, strlen($root) + 1)),
    'summary' => str_replace('\\', '/', substr($summaryFile, strlen($root) + 1)),
    'total' => count($rows),
], JSON_PRETTY_PRINT) . PHP_EOL;
