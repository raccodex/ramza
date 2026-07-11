<?php
declare(strict_types=1);

final class RACInstallerPaths
{
    public string $installDir;
    public string $rootDir;
    public string $configFile;
    public string $nodeConfigFile;
    public string $htaccessFile;
    public string $htaccessSource;
    public string $sqlDump;
    public string $lockFile;
    public string $logDir;

    public function __construct()
    {
        $this->installDir = dirname(__DIR__);
        $this->rootDir = dirname($this->installDir);
        $this->configFile = $this->rootDir . DIRECTORY_SEPARATOR . 'config.php';
        $this->nodeConfigFile = $this->rootDir . DIRECTORY_SEPARATOR . 'nodejs' . DIRECTORY_SEPARATOR . 'config.json';
        $this->htaccessFile = $this->rootDir . DIRECTORY_SEPARATOR . '.htaccess';
        $this->htaccessSource = $this->rootDir . DIRECTORY_SEPARATOR . 'htaccess.txt';
        $this->sqlDump = $this->rootDir . DIRECTORY_SEPARATOR . 'wowonder.sql';
        $this->lockFile = $this->installDir . DIRECTORY_SEPARATOR . 'install.lock';

        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->logDir = $base . 'racsocial-installer-' . substr(hash('sha256', $this->rootDir), 0, 16);
    }
}
