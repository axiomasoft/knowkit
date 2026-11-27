<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

interface McpTool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(AuthenticatedMcpContext $context, array $arguments): array;
}
