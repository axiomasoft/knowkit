<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ResourceRef;
use KnowKit\Domain\Ulid;
use KnowKit\Port\AccessPolicy;

final readonly class ResourceTemplateHandler
{
    public function __construct(
        private McpBackend $backend,
        private ?AccessPolicy $accessPolicy = null,
    ) {
    }

    /**
     * @return list<array{uriTemplate: string, name: string, description: string, mimeType: string}>
     */
    public function templates(): array
    {
        return [
            [
                'uriTemplate' => 'knowkit://{instance}/{kb}/outline/{revision}',
                'name' => 'Document Outline (R0)',
                'description' => 'Structural R0 outline for a document revision.',
                'mimeType' => 'application/json',
            ],
            [
                'uriTemplate' => 'knowkit://{instance}/{kb}/digest/{artifactId}',
                'name' => 'Layer Digest (R1)',
                'description' => 'Compiled R1 digest artifact.',
                'mimeType' => 'application/json',
            ],
            [
                'uriTemplate' => 'knowkit://{instance}/{kb}/citation/{citationId}',
                'name' => 'Citation Verbatim (R3)',
                'description' => 'Verbatim citation payload (requires DisclosureMode::VerbatimAllowed).',
                'mimeType' => 'application/json',
            ],
        ];
    }

    /**
     * @return list<array{uri: string, name: string, mimeType: string, description: string}>
     */
    public function list(AuthenticatedMcpContext $context): array
    {
        return $this->backend->listResources($context);
    }

    /**
     * @return array{contents: list<array{uri: string, mimeType: string, text: string}>}
     */
    public function read(AuthenticatedMcpContext $context, string $uri): array
    {
        if (preg_match('#^knowkit://([^/]+)/([^/]+)/(outline|digest|citation)/([^/]+)$#D', $uri, $matches) !== 1) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_resource_uri');
        }

        [, $instance, $kb, $kind, $id] = $matches;
        if ($instance !== $context->instanceId->value || $kb !== $context->scope->kbId->value) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }

        $tier = match ($kind) {
            'outline' => ResolutionTier::R0Outline,
            'digest' => ResolutionTier::R1Digest,
            'citation' => ResolutionTier::R3Verbatim,
        };

        if (!$context->disclosureMode->allowsTier($tier)) {
            throw new DomainFailure(
                Outcome::Denied,
                $tier === ResolutionTier::R3Verbatim ? 'verbatim_disclosure_forbidden' : 'disclosure_tier_forbidden',
            );
        }

        if ($this->accessPolicy !== null) {
            $resource = match ($kind) {
                'outline' => new ResourceRef($context->scope->tenantId, 'document_revision', new DocumentRevisionId($id)),
                'digest' => new ResourceRef($context->scope->tenantId, 'directive_artifact', new Ulid($id)),
                'citation' => new ResourceRef($context->scope->tenantId, 'knowledge_base', $context->scope->kbId),
            };
            $operation = $kind === 'outline' ? Operation::Navigate : Operation::Disclose;
            $receipt = $this->accessPolicy->decide($context->scope, $resource, $operation);
            $receipt->requireAuthorized($context->scope, $operation, $resource);
            $receipt->requireTier($tier);
        }

        $payload = match ($kind) {
            'outline' => $this->backend->readOutlineResource($context, $kb, $id),
            'digest' => $this->backend->readDigestResource($context, $kb, $id),
            'citation' => $this->backend->readCitationResource($context, $kb, $id),
        };

        return [
            'contents' => [$payload],
        ];
    }
}
