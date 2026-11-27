<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Client;

interface McpTransport
{
    /**
     * Execute a stateless MCP JSON-RPC call against a remote or scripted MCP server.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $serverId, string $method, array $params = []): array;
}
