<?php
declare(strict_types=1);

final class RACInstallerRequirements
{
    public function __construct(private readonly RACInstallerPaths $paths)
    {
    }

    public function evaluate(): array
    {
        $required = [];
        $warnings = [];
        $info = [];

        $required[] = $this->check('PHP 8.2+', self::supportsPhp(), 'RACSocial requires PHP 8.2.0 or newer.');
        foreach (['mysqli' => 'MySQLi', 'mbstring' => 'mbstring', 'curl' => 'cURL', 'gd' => 'GD', 'openssl' => 'OpenSSL', 'sodium' => 'Sodium', 'fileinfo' => 'fileinfo/MIME', 'zip' => 'ZIP', 'json' => 'JSON', 'session' => 'Session'] as $extension => $label) {
            $required[] = $this->check($label, extension_loaded($extension), "Enable the {$label} PHP extension.");
        }
        $required[] = $this->check('random_bytes()', function_exists('random_bytes'), 'PHP random_bytes() must be available for secure keys.');
        $required[] = $this->check('SQL dump', is_readable($this->paths->sqlDump), 'The repository root must contain a readable ramza.sql file.');
        $required[] = $this->check('Mobile API migration', is_readable($this->paths->mobileApiMigration), 'The package must contain updates/ramza_mobile_api_v1.sql.');
        $required[] = $this->check('Temporary directory', $this->temporaryDirectoryUsable(), 'PHP temporary directory must be writable for installer logs and atomic files.');
        $required[] = $this->check('config.php destination', RACInstallerConfigWriter::canWriteFreshConfig($this->paths->configFile), 'config.php must be missing, blank/template, or replaceable by the installer.');
        $required[] = $this->check('nodejs/config.json destination', RACInstallerNodeConfigWriter::canWriteFreshNodeConfig($this->paths->nodeConfigFile), 'nodejs/config.json must be missing, blank/template, or replaceable by the installer.');
        $required[] = $this->check('upload directory', $this->writablePath($this->paths->rootDir . DIRECTORY_SEPARATOR . 'upload'), 'The upload directory must be writable by PHP.');
        $required[] = $this->check('cache directory', $this->writablePath($this->paths->rootDir . DIRECTORY_SEPARATOR . 'cache'), 'The cache directory must be writable by PHP.');

        if (!function_exists('exif_read_data')) {
            $warnings[] = $this->warning('EXIF', 'The EXIF extension is recommended for media metadata handling.');
        }
        if (!$this->isHttps()) {
            $warnings[] = $this->warning('HTTPS', 'HTTPS was not detected. Use HTTPS before launching a production site.');
        }
        if (!$this->rewriteLooksAvailable()) {
            $warnings[] = $this->warning('Rewrite rules', 'Apache rewrite support could not be confirmed. Nginx/IIS may need manual rewrite configuration.');
        }
        if ($this->bytesFromIni('memory_limit') > 0 && $this->bytesFromIni('memory_limit') < 128 * 1024 * 1024) {
            $warnings[] = $this->warning('Memory limit', '128 MB or more is recommended; higher is safer for media-heavy installs.');
        }
        if ($this->bytesFromIni('upload_max_filesize') < 32 * 1024 * 1024 || $this->bytesFromIni('post_max_size') < 32 * 1024 * 1024) {
            $warnings[] = $this->warning('Upload/post size', '32 MB or more is recommended for media uploads.');
        }
        $maxExecution = (int) ini_get('max_execution_time');
        if ($maxExecution > 0 && $maxExecution < 120) {
            $warnings[] = $this->warning('Execution time', '120 seconds or more is recommended for SQL import on shared hosting.');
        }

        $info[] = ['name' => 'PHP runtime', 'status' => 'info', 'message' => PHP_VERSION];
        $info[] = ['name' => 'Server software', 'status' => 'info', 'message' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown')];
        $info[] = ['name' => 'SQL dump', 'status' => 'info', 'message' => basename($this->paths->sqlDump) . ' present'];
        $info[] = ['name' => 'Installer logs', 'status' => 'info', 'message' => 'Protected temporary logging is configured.'];

        $requiredPass = true;
        foreach ($required as $row) {
            if ($row['status'] !== 'pass') {
                $requiredPass = false;
                break;
            }
        }

        return [
            'required' => $required,
            'warnings' => $warnings,
            'info' => $info,
            'required_pass' => $requiredPass,
        ];
    }

    public static function supportsPhp(?string $version = null): bool
    {
        return version_compare($version ?? PHP_VERSION, '8.2.0', '>=');
    }

    private function check(string $name, bool $pass, string $message): array
    {
        return ['name' => $name, 'status' => $pass ? 'pass' : 'fail', 'message' => $pass ? 'Ready' : $message];
    }

    private function warning(string $name, string $message): array
    {
        return ['name' => $name, 'status' => 'warning', 'message' => $message];
    }

    private function temporaryDirectoryUsable(): bool
    {
        $dir = sys_get_temp_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $probe = tempnam($dir, 'rac_installer_');
        if ($probe === false) {
            return false;
        }
        $ok = file_put_contents($probe, 'ok') !== false;
        @unlink($probe);
        return $ok;
    }

    private function writablePath(string $path): bool
    {
        if (file_exists($path)) {
            return is_writable($path);
        }
        $parent = dirname($path);
        return is_dir($parent) && is_writable($parent);
    }

    private function rewriteLooksAvailable(): bool
    {
        if (function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules(), true)) {
            return true;
        }
        $server = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (str_contains($server, 'nginx') || str_contains($server, 'iis')) {
            return true;
        }
        return is_file($this->paths->htaccessFile) || is_file($this->paths->htaccessSource);
    }

    private function isHttps(): bool
    {
        return rac_installer_is_https();
    }

    private function bytesFromIni(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }
}
