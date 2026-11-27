<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

final class McpValidationException extends \InvalidArgumentException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $knowkitCode,
        public readonly array $data = [],
        int $code = -32602,
    ) {
        parent::__construct($knowkitCode, $code);
    }
}
