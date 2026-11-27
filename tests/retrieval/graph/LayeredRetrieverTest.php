<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Graph;

require_once dirname(__DIR__, 2) . '/semantic/hybrid/bootstrap.php';

use KnowKit\Application\Retrieval\Fusion\{LayeredRetriever, ReciprocalRankFusion};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Layers\{
    DeliveryMode,
    DisclosureMode,
    LayerBacking,
    LayerBudgetPolicy,
    LayerId,
    LayerNode,
    MergeStrategy,
    ResolutionTier,
    ResolvedLayerStack,
    TrustTier,
};
use KnowKit\Port\LexicalIndex;
use KnowKit\Tests\Retrieval\Selection\Fixture as Host;
use KnowKit\Tests\Semantic\Hybrid\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('retrieval:graph')]
final class LayeredRetrieverTest extends TestCase
{
    public function testAppliesEffectiveDenySetBeforeBackendSearchAndFusesWithLayerWeights(): void
    {
        $f = new Fixture();
        $allowedHit = $f->hit('allowed', 1);
        $tieHit = $f->hit('tie', 2);
        $exactHit = $f->hit('exact', 1);

        $deniedVersionId = $exactHit->ref->revisionRef->sourceRef->sourceVersionId;
        $scope = $f->lexical->scope;
        $filteredEligible = array_values(array_filter(
            $scope->allowedSourceVersionIds,
            static fn (D\SourceVersionId $id): bool => !$id->equals($deniedVersionId),
        ));

        $observedScopes = [];
        $lexical = new class ($f, $allowedHit, $tieHit, $observedScopes) implements LexicalIndex {
            /** @param list<D\AuthorizedSearchScope> $observedScopes */
            public function __construct(
                private Fixture $f,
                private D\Candidate $allowedHit,
                private D\Candidate $tieHit,
                private array &$observedScopes,
            ) {}

            public function index(D\GenerationId $generationId, D\ChunkSet $chunkSet): D\IndexReceipt
            {
                throw new \LogicException('Unused');
            }

            public function search(D\AuthorizedSearchScope $scope, D\Query $query, int $k): D\CandidateList
            {
                $this->observedScopes[] = $scope;
                if (count($this->observedScopes) === 1) {
                    return new D\CandidateList($scope, [$this->allowedHit, $this->tieHit]);
                }

                return new D\CandidateList($scope, [$this->f->hit('tie', 1), $this->f->hit('allowed', 2)]);
            }
        };

        $stack = $this->makeStack(
            $scope->tenantId,
            $scope->kbId,
            $scope->snapshotId,
            $f->lexical->inventory->manifest->digest,
            [new D\LocalId('source_version:' . $deniedVersionId->value)],
        );

        $retriever = new LayeredRetriever(
            $lexical,
            new ReciprocalRankFusion(),
            function (LayerNode $node, array $denySelectors) use ($f, $filteredEligible): D\SearchCorpus {
                $denyValues = array_map(static fn (D\LocalId $id): string => $id->value, $denySelectors);
                $eligible = array_values(array_filter(
                    $filteredEligible,
                    static fn (D\SourceVersionId $id): bool => !in_array('source_version:' . $id->value, $denyValues, true),
                ));
                $grant = new D\QueryGrant(new JsonObject([
                    'allowedCollectionIds' => [],
                    'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $eligible),
                    'grantEpoch' => '0',
                    'policyBindingDigest' => $f->lexical->scope->policyBindingDigest->value,
                    'expiresAt' => $f->host->now->plusSeconds(30)->value,
                ]), \KnowKit\Tests\Contract\Ports\PortFixture::validator());
                $scoped = D\AuthorizedSearchScope::materialize(
                    $f->host->identity->scope,
                    $grant,
                    $f->lexical->inventory->snapshot,
                    $f->lexical->inventory->generationId(),
                    $eligible,
                    $f->host->now,
                );

                return new D\SearchCorpus($scoped, $f->lexical->inventory);
            },
        );

        $result = $retriever->search($f->host->context(), $stack, $f->query(new D\SearchProfile()), 16);

        self::assertSame(D\Outcome::Success, $result->outcome);
        self::assertNotNull($result->data);
        // Only the 2 hybrid_retrievable layers should query the backend (pinned_prefix is skipped).
        self::assertCount(2, $observedScopes);
        foreach ($observedScopes as $receivedScope) {
            $versionValues = array_map(static fn (D\SourceVersionId $id): string => $id->value, $receivedScope->allowedSourceVersionIds);
            self::assertNotContains($deniedVersionId->value, $versionValues);
        }
        // Layer 2 (weight 8000) ranked 'tie' #1; Layer 1 (weight 2000) ranked 'allowed' #1 -> 'tie' wins!
        self::assertSame($tieHit->ref->chunkId->value, $result->data->candidates[0]->ref->chunkId->value);
    }

