<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use RuntimeException;

final class InputFailure extends RuntimeException
{
    public function __construct(
        public readonly int $status = 400,
        public readonly string $errorCode = 'invalid_request',
    ) {
        parent::__construct($errorCode);
    }
}
