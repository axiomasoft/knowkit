<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Client;

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Layers\DeliveryMode;
use KnowKit\Domain\Layers\TrustTier;

final class McpToolCatalogLayer
{
    /** @var array<string, array{expiresAtMs: int, descriptors: list<McpToolDescriptor>}> */
    private array $catalogByServer = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $schemaCache = [];

    public function __construct(
        private readonly McpTransport $transport,
        private readonly int $defaultTtlMs = 300000,
    ) {
    }

    public function deliveryMode(): DeliveryMode
    {
        return DeliveryMode::ToolDiscoverable;
    }

    public function syncCatalog(string $serverId, int $nowMs = 0): void
    {
        if (isset($this->catalogByServer[$serverId]) && $nowMs < $this->catalogByServer[$serverId]['expiresAtMs']) {
            return;
        }

        $response = $this->transport->call($serverId, 'tools/index', []);
        $cache = is_array($response['cache'] ?? null) ? $response['cache'] : [];
        $ttlMs = isset($cache['ttlMs']) && is_int($cache['ttlMs']) && $cache['ttlMs'] >= 0
            ? $cache['ttlMs']
            : $this->defaultTtlMs;

        $rawTools = is_array($response['tools'] ?? null) ? $response['tools'] : [];
        $descriptors = [];
        foreach ($rawTools as $rawTool) {
            if (!is_array($rawTool)) {
                continue;
            }
            $toolName = (string) ($rawTool['name'] ?? '');
            $summary = (string) ($rawTool['summary'] ?? ($rawTool['description'] ?? $toolName));
            $schemaDigest = (string) ($rawTool['schemaDigest'] ?? '');
            $requiresApproval = (bool) ($rawTool['requiresApproval'] ?? false);
            $itemTtlMs = isset($rawTool['ttlMs']) && is_int($rawTool['ttlMs']) ? $rawTool['ttlMs'] : $ttlMs;

            if ($toolName === '') {
                continue;
            }
            if ($schemaDigest === '' && isset($rawTool['inputSchema']) && is_array($rawTool['inputSchema'])) {
                $schemaDigest = hash('sha256', Jcs::canonicalize($rawTool['inputSchema']));
            }

            $descriptors[] = new McpToolDescriptor(
                serverId: $serverId,
                toolName: $toolName,
                summary: $summary,
                schemaDigest: $schemaDigest,
                requiresApproval: $requiresApproval,
                ttlMs: $itemTtlMs,
                inputSchema: null,
                deliveryMode: DeliveryMode::ToolDiscoverable,
            );
        }

        $this->catalogByServer[$serverId] = [
            'expiresAtMs' => $nowMs + $ttlMs,
            'descriptors' => $descriptors,
        ];
    }

    /**
     * Returns the compact R0 catalog without full JSON schemas.
     *
     * @return list<McpToolDescriptor>
     */
    public function r0Catalog(): array
    {
        $all = [];
        foreach ($this->catalogByServer as $entry) {
            foreach ($entry['descriptors'] as $descriptor) {
                $all[] = $descriptor;
            }
        }

        return $all;
    }

    /**
     * Searches the compact R0 catalog and hydrates full JCS-canonicalized schemas
     * ONLY for the top <= $limit matching tools.
     *
     * @return list<McpToolDescriptor>
     */
    public function discoverRelevant(string $query, int $limit = 4, int $nowMs = 0): array
    {
        if ($limit < 1) {
            return [];
        }

        foreach (array_keys($this->catalogByServer) as $serverId) {
            $this->syncCatalog($serverId, $nowMs);
        }

        $queryTokens = $this->tokenize($query);
        $scored = [];
        foreach ($this->r0Catalog() as $index => $descriptor) {
            $haystackTokens = $this->tokenize($descriptor->toolName . ' ' . $descriptor->summary);
            $score = count(array_intersect($queryTokens, $haystackTokens));
            if ($score > 0 || $queryTokens === []) {
                $scored[] = [
                    'score' => $score,
                    'index' => $index,
                    'descriptor' => $descriptor,
                ];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['index'] <=> $b['index'];
        });

        $selected = array_slice($scored, 0, $limit);
        $byServer = [];
        foreach ($selected as $row) {
            $desc = $row['descriptor'];
            if (!isset($this->schemaCache[$desc->serverId][$desc->toolName])) {
                $byServer[$desc->serverId][] = $desc->toolName;
            }
        }

        foreach ($byServer as $serverId => $toolNames) {
            $response = $this->transport->call($serverId, 'tools/schemas', ['names' => $toolNames]);
            $schemas = is_array($response['schemas'] ?? null) ? $response['schemas'] : [];
            foreach ($schemas as $toolName => $rawSchema) {
                if (is_string($toolName) && is_array($rawSchema)) {
                    $canonicalJson = Jcs::canonicalize($rawSchema);
                    /** @var array<string, mixed> $canonicalSchema */
                    $canonicalSchema = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
                    $this->schemaCache[$serverId][$toolName] = $canonicalSchema;
                }
            }
        }

        $hydrated = [];
        foreach ($selected as $row) {
            $desc = $row['descriptor'];
            $schema = $this->schemaCache[$desc->serverId][$desc->toolName] ?? null;
            $hydrated[] = $schema !== null ? $desc->withInputSchema($schema) : $desc;
        }

        return $hydrated;
    }

    /**
     * Invoke an external MCP tool and wrap output strictly as TrustTier::UntrustedEvidence.
     *
     * @param array<string, mixed> $arguments
     */
    public function invokeTool(string $serverId, string $toolName, array $arguments): UntrustedToolResult
    {
        $response = $this->transport->call($serverId, 'tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);

        $content = $response['structuredContent'] ?? ($response['content'] ?? $response);
        $contentDigest = hash('sha256', Jcs::canonicalize($content));

        return new UntrustedToolResult(
            content: $content,
            contentDigest: $contentDigest,
            serverId: $serverId,
            toolName: $toolName,
            trustTier: TrustTier::UntrustedEvidence,
        );
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        return array_values(array_unique($parts));
    }
}
