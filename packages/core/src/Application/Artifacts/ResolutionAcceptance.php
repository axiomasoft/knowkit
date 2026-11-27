<?php
declare(strict_types=1);

namespace KnowKit\Application\Artifacts;

use KnowKit\Domain\{
    Decision,
    DomainFailure,
    Fingerprint,
    IrDocument,
    LockMode,
    Node,
    Outcome,
    Parents,
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
            $report = $this->dependencies->checkAncestors($parents);
            if ($report->decision !== Decision::Allow) {
                throw new DomainFailure(
                    $report->decision === Decision::Deny ? Outcome::Denied : Outcome::Unknown,
                    $report->decision === Decision::Deny ? 'ancestor_unusable' : 'ancestor_unknown',
                );
            }

            $identity = new JsonObject([
                'tenantId' => $tenantId->value,
                'manifestId' => $manifest->manifestId->value,
                'manifestDigest' => $manifest->manifestDigest->value,
            ]);
            $node = new Node(
                new ResourceRef($tenantId, 'resolution_manifest', $manifest->manifestId),
                new Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity)),
            );

            $this->store->saveManifest($manifest);
            $this->dependencies->register($node, $parents);
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
