<?php
declare(strict_types=1);

final class RACInstallerLogger
{
    private string $logFile;

    public function __construct(private readonly RACInstallerPaths $paths)
    {
        if (!is_dir($this->paths->logDir) && !mkdir($this->paths->logDir, 0750, true) && !is_dir($this->paths->logDir)) {
            throw new RACInstallerSystemException('Unable to initialize protected installer logging.');
        }
        $this->logFile = $this->paths->logDir . DIRECTORY_SEPARATOR . 'installer.log';
        @chmod($this->paths->logDir, 0750);
    }

    public function info(string $phase, array $context = []): void
    {
        $this->write('info', $phase, $context);
    }

    public function error(string $phase, Throwable $error, array $context = []): string
    {
        $reference = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $context['reference'] = $reference;
        $context['exception'] = get_class($error);
        $context['message'] = $this->redact($error->getMessage());
        $this->write('error', $phase, $context);
        return $reference;
    }

    public function path(): string
    {
        return $this->logFile;
    }

    private function write(string $level, string $phase, array $context): void
    {
        $entry = [
            'time' => date('c'),
            'level' => $level,
            'phase' => $phase,
            'context' => $this->redactArray($context),
        ];
        file_put_contents($this->logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod($this->logFile, 0640);
    }

    private function redactArray(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $keyString = strtolower((string) $key);
            if (preg_match('/password|pass|purchase|code|token|secret|csrf|session|encrypt|key/', $keyString)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value);
            } elseif (is_scalar($value) || $value === null) {
                $redacted[$key] = $this->redact((string) $value);
            } else {
                $redacted[$key] = '[object]';
            }
        }
        return $redacted;
    }

    private function redact(string $value): string
    {
        $value = preg_replace('/(password|purchase[_ -]?code|secret|token|csrf|session|key)\s*[:=]\s*[^&\s"\']+/i', '$1=[redacted]', $value) ?? $value;
        $value = preg_replace('/([A-Za-z]:)?[\\\\\/][^\\s"\']+/', '[path]', $value) ?? $value;
        return $value;
    }
}
