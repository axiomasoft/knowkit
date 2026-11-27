<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server\Tools;

use KnowKit\Bridge\Mcp\Server\AuthenticatedMcpContext;
use KnowKit\Bridge\Mcp\Server\McpBackend;
use KnowKit\Bridge\Mcp\Server\McpTool;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ResourceRef;
use KnowKit\Port\AccessPolicy;

final readonly class ReadUnitsTool implements McpTool
{
    private const ULID_PATTERN = '^[0-7][0-9A-HJKMNP-TV-Z]{25}$';

    public function __construct(
        private McpBackend $backend,
        private ?AccessPolicy $accessPolicy = null,
    ) {
    }

    public function name(): string
    {
        return 'knowkit.read_units';
    }

    public function description(): string
    {
        return 'Read verbatim source units when DisclosureMode::VerbatimAllowed is active.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['revisionId', 'unitIds'],
            'properties' => [
                'revisionId' => [
                    'type' => 'string',
                    'pattern' => self::ULID_PATTERN,
                ],
                'unitIds' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 16,
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 128,
                    ],
                ],
            ],
        ];
    }

    public function execute(AuthenticatedMcpContext $context, array $arguments): array
    {
        if (!$context->disclosureMode->allowsDirectRead()) {
            throw new DomainFailure(Outcome::Denied, 'verbatim_disclosure_forbidden');
        }

        $revisionId = (string) $arguments['revisionId'];
        /** @var list<string> $unitIds */
        $unitIds = array_values(array_map('strval', (array) $arguments['unitIds']));

        if ($this->accessPolicy !== null) {
            $receipt = $this->accessPolicy->decide(
                $context->scope,
                new ResourceRef($context->scope->tenantId, 'document_revision', new DocumentRevisionId($revisionId)),
                Operation::Disclose,
            );
            $receipt->requireDirectRead();
        }

        return $this->backend->readUnits($context, $revisionId, $unitIds);
    }
}
