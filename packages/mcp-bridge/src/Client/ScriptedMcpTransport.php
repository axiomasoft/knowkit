<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Client;

final class ScriptedMcpTransport implements McpTransport
{
    /** @var array<string, callable(array<string, mixed>): array<string, mixed>> */
    private array $handlers = [];

    /** @var array<string, list<array<string, mixed>|\Throwable>> */
    private array $queues = [];

    /** @var list<array{serverId: string, method: string, params: array<string, mixed>}> */
    private array $recordedCalls = [];

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $handler
     */
    public function on(string $serverId, string $method, callable $handler): self
    {
        $this->handlers[$this->key($serverId, $method)] = $handler;

        return $this;
    }

    /**
     * @param array<string, mixed>|\Throwable $responseOrException
     */
    public function enqueue(string $serverId, string $method, array|\Throwable $responseOrException): self
    {
        $this->queues[$this->key($serverId, $method)][] = $responseOrException;

        return $this;
    }

    public function call(string $serverId, string $method, array $params = []): array
    {
        $this->recordedCalls[] = [
            'serverId' => $serverId,
            'method' => $method,
            'params' => $params,
        ];

        $key = $this->key($serverId, $method);
        if (isset($this->queues[$key]) && $this->queues[$key] !== []) {
            $next = array_shift($this->queues[$key]);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        }

        if (isset($this->handlers[$key])) {
            return ($this->handlers[$key])($params);
        }

        throw new \RuntimeException(sprintf('No scripted MCP handler for %s::%s', $serverId, $method));
    }

    /**
     * @return list<array{serverId: string, method: string, params: array<string, mixed>}>
     */
    public function recordedCalls(): array
    {
        return $this->recordedCalls;
    }

    private function key(string $serverId, string $method): string
    {
        return $serverId . '::' . $method;
    }
}
