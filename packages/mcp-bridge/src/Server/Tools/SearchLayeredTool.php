<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server\Tools;

use KnowKit\Bridge\Mcp\Server\AuthenticatedMcpContext;
use KnowKit\Bridge\Mcp\Server\McpBackend;
use KnowKit\Bridge\Mcp\Server\McpTool;
use KnowKit\Port\AccessPolicy;

final readonly class SearchLayeredTool implements McpTool
{
    private const ULID_PATTERN = '^[0-7][0-9A-HJKMNP-TV-Z]{25}$';

    public function __construct(
        private McpBackend $backend,
        private ?AccessPolicy $accessPolicy = null,
    ) {
    }

    public function name(): string
    {
        return 'knowkit.search_layered';
    }

    public function description(): string
    {
        return 'Search authorized knowledge layers and return an opaque SelectionToken with metadata only.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 4096,
                ],
                'stackId' => [
                    'type' => 'string',
                    'pattern' => self::ULID_PATTERN,
                ],
                'topK' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 64,
                ],
            ],
        ];
    }

    public function execute(AuthenticatedMcpContext $context, array $arguments): array
    {
        $query = (string) $arguments['query'];
        $stackId = isset($arguments['stackId']) ? (string) $arguments['stackId'] : null;
        $topK = isset($arguments['topK']) ? (int) $arguments['topK'] : 10;

        if ($this->accessPolicy !== null) {
            $this->accessPolicy->authorizeQuery($context->scope);
        }

        $result = $this->backend->searchLayered($context, $query, $stackId, $topK);

        // Enforce ARCH-MCP-BOUNDARY: search_layered returns ONLY selectionToken and safe metadata (0 bytes of chunk text).
        $sanitizedMeta = [];
        foreach ($result['candidatesMeta'] as $meta) {
            unset($meta['text'], $meta['body'], $meta['content'], $meta['verbatim'], $meta['snippet'], $meta['chunkText']);
            $sanitizedMeta[] = $meta;
        }

        return [
            'selectionToken' => $result['selectionToken'],
            'candidatesMeta' => $sanitizedMeta,
        ];
    }
}
