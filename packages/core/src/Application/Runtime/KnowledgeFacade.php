<?php
declare(strict_types=1);

namespace KnowKit\Application\Runtime;

use KnowKit\Application\Access\{AccessContext, HostIdentity};
use KnowKit\Application\Lifecycle\LifecycleCommands;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Application\SourceNavigation;
use KnowKit\Domain\{DisclosureOutcome, DisclosureRequest, DocumentRevisionRef, DomainFailure, EffectiveProfile, IdempotencyKey, LockMode, Outcome, ProfileId, PublishReceipt, Query, QueryBinding, Result, Revocation, Scope, Selection, SelectionLimits, SelectionToken, SnapshotRef, SnapshotSelector, SourceId, SourceVersionId, Ulid};
use KnowKit\Domain\Contract\{ContractValidator, JsonObject};
use KnowKit\Domain\Navigation\StructureOutline;
use KnowKit\Port\{CatalogRepository, CatalogTransaction, IdGenerator};

/**
 * Facade over real transactional use-cases (Ingestion/Publishing, Selection -> FreshDisclosure, SourceNavigation, Lifecycle).
 * Never bypasses Selection -> FreshDisclosure or host-authenticated AccessContext checks.
 */
final readonly class KnowledgeFacade
{
    /**
     * @param Selections|\Closure(): Selections $selections
     * @param Disclosures|\Closure(): Disclosures $disclosures
     * @param \Closure(Scope, string, string, string, string): PublishReceipt $ingestAndPublish
     * @param \Closure(Scope): list<SourceVersionId> $eligibleVersions
     * @param \Closure(Scope): JsonObject $policyBinding
     */
    public function __construct(
        private HostIdentity $host,
        private CatalogRepository $catalog,
        private CatalogTransaction $transaction,
        private Selections|\Closure $selections,
        private Disclosures|\Closure $disclosures,
        private SourceNavigation $navigation,
        private LifecycleCommands $lifecycle,
        private IdGenerator $ids,
        private ContractValidator $validator,
        private EffectiveProfile $effectiveProfile,
        private ProfileId $searchProfileId,
        private \Closure $ingestAndPublish,
        private \Closure $eligibleVersions,
        private \Closure $policyBinding,
    ) {
    }

    public function ingestText(
        Scope $scope,
        string $sourceKey,
        string $utf8,
        string $mediaType = 'text/markdown',
        string $rightsRef = 'local:default',
    ): PublishReceipt {
        return ($this->ingestAndPublish)($scope, $sourceKey, $utf8, $mediaType, $rightsRef);
    }

    public function searchAndDisclose(
        Scope $scope,
        Query $query,
        ?SelectionLimits $limits = null,
    ): DisclosureOutcome {
        try {
            $context = AccessContext::fromHost($this->host);
            if (!$scope->equals($context->scope())) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }
            $snapshot = $this->transaction->run(function () use ($scope): ?\KnowKit\Domain\PublicationSnapshot {
                $this->transaction->lockTenant($scope->tenantId, LockMode::Share);
                $this->transaction->lockKb($scope->kbId, LockMode::Share);

                return $this->catalog->resolveSnapshot(
                    $scope->tenantId,
                    $scope->kbId,
                    SnapshotSelector::active(),
                    $this->searchProfileId,
                );
            });
            if ($snapshot === null) {
                throw new DomainFailure(Outcome::Unavailable, 'publication_unavailable');
            }
            $bound = (new Normalizer())->bind(
                $query,
                $this->effectiveProfile,
                $limits ?? SelectionLimits::profile('compact'),
            );
            $retrieval = $this->selections()->retrieve(
                $context,
                $bound,
                $snapshot->ref,
                $snapshot->generationIds[0],
                ($this->eligibleVersions)($scope),
                ($this->policyBinding)($scope),
            );
            $selection = $retrieval->result->data;
            if (!$selection instanceof Selection || $retrieval->token === null) {
                if ($retrieval->result->outcome === Outcome::Empty) {
                    return new DisclosureOutcome(Result::empty(
                        $retrieval->result->effectiveProfile,
                        $retrieval->result->warnings,
                        $retrieval->result->degraded,
                    ));
                }
                throw new DomainFailure(
                    $retrieval->result->outcome,
                    $retrieval->result->errorCode ?? 'selection_unavailable',
                );
            }
            $request = new DisclosureRequest(new JsonObject([
                'selectionId' => $selection->id()->value,
                'chunkRefs' => array_map(
                    static fn(\KnowKit\Domain\ChunkRef $ref): JsonObject => $ref->toWire(),
                    $selection->candidateRefs(),
                ),
                'disclosureAttemptId' => $this->ids->next()->value,
            ]), $this->validator);

            return $this->disclosures()->disclose($context, $retrieval->token, $bound, $snapshot->ref, $request);
        } catch (DomainFailure $failure) {
            return new DisclosureOutcome(Result::failure($failure));
        }
    }

    public function discloseToken(
        Scope $scope,
        SelectionToken $token,
        QueryBinding $query,
        SnapshotRef $snapshot,
        DisclosureRequest $request,
    ): DisclosureOutcome {
        try {
            $context = AccessContext::fromHost($this->host);
            if (!$scope->equals($context->scope())
                || !$scope->tenantId->equals($snapshot->tenantId)
                || !$scope->kbId->equals($snapshot->kbId)) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }

            return $this->disclosures()->disclose($context, $token, $query, $snapshot, $request);
        } catch (DomainFailure $failure) {
            return new DisclosureOutcome(Result::failure($failure));
        }
    }

    public function outline(
        Scope $scope,
        DocumentRevisionRef $revision,
        int $depth = 4,
        int $limit = 24,
    ): StructureOutline {
        return $this->navigation->listStructure($scope, $revision, $depth, $limit);
    }

    public function revoke(Scope $scope, Ulid $sourceId, string $reason): void
    {
        $source = $this->catalog->getSource($scope->tenantId, new SourceId($sourceId->value))
            ?? throw new DomainFailure(Outcome::Denied, 'source_unavailable');
        $this->lifecycle->revoke(
            $scope,
            new Revocation($source->sourceId, $source->lifecycleEpoch, $reason),
            new IdempotencyKey('revoke-' . $this->ids->next()->value),
        );
    }

    private function selections(): Selections
    {
        return $this->selections instanceof \Closure ? ($this->selections)() : $this->selections;
    }

    private function disclosures(): Disclosures
    {
        return $this->disclosures instanceof \Closure ? ($this->disclosures)() : $this->disclosures;
    }
}
