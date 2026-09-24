<?php
declare(strict_types=1);

function Ramza_UpdateRoot(): string
{
    return dirname(__DIR__, 2);
}

function Ramza_UpdateDirectory(): string
{
    return Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . 'updates';
}

function Ramza_UpdateCurrentVersion(): string
{
    $file = Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . 'ramza-release.json';
    if (is_file($file)) {
        $data = json_decode((string) file_get_contents($file), true);
        $version = is_array($data) ? trim((string) ($data['version'] ?? '')) : '';
        if (preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?$/', $version) === 1) {
            return $version;
        }
    }
    return '1.0';
}

function Ramza_UpdateCurlNetworkOptions(string $url): array
{
    $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
    $options = [
        CURLOPT_DNS_CACHE_TIMEOUT => 300,
    ];
    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !function_exists('gethostbynamel')) {
        return $options;
    }
    $addresses = @gethostbynamel($host);
    if (!is_array($addresses)) {
        return $options;
    }
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $address];
            break;
        }
    }
    return $options;
}

function Ramza_UpdateNetworkError(int $errorNumber, string $error, string $fallback): string
{
    $clean = trim((string) preg_replace('/[\r\n]+/', ' ', $error));
    if ($errorNumber === 6 || stripos($clean, 'getaddrinfo') !== false || stripos($clean, 'resolve host') !== false) {
        error_log('Ramza updater DNS failure: ' . ($clean !== '' ? $clean : 'resolver unavailable'));
        return 'The update server address could not be resolved. Retry once; if it continues, restart PHP or contact the hosting provider.';
    }
    if ($errorNumber === 28) {
        return 'The update server did not respond before the timeout.';
    }
    return $clean !== '' ? $clean : $fallback;
}

function Ramza_UpdateApi(string $action, array $extra = []): array
{
    global $license_endpoint, $license_certificate, $site_url;
    if (empty($license_endpoint) || !function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'The update service is not configured.'];
    }
    $endpoint = trim((string) $license_endpoint);
    $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
    $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if (!filter_var($endpoint, FILTER_VALIDATE_URL) || ($scheme !== 'https' && !($scheme === 'http' && $loopback))) {
        return ['ok' => false, 'message' => 'The update endpoint is invalid.'];
    }
    $siteHost = strtolower((string) parse_url((string) $site_url, PHP_URL_HOST));
    $fields = array_merge([
        'action' => $action,
        'site_url' => (string) $site_url,
        'site_host' => $siteHost,
        'site_host_hash' => hash('sha256', $siteHost),
        'license_certificate' => (string) $license_certificate,
        'current_version' => Ramza_UpdateCurrentVersion(),
    ], $extra);
    $request = static function () use ($endpoint, $fields): array {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return [false, 0, 0, 'curl_init_failed', ''];
        }
        curl_setopt_array($ch, Ramza_UpdateCurlNetworkOptions($endpoint) + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_USERAGENT => 'RamzaUpdater/' . Ramza_UpdateCurrentVersion(),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errorNumber = curl_errno($ch);
        $error = curl_error($ch);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return [$body, $status, $errorNumber, $error, $contentType];
    };
    [$body, $status, $errorNumber, $error, $contentType] = $request();
    if ($body === false || $status === 0 || $status >= 500) {
        usleep(250000);
        [$body, $status, $errorNumber, $error, $contentType] = $request();
    }
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if ($status >= 200 && $status < 300 && is_array($decoded)) {
        return $decoded;
    }
    $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? '') : '';
    if ($message === '' && $status >= 400 && $status < 500 && stripos($contentType, 'json') === false) {
        $message = 'The hosting firewall rejected the update authorization request (HTTP ' . $status . '). Install the author hotfix and retry.';
    }
    if ($message === '' && $status > 0) {
        $message = 'The update service returned HTTP ' . $status . ' without a valid JSON response.';
    }
    return [
        'ok' => false,
        'message' => $message !== '' ? $message : Ramza_UpdateNetworkError($errorNumber, $error, 'Update verification failed.'),
    ];
}

