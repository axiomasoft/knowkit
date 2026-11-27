<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Graph;

require_once dirname(__DIR__, 2) . '/semantic/hybrid/bootstrap.php';
require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Adapter\Postgres\Graph\PdoConceptEdgeIndex;
use KnowKit\Application\Retrieval\Graph\GraphExpander;
use KnowKit\Domain as D;
use KnowKit\Tests\Semantic\Hybrid\Fixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('retrieval:graph')]
final class GraphExpanderTest extends TestCase
{
    public function testPdoConceptEdgeIndexPutNeighborsAndEnforcesSealedSnapshotGuard(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->kb(1);

            $tenantId = new D\TenantId(CatalogFixture::id(1));
            $kbId = new D\KbId(CatalogFixture::id(100));
            $snapshotId = new D\SnapshotId(CatalogFixture::id(200));

            $fx->tx->run(function () use ($fx, $tenantId, $kbId, $snapshotId): void {
                $fx->tx->lockTenant($tenantId, D\LockMode::Update);
                $fx->db->execute(
                    'INSERT INTO ' . $fx->db->table('publication_snapshots') .
                    ' (tenant_id, id, kb_id, manifest_digest, created_at, sealed) VALUES (?, ?, ?, ?, ?, false)',
                    [$tenantId->value, $snapshotId->value, $kbId->value, str_repeat('a', 64), $fx->clock->now()->value],
                );
            });

            $index = new PdoConceptEdgeIndex($fx->db, $fx->tx);
            $c1 = new D\ChunkId(CatalogFixture::id(6001));
            $c2 = new D\ChunkId(CatalogFixture::id(6002));
            $c3 = new D\ChunkId(CatalogFixture::id(6003));

            $edges = [
                new D\ConceptEdge($c1, $c2, 'defines', 9000, 'deterministic'),
                new D\ConceptEdge($c1, $c3, 'cites', 4000, 'source'),
            ];

            $index->putEdges($tenantId, $kbId, $snapshotId, $edges);
            $index->putEdges($tenantId, $kbId, $snapshotId, $edges); // idempotent replay

            $neighbors = $index->neighbors($tenantId, $kbId, $snapshotId, [$c1], ['defines', 'cites'], 10);
            self::assertCount(2, $neighbors);
            self::assertSame($c2->value, $neighbors[0]->toChunkId->value);
            self::assertSame(9000, $neighbors[0]->weightBps);
            self::assertSame($c3->value, $neighbors[1]->toChunkId->value);

            $filtered = $index->neighbors($tenantId, $kbId, $snapshotId, [$c1], ['defines'], 10);
            self::assertCount(1, $filtered);
            self::assertSame('defines', $filtered[0]->relation);

            // Sealed snapshot rejects concept_edges writes via trg_concept_edges_sealed_guard.
            $sealedSnapshotId = new D\SnapshotId(CatalogFixture::id(201));
            $fx->tx->run(function () use ($fx, $tenantId, $kbId, $sealedSnapshotId): void {
                $fx->tx->lockTenant($tenantId, D\LockMode::Update);
                $fx->db->execute(
                    'INSERT INTO ' . $fx->db->table('publication_snapshots') .
                    ' (tenant_id, id, kb_id, manifest_digest, created_at, sealed) VALUES (?, ?, ?, ?, ?, true)',
                    [$tenantId->value, $sealedSnapshotId->value, $kbId->value, str_repeat('b', 64), $fx->clock->now()->value],
                );
            });

            try {
                $index->putEdges($tenantId, $kbId, $sealedSnapshotId, [
                    new D\ConceptEdge($c2, $c3, 'proves', 7000, 'generated'),
                ]);
                self::fail('Expected storage_conflict on sealed snapshot');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
                self::assertSame('storage_conflict', $failure->errorCode);
            }
        });
    }

    public function testGraphExpanderEnforcesScopeAndEffectiveDenySetBeforeAddingCandidates(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $f = new Fixture();
            $allowedHit = $f->hit('allowed', 1);
            $tieHit = $f->hit('tie', 1);
            $exactHit = $f->hit('exact', 1);
            $neighborHit = $f->hit('neighbor', 1);

            $deniedVersionId = $exactHit->ref->revisionRef->sourceRef->sourceVersionId;
            $eligible = array_values(array_filter(
                $f->lexical->scope->allowedSourceVersionIds,
                static fn (D\SourceVersionId $id): bool => !$id->equals($deniedVersionId),
            ));
            $grant = new D\QueryGrant(new D\Contract\JsonObject([
                'allowedCollectionIds' => [],
                'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $eligible),
                'grantEpoch' => '0',
                'policyBindingDigest' => $f->lexical->scope->policyBindingDigest->value,
                'expiresAt' => $f->host->now->plusSeconds(30)->value,
            ]), \KnowKit\Tests\Contract\Ports\PortFixture::validator());
            $scope = D\AuthorizedSearchScope::materialize(
                $f->host->identity->scope,
                $grant,
                $f->lexical->inventory->snapshot,
                $f->lexical->inventory->generationId(),
                $eligible,
                $f->host->now,
            );

            $fx->tenant(1);
            $fx->kb(1);
            $fx->tx->run(function () use ($fx, $scope): void {
                $fx->tx->lockTenant($scope->tenantId, D\LockMode::Update);
                $fx->db->execute(
                    'INSERT INTO ' . $fx->db->table('publication_snapshots') .
                    ' (tenant_id, id, kb_id, manifest_digest, created_at, sealed) VALUES (?, ?, ?, ?, ?, false)',
                    [
                        $scope->tenantId->value,
                        $scope->snapshotId->value,
                        $scope->kbId->value,
                        str_repeat('a', 64),
                        $fx->clock->now()->value,
                    ],
                );
            });

            $index = new PdoConceptEdgeIndex($fx->db, $fx->tx);
            $a = $allowedHit->ref->chunkId;
            $b = $tieHit->ref->chunkId;        // authorized & allowed (1-hop)
            $c = $exactHit->ref->chunkId;      // unauthorized (sourceVersionId not in $scope)
            $d = $neighborHit->ref->chunkId;   // in $scope, but denied via effectiveDenySet
            $extraHop3 = new D\ChunkId(CatalogFixture::id(6999));

            $index->putEdges($scope->tenantId, $scope->kbId, $scope->snapshotId, [
                new D\ConceptEdge($a, $b, 'defines', 9000, 'deterministic'),
                new D\ConceptEdge($a, $c, 'defines', 9500, 'deterministic'),
                new D\ConceptEdge($a, $d, 'defines', 9400, 'deterministic'),
                new D\ConceptEdge($b, $extraHop3, 'cites', 8000, 'deterministic'), // excluded relation
            ]);

            $refsById = [
                $allowedHit->ref->chunkId->value => $allowedHit->ref,
                $tieHit->ref->chunkId->value => $tieHit->ref,
                $exactHit->ref->chunkId->value => $exactHit->ref,
                $neighborHit->ref->chunkId->value => $neighborHit->ref,
            ];

            $expander = new GraphExpander(
                static fn (D\ChunkId $id): ?D\ChunkRef => $refsById[$id->value] ?? null,
            );
            $policy = new D\GraphExpansionPolicy(
                maxHops: 2,
                maxExpandedNodes: 32,
                allowedRelations: ['defines', 'proves'],
                effectiveDenySet: [new D\LocalId('chunk:' . $d->value)],
            );

            $result = $expander->expand($scope, new D\CandidateList($scope, [$allowedHit]), $index, $policy);

            $resultIds = array_map(static fn (D\Candidate $hit): string => $hit->ref->chunkId->value, $result->candidates);
            self::assertSame([$a->value, $b->value], $resultIds);
            self::assertNotContains($c->value, $resultIds);
            self::assertNotContains($d->value, $resultIds);
            self::assertNotContains($extraHop3->value, $resultIds);
        });
    }

    public function testGraphExpansionPolicyEnforcesHardCaps(): void
    {
        foreach ([[3, 32], [2, 33], [-1, 10], [1, -1]] as [$hops, $nodes]) {
            try {
                new D\GraphExpansionPolicy($hops, $nodes);
                self::fail("Expected DomainFailure for maxHops={$hops}, maxExpandedNodes={$nodes}");
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
                self::assertSame('limit_exceeded', $failure->errorCode);
            }
        }
    }
}
