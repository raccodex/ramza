<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class Security
{
    public function __construct(private readonly string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new ApiException(503, 'MOBILE_SECURITY_NOT_CONFIGURED', 'Mobile API security is not configured.');
        }
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->pepper);
    }

    public function opaqueToken(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