    public function testFailsClosedWhenResolverLeavesDeniedVersionInScope(): void
    {
        $f = new Fixture();
        $deniedVersionId = $f->hit('allowed', 1)->ref->revisionRef->sourceRef->sourceVersionId;

        $lexical = new class implements LexicalIndex {
            public int $calls = 0;

            public function index(D\GenerationId $generationId, D\ChunkSet $chunkSet): D\IndexReceipt
            {
                throw new \LogicException('Unused');
            }

            public function search(D\AuthorizedSearchScope $scope, D\Query $query, int $k): D\CandidateList
            {
                $this->calls++;

                return new D\CandidateList($scope, []);
            }
        };

        $stack = $this->makeStack(
            $f->lexical->scope->tenantId,
            $f->lexical->scope->kbId,
            $f->lexical->scope->snapshotId,
            $f->lexical->inventory->manifest->digest,
            [new D\LocalId($deniedVersionId->value)],
        );

        $retriever = new LayeredRetriever(
            $lexical,
            new ReciprocalRankFusion(),
            static fn (LayerNode $node, array $denySelectors): D\SearchCorpus => $f->lexical,
        );

        $result = $retriever->search($f->host->context(), $stack, $f->query(new D\SearchProfile()), 16);

        self::assertSame(D\Outcome::Denied, $result->outcome);
        self::assertSame('scope_mismatch', $result->errorCode);
        self::assertSame(0, $lexical->calls);
    }

    /** @param list<D\LocalId> $effectiveDenySelectors */
    private function makeStack(
        D\TenantId $tenantId,
        D\KbId $kbId,
        D\SnapshotId $snapshotId,
        D\Fingerprint $manifestDigest,
        array $effectiveDenySelectors,
    ): ResolvedLayerStack {
        $snapshotRef = new D\SnapshotRef($tenantId, $kbId, $snapshotId, $manifestDigest);
        $pinned = new LayerNode(
            layerId: new LayerId('pinned.rules'),
            dependsOnLayerIds: [],
            trustTier: TrustTier::TrustedDirective,
            deliveryMode: DeliveryMode::PinnedPrefix,
            mergeStrategy: MergeStrategy::ImmutableSeal,
            disclosureMode: DisclosureMode::VerbatimAllowed,
            lockedKeys: [],
            ruleValues: new JsonObject([]),
            denySelectors: [],
            budget: new LayerBudgetPolicy(100, 1000, 4096, 10000, ResolutionTier::R0Outline, ResolutionTier::R0Outline),
            backing: LayerBacking::directive(new D\Ulid(Host::id(901)), new D\Sha256(str_repeat('a', 64))),
        );
        $layer1 = new LayerNode(
            layerId: new LayerId('evidence.local'),
            dependsOnLayerIds: [new LayerId('pinned.rules')],
            trustTier: TrustTier::CuratedReference,
            deliveryMode: DeliveryMode::HybridRetrievable,
            mergeStrategy: MergeStrategy::WeightedMix,
            disclosureMode: DisclosureMode::SummaryOnly,
            lockedKeys: [],
            ruleValues: new JsonObject([]),
            denySelectors: [],
            budget: new LayerBudgetPolicy(100, 2000, 8192, 2000, ResolutionTier::R1Digest, ResolutionTier::R0Outline),
            backing: LayerBacking::snapshot($snapshotRef),
        );
        $layer2 = new LayerNode(
            layerId: new LayerId('evidence.mount'),
            dependsOnLayerIds: [new LayerId('pinned.rules')],
            trustTier: TrustTier::CuratedReference,
            deliveryMode: DeliveryMode::HybridRetrievable,
            mergeStrategy: MergeStrategy::WeightedMix,
            disclosureMode: DisclosureMode::SummaryOnly,
            lockedKeys: [],
            ruleValues: new JsonObject([]),
            denySelectors: $effectiveDenySelectors,
            budget: new LayerBudgetPolicy(100, 2000, 8192, 8000, ResolutionTier::R1Digest, ResolutionTier::R0Outline),
            backing: LayerBacking::catalogMount(
                new D\Ulid(Host::id(902)),
                new D\TenantId(Host::id(903)),
                new D\Sha256(str_repeat('b', 64)),
            ),
        );

        return new ResolvedLayerStack(
            tenantId: $tenantId,
            stackId: new D\Ulid(Host::id(900)),
            nodes: [$pinned, $layer1, $layer2],
            lockedKeyOwners: [],
            effectiveDenySelectors: $effectiveDenySelectors,
            staticPrefixDigest: new D\Fingerprint(str_repeat('1', 64)),
            sessionPrefixDigest: new D\Fingerprint(str_repeat('2', 64)),
            stackDigest: new D\Fingerprint(str_repeat('3', 64)),
        );
    }
}
