<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server\Tools;

use KnowKit\Bridge\Mcp\Server\AuthenticatedMcpContext;
use KnowKit\Bridge\Mcp\Server\McpBackend;
use KnowKit\Bridge\Mcp\Server\McpTool;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ResourceRef;
use KnowKit\Port\AccessPolicy;

final readonly class VerifyGroundingTool implements McpTool
{
    public function __construct(
        private McpBackend $backend,
        private ?AccessPolicy $accessPolicy = null,
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

        if ($this->accessPolicy === null) {
            throw new DomainFailure(Outcome::Denied, 'policy_denied');
        }
        $resource = new ResourceRef($context->scope->tenantId, 'knowledge_base', $context->scope->kbId);
        $receipt = $this->accessPolicy->decide($context->scope, $resource, Operation::Retrieve);
        $receipt->requireAuthorized($context->scope, Operation::Retrieve, $resource);

        return $this->backend->verifyGrounding($context, $selectionToken, $responseText, $citations);
    }
}