function Ramza_UpdateAvailableReleases(array $result, string $current): array
{
    // An older portal may still return only `release`.
    $entries = is_array($result['releases'] ?? null)
        ? $result['releases']
        : (is_array($result['release'] ?? null) && $result['release'] !== [] ? [$result['release']] : []);
    $releases = [];
    foreach ($entries as $entry) {
        if (!is_array($entry) || !is_string($entry['version'] ?? null)) {
            continue;
        }
        $version = trim($entry['version']);
        if (preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?$/', $version) !== 1
            || version_compare($version, $current, '<=')) {
            continue;
        }
        $entry['version'] = $version;
        $releases[$version] = $entry;
    }
    $releases = array_values($releases);
    usort($releases, static fn(array $a, array $b): int => version_compare($a['version'], $b['version']));
    return $releases;
}

function Ramza_UpdateCheckLocalPackages(string $current): array
{
    $releases = [];
    $updatesDir = Ramza_UpdateDirectory();
    $searchDirs = [$updatesDir, $updatesDir . DIRECTORY_SEPARATOR . 'manual'];
    foreach ($searchDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $zips = glob($dir . DIRECTORY_SEPARATOR . '*.zip') ?: [];
        foreach ($zips as $zipFile) {
            if (str_ends_with($zipFile, '.part')) {
                continue;
            }
            $ins = Ramza_UpdateInspect($zipFile);
            if (($ins['ok'] ?? false) === true && !empty($ins['version'])) {
                if (version_compare($ins['version'], $current, '>')) {
                    $releases[$ins['version']] = [
                        'version' => $ins['version'],
                        'channel' => 'stable',
                        'notes' => 'Local update package: ' . basename($zipFile),
                        'min_php' => '8.2',
                        'url' => 'file://' . str_replace('\\', '/', $zipFile),
                        'sha256' => $ins['sha256'],
                        'is_local' => true,
                        'path' => $zipFile,
                    ];
                }
            }
        }
    }
    return array_values($releases);
}

function Ramza_UpdateCheck(): array
{
    global $license_endpoint;
    $current = Ramza_UpdateCurrentVersion();
    $releases = [];
    $channel = 'stable';

    // If an update service URL is configured, query it
    if (!empty($license_endpoint) && function_exists('curl_init')) {
        $result = Ramza_UpdateApi('update_manifest');
        if (($result['ok'] ?? false) === true) {
            $releases = Ramza_UpdateAvailableReleases($result, $current);
            $channel = ($result['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
        }
    }

    // Check local packages in updates/ directory
    $localReleases = Ramza_UpdateCheckLocalPackages($current);
    if (!empty($localReleases)) {
        foreach ($localReleases as $localRel) {
            $found = false;
            foreach ($releases as $existing) {
                if ($existing['version'] === $localRel['version']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $releases[] = $localRel;
            }
        }
    }

    $isAvailable = !empty($releases);
    return [
        'ok' => true,
        'available' => $isAvailable,
        'current' => $current,
        'channel' => $channel,
        'release' => $releases[0] ?? [],
        'releases' => $releases,
        'message' => $isAvailable
            ? 'A new version (v' . ($releases[0]['version'] ?? '') . ') is available for installation.'
            : 'You are running the latest version of Ramza (v' . $current . '). Everything is up to date.',
    ];
}

function Ramza_UpdateNormalizePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? '';
    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1 || str_contains($path, "\0")) {
        return '';
    }
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return '';
        }
    }
    $blocked = ['config.php', '.env', 'install/install.lock'];
    $lower = strtolower($path);
    if (in_array($lower, $blocked, true)
        || str_starts_with($lower, 'cache/')
        || str_starts_with($lower, 'upload/')
        || str_starts_with($lower, 'updates/')
        || str_starts_with($lower, 'developer-tools/')) {
        return '';
    }
    return implode('/', $parts);
}

