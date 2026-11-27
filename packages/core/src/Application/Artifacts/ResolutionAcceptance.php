<?php
declare(strict_types=1);

namespace KnowKit\Application\Artifacts;

use KnowKit\Domain\{
    DomainFailure,
    Fingerprint,
    IrDocument,
    LockMode,
    Node,
    Outcome,
    Parents,
    ResourceId,
    ResourceRef,
    TenantId,
};
use KnowKit\Domain\Artifacts\{DigestArtifact, PropositionSetArtifact, ResolutionManifest};
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject};
use KnowKit\Port\{AtomVerifier, CatalogTransaction, DependencyStore, ResolutionStore};

final readonly class ResolutionAcceptance
{
    public function __construct(
        private ContractValidator $validator,
        private AtomicSpanGuard $guard,
        private DependencyStore $dependencies,
        private ResolutionStore $store,
        private ?AtomVerifier $verifier = null,
        private ?CatalogTransaction $tx = null,
    ) {
    }

    public function accept(
        ResolutionManifest $manifest,
        IrDocument $ir,
        DigestArtifact|PropositionSetArtifact|null $derivedArtifact = null,
    ): ResolutionManifest {
        $manifest->assertValid($this->validator);

        $revisionRef = $manifest->documentRevisionRef;
        $tenantId = $revisionRef->sourceRef->tenantId;
        $irTenant = new TenantId((string) $ir->toWire()->get('tenantId'));
        if (!$irTenant->equals($tenantId)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }

        if ($derivedArtifact !== null) {
            if (!$derivedArtifact->documentRevisionRef->equals($revisionRef)) {
                throw new DomainFailure(Outcome::Incompatible, 'revision_mismatch');
            }
            $this->guard->assertPreserved($ir, $derivedArtifact, $this->verifier);
        }

        $persist = function () use ($manifest, $revisionRef, $tenantId): void {
            $parents = new Parents($tenantId, [
                new ResourceRef($tenantId, 'source_version', $revisionRef->sourceRef->sourceVersionId),
                new ResourceRef($tenantId, 'document_revision', $revisionRef->documentRevisionId),
            ]);
            $identity = new JsonObject([
                'tenantId' => $tenantId->value,
                'manifestId' => $manifest->manifestId->value,
                'manifestDigest' => $manifest->manifestDigest->value,
            ]);
            $node = new Node(
                new ResourceRef($tenantId, 'resource', new ResourceId($manifest->manifestId->value)),
                new Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity)),
            );

            $this->dependencies->register($node, $parents);
            $this->store->saveManifest($manifest);
        };

        if ($this->tx !== null) {
            $this->tx->run(function () use ($tenantId, $persist): void {
                $this->tx->lockTenant($tenantId, LockMode::Update);
                $persist();
            });
        } else {
            $persist();
        }

        return $manifest;
    }
}
