<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server\Tools;

use KnowKit\Bridge\Mcp\Server\AuthenticatedMcpContext;
use KnowKit\Bridge\Mcp\Server\McpBackend;
use KnowKit\Bridge\Mcp\Server\McpTool;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ResourceRef;
use KnowKit\Port\AccessPolicy;

final readonly class DiscloseEvidenceTool implements McpTool
{
    public function __construct(
        private McpBackend $backend,
        private ?AccessPolicy $accessPolicy = null,
    ) {
    }

    public function name(): string
    {
        return 'knowkit.disclose_evidence';
    }

    public function description(): string
    {
        return 'Disclose evidence fragments for a SelectionToken under fresh AccessPolicy and DisclosureMode checks.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['selectionToken'],
            'properties' => [
                'selectionToken' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 256,
                ],
                'resolutionTier' => [
                    'type' => 'string',
                    'enum' => ['R0', 'R1', 'R2', 'R3'],
                ],
                'maxFragments' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 32,
                ],
            ],
        ];
    }

    public function execute(AuthenticatedMcpContext $context, array $arguments): array
    {
        $selectionToken = (string) $arguments['selectionToken'];
        $tierValue = isset($arguments['resolutionTier']) ? (string) $arguments['resolutionTier'] : ResolutionTier::R2Propositions->value;
        $tier = ResolutionTier::from($tierValue);
        $maxFragments = isset($arguments['maxFragments']) ? (int) $arguments['maxFragments'] : 8;

        if (!$context->disclosureMode->allowsTier($tier)) {
            throw new DomainFailure(
                Outcome::Denied,
                $tier === ResolutionTier::R3Verbatim ? 'verbatim_disclosure_forbidden' : 'disclosure_tier_forbidden',
            );
        }

        if ($this->accessPolicy === null) {
            throw new DomainFailure(Outcome::Denied, 'policy_denied');
        }
        $resource = new ResourceRef($context->scope->tenantId, 'knowledge_base', $context->scope->kbId);
        $receipt = $this->accessPolicy->decide(
            $context->scope,
            $resource,
            Operation::Disclose,
        );
        $receipt->requireAuthorized($context->scope, Operation::Disclose, $resource);
        $receipt->requireTier($tier);

        return $this->backend->discloseEvidence($context, $selectionToken, $tier, $maxFragments);
    }
}
