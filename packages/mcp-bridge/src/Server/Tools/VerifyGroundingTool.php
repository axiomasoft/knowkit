<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server\Tools;

use KnowKit\Bridge\Mcp\Server\AuthenticatedMcpContext;
use KnowKit\Bridge\Mcp\Server\McpBackend;
use KnowKit\Bridge\Mcp\Server\McpTool;

final readonly class VerifyGroundingTool implements McpTool
{
    public function __construct(
        private McpBackend $backend,
    ) {
    }

    public function name(): string
    {
        return 'knowkit.verify_grounding';
    }

    public function description(): string
    {
        return 'Verify response grounding aliases [#i] against the CitationAliasMap for a SelectionToken.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['selectionToken', 'responseText', 'citations'],
            'properties' => [
                'selectionToken' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 256,
                ],
                'responseText' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 65536,
                ],
                'citations' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => 64,
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 64,
                    ],
                ],
            ],
        ];
    }

    public function execute(AuthenticatedMcpContext $context, array $arguments): array
    {
        $selectionToken = (string) $arguments['selectionToken'];
        $responseText = (string) $arguments['responseText'];
        /** @var list<string> $citations */
        $citations = array_values(array_map('strval', (array) $arguments['citations']));

        return $this->backend->verifyGrounding($context, $selectionToken, $responseText, $citations);
    }
}
