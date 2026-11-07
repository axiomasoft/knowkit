<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Selection;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Clock;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\LexicalIndex;
use KnowKit\Port\SelectionCatalog;
use KnowKit\Port\SelectionStore;

/** Selection is a bounded reference discovery capability, never permission to disclose text. */
final readonly class Selections
{
    public function __construct(
        private PolicyChecks $policy,
        private LexicalIndex $index,
        private SelectionCatalog $catalog,
        private SelectionStore $store,
        private BoundedSelector $selector,
        private Clock $clock,
        private IdGenerator $ids,
        private ContractValidator $validator,
    ) {}

    /** @param list<D\SourceVersionId> $hostEligible Trusted host universe, intersected before top-k. */
    public function retrieve(
        AccessContext $context,
        D\QueryBinding $query,
        D\SnapshotRef $snapshot,
        D\GenerationId $generation,
        array $hostEligible,
        JsonObject $policyBinding,
        ?string $runRef = null,
        int $ttl = 30,
        int $topK = 64,
        ?D\CurrentMemberships $memberships = null,
    ): D\RetrievalOutcome {
        try {
            if ($ttl < 1 || $ttl > 300) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            if ($runRef !== null) {
                D\ValueGuard::text($runRef, 256);
            }
            $scope = $this->policy->authorizeQuery($context, $snapshot, $generation,
                $hostEligible, $policyBinding, $query->original, $memberships);
            $inventory = $this->catalog->load($context->scope(), $snapshot, $generation);
            $inventory->assertSearch($scope, $query);
            $candidates = $this->index->search($scope, $query->normalized, $topK);
            if ($candidates->scope != $scope) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }
            $selected = $this->selector->choose($scope, $candidates->candidates, $inventory, $query->limits);
            $context->assertCurrent();

            return $this->issue($context, $query, $inventory, $selected, $policyBinding, $runRef, $ttl);
        } catch (D\DomainFailure $failure) {
            return new D\RetrievalOutcome(D\Result::failure($failure));
        } catch (\Throwable) {
            return new D\RetrievalOutcome(D\Result::failure(
                new D\DomainFailure(D\Outcome::Unavailable, 'retrieval_unavailable'),
            ));
        }
    }

    /**
     * Binding validation only. Disclosure MUST still acquire lifecycle guards, check current
     * ACL/ancestors, load canonical bytes and commit a fresh usage at its actual boundary.
     * Unknown, foreign and expired tokens disclose no references through this method.
     */
    public function requireBound(
        AccessContext $context,
        D\SelectionToken $token,
        D\QueryBinding $query,
        D\SnapshotRef $snapshot,
        ?string $runRef = null,
    ): D\SelectionRecord {
        $context->assertCurrent();
        $record = $this->store->get($context->scope(), $token->hash())
            ?? throw new D\DomainFailure(D\Outcome::Denied, 'selection_invalid');
        $context->assertCurrent();
        $record->requireBinding($context->policy, $query, $snapshot, $runRef, $this->clock->now());

        return $record;
    }

    private function issue(
        AccessContext $context,
        D\QueryBinding $query,
        D\SelectionInventory $inventory,
        D\SelectedReferences $selected,
        JsonObject $binding,
        ?string $runRef,
        int $ttl,
    ): D\RetrievalOutcome {
        if ($selected->refs === []) {
            return new D\RetrievalOutcome(D\Result::empty($query->limits->profile, $selected->warnings),
                null, $selected->readRequirements);
        }
        $now = $this->clock->now();
        $scope = $context->scope();
        $selection = new D\Selection(new JsonObject([
            'selectionId' => $this->ids->next()->value,
            'tenantId' => $scope->tenantId->value,
            'hostScopeHash' => $scope->hostScopeHash->value,
            'runRef' => $runRef,
            'scopeBindingDigest' => $scope->bindingDigest(new D\Sha256(HashDomain::PolicyBinding->digest($binding)))->value,
            'queryDigest' => $query->digest()->value,
            'snapshotId' => $inventory->snapshot->snapshotId->value,
            'generationId' => $inventory->generationId()->value,
            'candidateRefs' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $selected->refs),
            'limits' => $query->limits->toWire(),
            'policyBinding' => $binding,
            'expiresAt' => $now->plusSeconds($ttl)->value,
        ]), $this->validator);
        $record = new D\SelectionRecord($selection, $context->policy, $query, $inventory->snapshot, $now);
        $token = D\SelectionToken::issue();
        $this->store->put($scope, $token->hash(), $record);

        return new D\RetrievalOutcome(D\Result::success($selection, false, $query->limits->profile, $selected->warnings),
            $token, $selected->readRequirements);
    }
}
