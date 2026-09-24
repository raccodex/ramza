<?php
@ini_set('session.cookie_httponly',1);
@ini_set('session.use_only_cookies',1);
if (!version_compare(PHP_VERSION, '8.2.0', '>=')) {
    exit("RACSocial requires PHP 8.2.0 or newer. Current PHP version: " . PHP_VERSION . "\n");
}
if (!function_exists("mysqli_connect")) {
    exit("MySQLi is required to run the application, please contact your hosting to enable php mysqli.");
}

/*
 * Stop the application bootstrap before any database code runs when a fresh
 * package has not been installed yet.  Keeping this gate here also protects
 * direct entry points such as admincp.php and api.php, not only index.php.
 */
$ramzaApplicationRoot = dirname(__DIR__);
$ramzaConfigPath = $ramzaApplicationRoot . DIRECTORY_SEPARATOR . 'config.php';
$ramzaInstallLockPath = $ramzaApplicationRoot . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'install.lock';

$ramzaInstallerUrl = static function (string $applicationRoot): string {
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $resolvedRoot = realpath($applicationRoot);
    $basePath = '';

    if (is_string($scriptFile) && is_string($resolvedRoot)) {
        $normalizedFile = str_replace('\\', '/', $scriptFile);
        $normalizedRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/');
        $comparisonFile = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedFile) : $normalizedFile;
        $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot;

        if (str_starts_with($comparisonFile, $comparisonRoot . '/')) {
            $relativeEntry = substr($normalizedFile, strlen($normalizedRoot));
            if ($scriptName !== '' && $relativeEntry !== '' && str_ends_with(strtolower($scriptName), strtolower($relativeEntry))) {
                $basePath = substr($scriptName, 0, -strlen($relativeEntry));
            }
        }
    }

    if ($basePath === '') {
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $resolvedRoot = realpath($applicationRoot);
        if (is_string($documentRoot) && is_string($resolvedRoot)) {
            $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
            $normalizedRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/');
            $comparisonDocumentRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedDocumentRoot) : $normalizedDocumentRoot;
            $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot;
            if ($comparisonRoot === $comparisonDocumentRoot) {
                $basePath = '';
            } elseif (str_starts_with($comparisonRoot, $comparisonDocumentRoot . '/')) {
                $basePath = substr($normalizedRoot, strlen($normalizedDocumentRoot));
            }
        }
    }

    return rtrim($basePath, '/') . '/install/';
};

$ramzaHandleIncompleteInstall = static function () use ($ramzaApplicationRoot, $ramzaInstallLockPath, $ramzaInstallerUrl): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Ramza is not installed. Open /install/ in a web browser first.\n");
        exit(78);
    }

    if (!is_file($ramzaInstallLockPath)) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . $ramzaInstallerUrl($ramzaApplicationRoot), true, 302);
        exit;
    }

    http_response_code(503);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Ramza configuration is missing or incomplete. Restore config.php before using this installation.\n";
    exit;
};

if (!is_readable($ramzaConfigPath)) {
    $ramzaHandleIncompleteInstall();
}

require_once $ramzaConfigPath;

if (!function_exists('Ramza_IsDemoMode')) {
    function Ramza_IsDemoMode(): bool
    {
        global $ramza_demo_mode;

        if (!isset($ramza_demo_mode)) {
            return false;
        }
        if (is_bool($ramza_demo_mode)) {
            return $ramza_demo_mode;
        }

        return in_array(strtolower(trim((string) $ramza_demo_mode)), array(
            '1',
            'true',
            'yes',
            'on',
            'enabled'
        ), true);
    }
}

$ramzaConfigComplete = isset($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name, $site_url)
    && trim((string) $sql_db_host) !== ''
    && trim((string) $sql_db_user) !== ''
    && trim((string) $sql_db_name) !== ''
    && trim((string) $site_url) !== '';

if (!$ramzaConfigComplete) {
    $ramzaHandleIncompleteInstall();
}

/*
 * Subfolder / SAPI hardening.
 *
 * The legacy codebase resolves the entire media pipeline through relative
 * filesystem paths (for example "upload/videos/...", move_uploaded_file(),
 * @mkdir('upload/...')). Those paths only work when the current working
 * directory equals the application root. Classic Apache + mod_php satisfies
 * that assumption, but LiteSpeed / OpenLiteSpeed (and PHP-FPM behind some
 * Nginx layouts) can begin a request with a different CWD - especially when
 * Ramza is installed inside a subfolder such as /kali or /racsocial. When
 * that happens, photo, video and reel uploads break because files are written
 * to (or looked for in) the wrong directory.
 *
 * Anchoring the working directory to the resolved application root once, here
 * in the shared bootstrap, repairs every relative upload path in one place
 * without rewriting hundreds of call sites. RAMZA_ROOT is exported so new code
 * can build guaranteed-absolute paths when required.
 */
if (!defined('RAMZA_ROOT')) {
    $ramzaResolvedRoot = realpath($ramzaApplicationRoot);
    if ($ramzaResolvedRoot === false) {
        $ramzaResolvedRoot = $ramzaApplicationRoot;
    }
    define('RAMZA_ROOT', rtrim(str_replace('\\', '/', $ramzaResolvedRoot), '/'));
}
if (is_dir(RAMZA_ROOT)) {
    @chdir(RAMZA_ROOT);
}

date_default_timezone_set('UTC');
session_start();
@ini_set('gd.jpeg_ignore_warning', 1);
require_once('assets/libraries/DB/vendor/joshcam/mysqli-database-class/MySQL-Maria.php');
require_once('includes/cache.php');
require_once('includes/functions_general.php');
$_SERVER['REMOTE_ADDR'] = get_ip_address();
require_once('includes/tabels.php');
require_once('includes/functions_one.php');
require_once('includes/ramza_algorithm.php');
require_once('includes/functions_two.php');
require_once('includes/functions_three.php');
