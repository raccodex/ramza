<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class Request
{
    private ?array $json = null;

    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH) ?? '');
        $marker = '/api/v1';
        $position = strpos($path, $marker);
        if ($position === false) {
            return '/';
        }
        $relative = substr($path, $position + strlen($marker));
        $relative = '/' . ltrim($relative, '/');
        return $relative === '/index.php' ? '/' : (rtrim($relative, '/') ?: '/');
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 1_048_576) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'The JSON request is too large.');
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $this->json = [];
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'The request body is not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw new ApiException(400, 'INVALID_JSON', 'The JSON body must be an object.');
        }
        return $this->json = $decoded;
    }

    public function bearerToken(): string
    {
        $header = trim((string) (
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        ));
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $header = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
            }
        }
        if (preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,200})$/i', $header, $matches) !== 1) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid bearer token is required.');
        }
        return $matches[1];
    }

    public function publicClientId(): string
    {
        $value = trim((string) ($_SERVER['HTTP_X_RAMZA_CLIENT'] ?? ''));
        // New mobile builds use the client configured by the server. Keep
        // accepting the legacy header for older installations, but do not
        // require it from the public application anymore.
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9._-]{12,80}$/', $value) !== 1) {
            throw new ApiException(401, 'CLIENT_REQUIRED', 'A valid public mobile client identifier is required.');
        }
        return $value;
    }

    public function requiredString(array $body, string $field, int $maximum = 255): string
    {
        $value = trim((string) ($body[$field] ?? ''));
        if ($value === '' || mb_strlen($value) > $maximum) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'This field is required.', $field);
        }
        return $value;
    }

    public function queryString(string $field, int $maximum = 500, string $default = ''): string
    {
        $value = trim((string) ($_GET[$field] ?? $default));
        if (mb_strlen($value) > $maximum) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The query value is too long.', $field);
        }
        return $value;
    }

    public function queryInt(string $field, int $default, int $minimum, int $maximum): int
    {
        $raw = $_GET[$field] ?? $default;
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The query value must be an integer.', $field);
        }
        $value = (int) $raw;
        if ($value < $minimum || $value > $maximum) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The query value is outside the allowed range.', $field);
        }
        return $value;
    }
}