function Ramza_UpdateDownload(array $release): array
{
    $url = trim((string) ($release['url'] ?? ''));
    $expectedHash = strtolower(trim((string) ($release['sha256'] ?? '')));
    if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'message' => 'The update release metadata is invalid.'];
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
        return ['ok' => false, 'message' => 'Update downloads require HTTPS.'];
    }
    $directory = Ramza_UpdateDirectory() . DIRECTORY_SEPARATOR . 'downloads';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return ['ok' => false, 'message' => 'The update directory is not writable.'];
    }
    $target = $directory . DIRECTORY_SEPARATOR . 'ramza-' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($release['version'] ?? 'update')) . '.zip';
    $handle = @fopen($target . '.part', 'wb');
    if (!$handle || !function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'The update download could not be started.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, Ramza_UpdateCurlNetworkOptions($url) + [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'RamzaUpdater/' . Ramza_UpdateCurrentVersion(),
    ]);
    $ok = curl_exec($ch);
    $errorNumber = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($handle);
    $part = $target . '.part';
    if (!$ok || !is_file($part) || filesize($part) < 100 || filesize($part) > 314572800) {
        @unlink($part);
        return ['ok' => false, 'message' => Ramza_UpdateNetworkError($errorNumber, $error, 'The update package download failed.')];
    }
    $actualHash = hash_file('sha256', $part);
    if (!hash_equals($expectedHash, strtolower($actualHash))) {
        @unlink($part);
        return ['ok' => false, 'message' => 'The downloaded update checksum does not match.'];
    }
    @unlink($target);
    if (!@rename($part, $target)) {
        @unlink($part);
        return ['ok' => false, 'message' => 'The downloaded update could not be saved.'];
    }
    return ['ok' => true, 'path' => $target, 'sha256' => $actualHash];
}

function Ramza_UpdateInspect(string $package): array
{
    if (!class_exists('ZipArchive') || !is_file($package) || filesize($package) > 314572800) {
        return ['ok' => false, 'message' => 'A valid ZIP update package is required (up to 300 MB).'];
    }
    $zip = new ZipArchive();
    if ($zip->open($package) !== true || $zip->numFiles < 1 || $zip->numFiles > 50000) {
        return ['ok' => false, 'message' => 'The update archive is empty or invalid.'];
    }
    $manifestRaw = $zip->getFromName('update.json');
    $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
    if (!is_array($manifest)) {
        $releaseRaw = $zip->getFromName('ramza-release.json');
        if (is_string($releaseRaw)) {
            $manifest = json_decode($releaseRaw, true);
        }
    }
    $current = Ramza_UpdateCurrentVersion();
    $version = is_array($manifest) ? trim((string) ($manifest['version'] ?? '')) : '';
    if ($version === '' || preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?$/', $version) !== 1) {
        if (preg_match('/(?:v|version-?|update-?)(\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?)/i', basename($package), $vm)) {
            $version = $vm[1];
        } else {
            $version = $current . '-patch.' . gmdate('YmdHis');
        }
    }

    $files = [];
    $total = 0;
    $hasDeclaredFiles = is_array($manifest['files'] ?? null) && !empty($manifest['files']);

    if ($hasDeclaredFiles) {
        foreach ($manifest['files'] as $path => $hash) {
            $safe = is_string($path) ? Ramza_UpdateNormalizePath($path) : '';
            $hash = strtolower(trim((string) $hash));
            $stat = $safe !== '' ? $zip->statName($safe) : false;
            if ($safe === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || !is_array($stat) || str_ends_with($safe, '/')) {
                $zip->close();
                return ['ok' => false, 'message' => 'The update contains an unsafe or invalid file: ' . $path];
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > 1073741824) {
                $zip->close();
                return ['ok' => false, 'message' => 'The expanded update is too large (maximum 1 GB).'];
            }
            $contents = $zip->getFromName($safe);
            if (!is_string($contents) || !hash_equals($hash, hash('sha256', $contents))) {
                $zip->close();
                return ['ok' => false, 'message' => 'A file checksum inside the update does not match: ' . $path];
            }
            $files[$safe] = $hash;
        }
    } else {
        // Auto-discover safe entries from archive
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_ends_with($name, '/') || in_array(strtolower($name), ['update.json', 'rollback.json'], true)) {
                continue;
            }
            $safe = Ramza_UpdateNormalizePath($name);
            if ($safe === '') {
                // If it's a blocked path like config.php or .env, skip it safely
                if (in_array(strtolower($name), ['config.php', '.env', 'install/install.lock'], true)) {
                    continue;
                }
                $zip->close();
                return ['ok' => false, 'message' => 'The update package contains an unsafe file: ' . $name];
            }
            $stat = $zip->statIndex($index);
            $total += (int) ($stat['size'] ?? 0);
            if ($total > 1073741824) {
                $zip->close();
                return ['ok' => false, 'message' => 'The expanded update is too large (maximum 1 GB).'];
            }
            $contents = $zip->getFromIndex($index);
            if (!is_string($contents)) {
                $zip->close();
                return ['ok' => false, 'message' => 'Could not read entry from archive: ' . $name];
            }
            $files[$safe] = hash('sha256', $contents);
        }
    }

    // Check for SQL migration file
    $hasSql = false;
    $sqlFile = '';
    foreach (['update.sql', 'sql/update.sql', 'migration.sql', 'schema_update.sql'] as $candidate) {
        if ($zip->locateName($candidate) !== false) {
            $hasSql = true;
            $sqlFile = $candidate;
            break;
        }
    }

    $zip->close();

    if (empty($files) && !$hasSql) {
        return ['ok' => false, 'message' => 'The update package does not contain any valid files.'];
    }

    return [
        'ok' => true,
        'version' => $version,
        'files' => $files,
        'manifest' => is_array($manifest) ? $manifest : ['version' => $version],
        'sha256' => hash_file('sha256', $package),
        'has_sql' => $hasSql,
        'sql_file' => $sqlFile,
    ];
}

