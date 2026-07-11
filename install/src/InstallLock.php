<?php
declare(strict_types=1);

final class RACInstallerInstallLock
{
    public function __construct(private readonly RACInstallerPaths $paths)
    {
    }

    public function exists(): bool
    {
        return is_file($this->paths->lockFile);
    }

    public function create(string $siteUrl, string $schemaFingerprint): void
    {
        if ($this->exists()) {
            throw new RACInstallerUserException('RACSocial is already installed.');
        }
        $payload = json_encode([
            'installed_at' => date('c'),
            'installer_version' => RACSOCIAL_INSTALLER_VERSION,
            'php_version' => PHP_VERSION,
            'schema_fingerprint' => $schemaFingerprint,
            'site_host_hash' => hash('sha256', (string) parse_url($siteUrl, PHP_URL_HOST)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = tempnam($this->paths->installDir, 'rac-lock-');
        if ($temporary === false || file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new RACInstallerSystemException('Unable to prepare the installation lock.');
        }
        if (!rename($temporary, $this->paths->lockFile)) {
            @unlink($temporary);
            throw new RACInstallerSystemException('Unable to create the installation lock.');
        }
        @chmod($this->paths->lockFile, 0640);
    }
}
