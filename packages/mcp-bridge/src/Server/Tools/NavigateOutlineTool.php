<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server\Tools;

use KnowKit\Bridge\Mcp\Server\AuthenticatedMcpContext;
use KnowKit\Bridge\Mcp\Server\McpBackend;
use KnowKit\Bridge\Mcp\Server\McpTool;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Operation;
use KnowKit\Domain\ResourceRef;
use KnowKit\Port\AccessPolicy;

final readonly class NavigateOutlineTool implements McpTool
{
    private const ULID_PATTERN = '^[0-7][0-9A-HJKMNP-TV-Z]{25}$';

    public function __construct(
        private McpBackend $backend,
        private ?AccessPolicy $accessPolicy = null,
    ) {
    }

    public function name(): string
    {
        return 'knowkit.navigate_outline';
    }

    public function description(): string
    {
        return 'Read the R0 structural outline of a document revision.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['revisionId'],
            'properties' => [
                'revisionId' => [
                    'type' => 'string',
                    'pattern' => self::ULID_PATTERN,
                ],
                'maxDepth' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 6,
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 64,
                ],
            ],
        ];
    }

    public function execute(AuthenticatedMcpContext $context, array $arguments): array
    {
        $revisionId = (string) $arguments['revisionId'];
        $maxDepth = isset($arguments['maxDepth']) ? (int) $arguments['maxDepth'] : 4;
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 24;

        $context->disclosureMode->allowsTier(ResolutionTier::R0Outline)
            || throw new \KnowKit\Domain\DomainFailure(\KnowKit\Domain\Outcome::Denied, 'disclosure_tier_forbidden');

        if ($this->accessPolicy !== null) {
            $receipt = $this->accessPolicy->decide(
                $context->scope,
                new ResourceRef($context->scope->tenantId, 'document_revision', new DocumentRevisionId($revisionId)),
                Operation::Navigate,
            );
            $receipt->requireTier(ResolutionTier::R0Outline);
        }

        return $this->backend->navigateOutline($context, $revisionId, $maxDepth, $limit);
    }
}