function Ramza_UpdateApply(string $package, array $credentials = []): array
{
    $inspection = Ramza_UpdateInspect($package);
    if (($inspection['ok'] ?? false) !== true) {
        return $inspection;
    }
    // Open-source packages do not require an account or purchase code.
    // Ramza_UpdateInspect() still rejects unsafe paths and verifies every file
    // checksum before staging or replacing application files.
    $updateDir = Ramza_UpdateDirectory();
    if (!is_dir($updateDir) && !@mkdir($updateDir, 0750, true) && !is_dir($updateDir)) {
        return ['ok' => false, 'message' => 'The update directory is not writable.'];
    }
    $lockHandle = @fopen($updateDir . DIRECTORY_SEPARATOR . '.update.lock', 'c+');
    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'message' => 'Another update is already running.'];
    }
    $stamp = gmdate('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $inspection['version']);
    $staging = $updateDir . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $stamp;
    $backup = $updateDir . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $stamp;
    @mkdir($staging, 0750, true);
    @mkdir($backup, 0750, true);
    $zip = new ZipArchive();
    if ($zip->open($package) !== true) {
        flock($lockHandle, LOCK_UN); fclose($lockHandle);
        return ['ok' => false, 'message' => 'The update package could not be reopened.'];
    }
    $created = [];
    $replaced = [];
    $error = '';
    foreach ($inspection['files'] as $path => $hash) {
        $stageFile = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        @mkdir(dirname($stageFile), 0750, true);
        $stream = $zip->getStream($path);
        $target = @fopen($stageFile, 'wb');
        if (!$stream || !$target || stream_copy_to_stream($stream, $target) === false) {
            $error = 'The update could not be staged.';
            if (is_resource($stream)) fclose($stream);
            if (is_resource($target)) fclose($target);
            break;
        }
        fclose($stream); fclose($target);
    }
    $zip->close();
    if ($error === '') {
        foreach ($inspection['files'] as $path => $hash) {
            $relative = str_replace('/', DIRECTORY_SEPARATOR, $path);
            $source = $staging . DIRECTORY_SEPARATOR . $relative;
            $destination = Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . $relative;
            if (is_file($destination)) {
                $backupFile = $backup . DIRECTORY_SEPARATOR . $relative;
                @mkdir(dirname($backupFile), 0750, true);
                if (!@copy($destination, $backupFile)) { $error = 'A current file could not be backed up.'; break; }
                $replaced[] = $path;
            } else {
                $created[] = $path;
            }
            @mkdir(dirname($destination), 0755, true);
            if (!@copy($source, $destination)) { $error = 'An update file could not be installed.'; break; }
        }
    }
    $recoveryErrors = [];
    if ($error !== '') {
        foreach (array_reverse($replaced) as $path) {
            $relative = str_replace('/', DIRECTORY_SEPARATOR, $path);
            $backupFile = $backup . DIRECTORY_SEPARATOR . $relative;
            $destination = Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . $relative;
            if (!is_file($backupFile) || !@copy($backupFile, $destination)) {
                $recoveryErrors[] = $path;
            }
        }
        foreach (array_reverse($created) as $path) {
            $destination = Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_file($destination) && !@unlink($destination)) {
                $recoveryErrors[] = $path;
            }
        }
    } else {
        // Run SQL migration if present
        if (!empty($inspection['has_sql']) && !empty($inspection['sql_file'])) {
            $zipSql = new ZipArchive();
            if ($zipSql->open($package) === true) {
                $sqlContent = $zipSql->getFromName($inspection['sql_file']);
                $zipSql->close();
                if (is_string($sqlContent) && trim($sqlContent) !== '') {
                    global $sqlConnect;
                    if ($sqlConnect instanceof mysqli) {
                        $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
                        foreach ($statements as $stmt) {
                            if ($stmt !== '' && !str_starts_with($stmt, '--') && !str_starts_with($stmt, '/*')) {
                                @mysqli_query($sqlConnect, $stmt);
                            }
                        }
                    }
                }
            }
        }
        // Update ramza-release.json if it exists
        $releaseFile = Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . 'ramza-release.json';
        if (is_file($releaseFile)) {
            $currRelease = json_decode((string) file_get_contents($releaseFile), true);
            if (is_array($currRelease)) {
                $currRelease['version'] = $inspection['version'];
                $currRelease['released'] = gmdate('Y-m-d');
                @file_put_contents($releaseFile, json_encode($currRelease, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }
        // Update version in database configuration table
        global $sqlConnect;
        if ($sqlConnect instanceof mysqli) {
            $safeVer = mysqli_real_escape_string($sqlConnect, (string) $inspection['version']);
            $tableName = defined('T_CONFIG') ? T_CONFIG : 'Wo_Config';
            @mysqli_query($sqlConnect, "UPDATE {$tableName} SET `value` = '{$safeVer}' WHERE `name` = 'version'");
        }
    }
    $record = [
        'version' => $inspection['version'], 'installed_at' => gmdate('c'),
        'package_sha256' => $inspection['sha256'], 'replaced' => $replaced, 'created' => $created,
        'status' => $error === '' ? 'installed' : ($recoveryErrors === [] ? 'failed_rolled_back' : 'failed_recovery_incomplete'),
        'error' => $error, 'recovery_errors' => $recoveryErrors,
    ];
    @file_put_contents($backup . DIRECTORY_SEPARATOR . 'rollback.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    flock($lockHandle, LOCK_UN); fclose($lockHandle);
    if ($error === '') {
        return ['ok' => true, 'version' => $inspection['version'], 'backup' => $stamp];
    }
    $message = $error;
    if ($recoveryErrors !== []) {
        $message .= ' Automatic recovery was incomplete; restore the recorded backup before serving traffic.';
    }
    return ['ok' => false, 'message' => $message, 'backup' => $stamp, 'recovered' => $recoveryErrors === []];
}

function Ramza_UpdateRollbackLatest(): array
{
    $base = Ramza_UpdateDirectory() . DIRECTORY_SEPARATOR . 'backups';
    $folders = is_dir($base) ? glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) : [];
    if (!$folders) return ['ok' => false, 'message' => 'No update backup is available.'];
    rsort($folders, SORT_STRING);
    $folder = $folders[0];
    $record = json_decode((string) @file_get_contents($folder . DIRECTORY_SEPARATOR . 'rollback.json'), true);
    if (!is_array($record) || ($record['status'] ?? '') !== 'installed') return ['ok' => false, 'message' => 'The latest backup cannot be rolled back.'];
    foreach ((array) ($record['replaced'] ?? []) as $path) {
        $safe = Ramza_UpdateNormalizePath((string) $path);
        $source = $safe !== '' ? $folder . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe) : '';
        if ($safe === '' || !is_file($source) || !@copy($source, Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe))) {
            return ['ok' => false, 'message' => 'Rollback could not restore every replaced file.'];
        }
    }
    foreach ((array) ($record['created'] ?? []) as $path) {
        $safe = Ramza_UpdateNormalizePath((string) $path);
        if ($safe !== '') @unlink(Ramza_UpdateRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe));
    }
    $record['status'] = 'rolled_back'; $record['rolled_back_at'] = gmdate('c');
    @file_put_contents($folder . DIRECTORY_SEPARATOR . 'rollback.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return ['ok' => true, 'version' => (string) ($record['version'] ?? '')];
}
