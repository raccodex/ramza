<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $field = null
    ) {
        parent::__construct($message);
    }
}
