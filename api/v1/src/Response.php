<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class Response
{
    public static function success(
        mixed $data,
        string $message = 'Request completed successfully.',
        array $meta = [],
        int $status = 200
    ): never {
        self::send($status, [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge(['request_id' => RequestContext::id()], $meta),
            'errors' => [],
        ]);
    }

    public static function error(ApiException $error): never
    {
        $item = [
            'code' => $error->errorCode,
            'message' => $error->getMessage(),
        ];
        if ($error->field !== null) {
            $item['field'] = $error->field;
        }
        self::send($error->httpStatus, [
            'success' => false,
            'message' => $error->getMessage(),
            'data' => null,
            'meta' => ['request_id' => RequestContext::id()],
            'errors' => [$item],
        ]);
    }

    public static function unexpected(): never
    {
        self::send(500, [
            'success' => false,
            'message' => 'The request could not be completed.',
            'data' => null,
            'meta' => ['request_id' => RequestContext::id()],
            'errors' => [[
                'code' => 'INTERNAL_ERROR',
                'message' => 'The request could not be completed.',
            ]],
        ]);
    }

    private static function send(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
