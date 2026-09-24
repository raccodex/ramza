<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class RequestContext
{
    private static string $requestId = '';

    public static function initialize(): void
    {
        $candidate = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,128}$/', $candidate) === 1
            ? $candidate
            : bin2hex(random_bytes(16));
        header('X-Request-ID: ' . self::$requestId);
    }

    public static function id(): string
    {
        return self::$requestId;
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }
}
