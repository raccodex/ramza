<?php
declare(strict_types=1);

final class RACInstallerHtaccessWriter
{
    public function __construct(private readonly RACInstallerPaths $paths)
    {
    }

    public function installIfMissing(): bool
    {
        if (is_file($this->paths->htaccessFile)) {
            return false;
        }
        if (!is_readable($this->paths->htaccessSource)) {
            throw new RACInstallerSystemException('The rewrite-rule source file is unavailable.');
        }
        $content = file_get_contents($this->paths->htaccessSource);
        if (!is_string($content)) {
            throw new RACInstallerSystemException('The rewrite-rule source file could not be read.');
        }
        $temporary = tempnam($this->paths->rootDir, 'rac-htaccess-');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new RACInstallerSystemException('Unable to prepare rewrite rules.');
        }
        if (!rename($temporary, $this->paths->htaccessFile)) {
            @unlink($temporary);
            throw new RACInstallerSystemException('Unable to publish rewrite rules.');
        }
        @chmod($this->paths->htaccessFile, 0644);
        return true;
    }
}
