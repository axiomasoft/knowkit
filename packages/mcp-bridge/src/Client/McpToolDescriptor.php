<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Client;

use KnowKit\Domain\Layers\DeliveryMode;

final readonly class McpToolDescriptor
{
    /**
     * @param array<string, mixed>|null $inputSchema Null in compact R0 index; hydrated on demand.
     */
    public function __construct(
        public string $serverId,
        public string $toolName,
        public string $summary,
        public string $schemaDigest,
        public bool $requiresApproval,
        public int $ttlMs,
        public ?array $inputSchema = null,
        public DeliveryMode $deliveryMode = DeliveryMode::ToolDiscoverable,
    ) {
        if ($serverId === '' || $toolName === '' || $summary === '' || $schemaDigest === '') {
            throw new \InvalidArgumentException('McpToolDescriptor fields must be non-empty');
        }
        if ($ttlMs < 0) {
            throw new \InvalidArgumentException('ttlMs must be non-negative');
        }
        if ($deliveryMode !== DeliveryMode::ToolDiscoverable) {
            throw new \InvalidArgumentException('McpToolDescriptor requires DeliveryMode::ToolDiscoverable');
        }
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    public function withInputSchema(array $inputSchema): self
    {
        return new self(
            $this->serverId,
            $this->toolName,
            $this->summary,
            $this->schemaDigest,
            $this->requiresApproval,
            $this->ttlMs,
            $inputSchema,
            $this->deliveryMode,
        );
    }

    /**
     * @return array{serverId: string, toolName: string, summary: string, schemaDigest: string, requiresApproval: bool, ttlMs: int}
     */
    public function toCompactR0(): array
    {
        return [
            'serverId' => $this->serverId,
            'toolName' => $this->toolName,
            'summary' => $this->summary,
            'schemaDigest' => $this->schemaDigest,
            'requiresApproval' => $this->requiresApproval,
            'ttlMs' => $this->ttlMs,
        ];
    }
}
