<?php
declare(strict_types=1);

final class RACInstallerNodeConfigWriter
{
    private ?string $temporaryFile = null;
    private ?string $backupFile = null;
    private ?string $originalContent = null;
    private bool $originalExisted = false;
    private bool $published = false;

    public function __construct(private readonly RACInstallerPaths $paths)
    {
    }

    public static function canWriteFreshNodeConfig(string $path): bool
    {
        if (!file_exists($path)) {
            return is_dir(dirname($path)) && is_writable(dirname($path));
        }
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            return false;
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            return false;
        }
        if (trim($content) === '') {
            return true;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return false;
        }
        foreach (['sql_db_host', 'sql_db_user', 'sql_db_pass', 'sql_db_name', 'site_url'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                return false;
            }
        }
        return in_array((string) $decoded['sql_db_host'], ['', 'sql_db_host'], true)
            && in_array((string) $decoded['sql_db_user'], ['', 'sql_db_user', 'database_user'], true)
            && in_array((string) $decoded['sql_db_name'], ['', 'sql_db_name', 'database_name'], true)
            && in_array((string) $decoded['site_url'], ['', 'site_url'], true);
    }

    public function prepare(array $database, array $site, string $purchaseCode): void
    {
        if (!self::canWriteFreshNodeConfig($this->paths->nodeConfigFile)) {
            throw new RACInstallerUserException('A populated nodejs/config.json already exists. The installer will not overwrite it.');
        }
        $this->originalExisted = is_file($this->paths->nodeConfigFile);
        $this->originalContent = $this->originalExisted ? file_get_contents($this->paths->nodeConfigFile) : null;
        if ($this->originalExisted && !is_string($this->originalContent)) {
            throw new RACInstallerSystemException('Unable to preserve the Node.js configuration template for failure recovery.');
        }
        if ($this->originalExisted && is_string($this->originalContent)) {
            $this->backupFile = $this->writeTemporary($this->paths->logDir, 'rac-node-backup-', $this->originalContent);
        }
        $payload = [
            'sql_db_host' => $database['host'],
            'sql_db_user' => $database['user'],
            'sql_db_pass' => $database['pass'],
            'sql_db_name' => $database['name'],
            'site_url' => $site['url'],
            'purchase_code' => $purchaseCode,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->temporaryFile = $this->writeTemporary(dirname($this->paths->nodeConfigFile), 'rac-node-', $json);
    }

    public function commit(): void
    {
        if ($this->temporaryFile === null || !is_file($this->temporaryFile)) {
            throw new RACInstallerSystemException('The prepared Node.js configuration is unavailable.');
        }
        if (file_exists($this->paths->nodeConfigFile) && !self::canWriteFreshNodeConfig($this->paths->nodeConfigFile)) {
            throw new RACInstallerUserException('nodejs/config.json changed during installation and was not overwritten.');
        }
        if (file_exists($this->paths->nodeConfigFile) && !unlink($this->paths->nodeConfigFile)) {
            throw new RACInstallerSystemException('Unable to replace the Node.js configuration template.');
        }
        if (!rename($this->temporaryFile, $this->paths->nodeConfigFile)) {
            throw new RACInstallerSystemException('Unable to publish nodejs/config.json atomically.');
        }
        $this->temporaryFile = null;
        $this->published = true;
        @chmod($this->paths->nodeConfigFile, 0640);
    }

    public function cleanup(): void
    {
        if ($this->temporaryFile !== null && is_file($this->temporaryFile)) {
            @unlink($this->temporaryFile);
        }
        $this->temporaryFile = null;
    }

    public function rollbackPublished(): void
    {
        if (!$this->published) {
            return;
        }
        if ($this->originalExisted && $this->backupFile !== null && is_file($this->backupFile)) {
            $backup = file_get_contents($this->backupFile);
            if (is_string($backup)) {
                file_put_contents($this->paths->nodeConfigFile, $backup, LOCK_EX);
            }
        } else {
            @unlink($this->paths->nodeConfigFile);
        }
        $this->published = false;
    }

    public function finalize(): void
    {
        if ($this->backupFile !== null && is_file($this->backupFile)) {
            @unlink($this->backupFile);
        }
        $this->backupFile = null;
        $this->originalContent = null;
    }

    private function writeTemporary(string $directory, string $prefix, string $content): string
    {
        $path = tempnam($directory, $prefix);
        $handle = $path === false ? false : fopen($path, 'wb');
        $written = $handle === false ? false : fwrite($handle, $content);
        if ($handle !== false) {
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
            fclose($handle);
        }
        if ($path === false || $written !== strlen($content)) {
            if (is_string($path)) {
                @unlink($path);
            }
            throw new RACInstallerSystemException('Unable to prepare the Node.js configuration atomically.');
        }
        @chmod($path, 0600);
        return $path;
    }
}
