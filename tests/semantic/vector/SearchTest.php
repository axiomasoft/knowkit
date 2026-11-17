<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Vector;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Domain as D;
use KnowKit\PgVector\Domain\DocumentVectors;
use KnowKit\PgVector\Domain\QueryVector;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Vector\SearchSql;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:vector')]
final class SearchTest extends TestCase
{
    public function testDeniedNearestNeighborsTenantAndSnapshotAreExcludedBeforeTopK(): void
    {
        Fixture::run(function (Fixture $f): void {
            $index = $f->index();
            $query = new D\Query('synthetic question');
            self::assertSame(Fixture::corpus()['expected']['restrictedTop1'], $f->names($index->search($f->scope(), $query, 1, Fixture::space())));
            self::assertSame(Fixture::corpus()['expected']['restrictedTop2'], $f->names($index->search($f->scope(), $query, 2, Fixture::space())));
            self::assertSame(['denied'], $f->names($index->search($f->scope(['allowed', 'denied']), $query, 1, Fixture::space())));
            self::assertSame(['foreign'], $f->names($f->index(2)->search($f->scope(['foreign'], 2), $query, 1, Fixture::space())));
            // Tenant-qualified IDs intentionally collide; tenant A still resolves only A's chunk.
            self::assertSame(['allowed'], $f->names($index->search($f->scope(['foreign']), $query, 1, Fixture::space())));
            self::assertSame([], $f->names($index->search($f->scope(['other-snapshot']), $query, 1, Fixture::space())));
            self::assertSame([], $index->search($f->scope([]), $query, 1, Fixture::space())->candidates);
            self::assertSame(2, $index->searchWithDiagnostics($f->scope(), $query, 1, Fixture::space())->permittedCount);
            self::assertSame(0, $index->searchWithDiagnostics($f->scope([]), $query, 1, Fixture::space())->permittedCount);
            $tieVersion = $f->lexical->sets['tie']->chunkSet->revisionRef->sourceRef->sourceVersionId;
            self::assertSame(['tie'], $f->names($index->search($f->scope(), new D\Query('same', [$tieVersion]), 1, Fixture::space())));
            self::assertSame([], $index->search($f->scope(), new D\Query('same', null, []), 1, Fixture::space())->candidates);
            $this->assertPrefilterPlan($f, $query);
        });
    }

    private function assertPrefilterPlan(Fixture $f, D\Query $query): void
    {
        $db = $f->lexical->catalog->db;
        $view = $f->backend()->sealed($f->scope());
        $values = SearchSql::values($f->scope(), $query, $view);
        $plan = json_decode($db->execute('EXPLAIN (ANALYZE,VERBOSE,FORMAT JSON) ' . SearchSql::exact($db),
            [...$values, '[0,0,0]', 1])->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Limit', $plan[0]['Plan']['Node Type']);
        $walk = function (array $node) use (&$walk): ?array {
            if (($node['Subplan Name'] ?? '') === 'CTE permitted') {
                return $node;
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $found = $walk($child);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        };
        $permitted = $walk($plan[0]['Plan']);
        self::assertNotNull($permitted);
        self::assertSame(2, $permitted['Actual Rows']);
        self::assertStringNotContainsString('"Node Type":"Limit"', json_encode($permitted));
        self::assertStringNotContainsString('<->', json_encode($permitted));
        $raw = $db->execute('SELECT chunk_id FROM vector_documents WHERE tenant_id=? AND generation_id=?
            ORDER BY embedding OPERATOR(public.<->) CAST(? AS public.vector) LIMIT 1', [Fixture::id(1), Fixture::id(501), '[0,0,0]'])->fetchColumn();
        self::assertSame($f->lexical->sets['denied']->chunkSet->chunks[0]->ref->chunkId->value, $raw);
    }

    public function testUnsupportedPrefilterIsRejectedBeforeAnyProviderCall(): void
    {
        $calls = 0;
        foreach (['postfilter-only', 'none', 'unapproved-namespace'] as $capability) {
            LexicalFixture::failure(static function () use ($capability, &$calls): void {
                new SearchPolicy(filterCapability: $capability);
                ++$calls;
            }, 'prefilter_unsupported');
        }
        self::assertSame(0, $calls);
    }

    public function testEveryEqualDimensionSpaceMutationIsIncompatibleBeforeEmbedding(): void
    {
        Fixture::run(function (Fixture $f): void {
            foreach (Fixture::corpus()['mutations'] as $field => $case) {
                $space = Fixture::space([$field => $case['value']]);
                self::assertSame($case['digest'], $space->digest()->value);
                LexicalFixture::failure(fn () => $f->index()->search($f->scope(), new D\Query('private question'), 1, $space), 'space_mismatch');
            }
            self::assertSame(0, $f->queryCalls);
        });
    }

    public function testDocumentQueryRolesAndQueryBindingsAreEnforced(): void
    {
        Fixture::run(function (Fixture $f): void {
            $query = new D\Query('first question');
            $f->queryResponse = static fn ($scope, $q, $space): QueryVector => QueryVector::bind(
                new D\Vector([0, 0, 0], Fixture::space()), $space->documentRole, $scope, $q);
            LexicalFixture::failure(fn () => $f->index()->search($f->scope(), $query, 1, Fixture::space()), 'embedding_role_mismatch');
            $f->queryResponse = static fn ($scope, $q, $space): QueryVector => QueryVector::bind(
                new D\Vector([0, 0, 0], Fixture::space()), $space->queryRole, $scope, new D\Query('other question'));
            LexicalFixture::failure(fn () => $f->index()->search($f->scope(), $query, 1, Fixture::space()), 'scope_mismatch', D\Outcome::Denied);
            $f->queryResponse = static fn ($scope, $q, $space): QueryVector => QueryVector::bind(
                new D\Vector([0, 0, 0], Fixture::space()), $space->queryRole, $f->scope(generation: 502, snapshot: 511), $q);
            LexicalFixture::failure(fn () => $f->index()->search($f->scope(), $query, 1, Fixture::space()), 'scope_mismatch', D\Outcome::Denied);
            $f->queryResponse = static fn ($scope, $q, $space): QueryVector => QueryVector::bind(
                new D\Vector([0, 0, 0], Fixture::space(['revision' => 'r2'])), $space->queryRole, $scope, $q);
            LexicalFixture::failure(fn () => $f->index()->search($f->scope(), $query, 1, Fixture::space()), 'space_mismatch');
            foreach ([D\Outcome::Denied, D\Outcome::Unknown, D\Outcome::Unavailable, D\Outcome::Incompatible] as $outcome) {
                $f->queryResponse = static function () use ($outcome): never {
                    throw new D\DomainFailure($outcome, 'provider_typed_failure');
                };
                LexicalFixture::failure(fn () => $f->index()->search($f->scope(), $query, 1, Fixture::space()), 'provider_typed_failure', $outcome);
            }
        });
    }

    public function testAuthorizedHnswMeasuresRecallAndExposesExactFallback(): void
    {
        Fixture::run(function (Fixture $f): void {
            $query = new D\Query('question');
            $good = $f->index(policy: new SearchPolicy('authorized-hnsw', 40))->searchWithDiagnostics($f->scope(), $query, 2, Fixture::space());
            self::assertSame(1.0, $good->annRecall);
            self::assertSame(2, $good->permittedCount);
            self::assertFalse($good->fallbackUsed);
            self::assertSame('authorized-hnsw', $good->effectiveMode);
            $low = $f->index(policy: new SearchPolicy('authorized-hnsw', 1))->searchWithDiagnostics($f->scope(), $query, 2, Fixture::space());
            self::assertSame(0.5, $low->annRecall);
            self::assertTrue($low->fallbackUsed);
            self::assertSame('exact', $low->effectiveMode);
            self::assertSame('exact', $low->fallbackPolicy);
            self::assertSame(['allowed', 'tie'], $f->names($low->candidates));
            LexicalFixture::failure(fn () => $f->index(policy: new SearchPolicy('authorized-hnsw', 1, 'reject'))
                ->search($f->scope(), $query, 2, Fixture::space()), 'ann_recall_insufficient', D\Outcome::Unavailable);
            self::assertSame(0, (int) $f->lexical->catalog->db->execute("SELECT count(*) FROM pg_tables
                WHERE schemaname LIKE 'pg_temp_%' AND tablename LIKE 'kk_authorized_%'")->fetchColumn());
            $path = Fixture::root() . '/.phpunit.cache/P5.2';
            if (!is_dir($path)) {
                mkdir($path, 0700, true);
            }
            file_put_contents($path . '/ann-recall.json', json_encode(['schemaVersion' => 'vector-recall:1',
                'backend' => $f->lexical->catalog->db->execute("SELECT extversion FROM pg_extension WHERE extname='vector'")->fetchColumn(),
                'permittedCount' => 2, 'deniedNearestCount' => 1, 'k' => 2, 'ef40Recall' => $good->annRecall,
                'ef1Recall' => $low->annRecall, 'fallbackPolicy' => $low->fallbackPolicy,
                'fallbackUsed' => $low->fallbackUsed, 'effectiveMode' => $low->effectiveMode], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        });
    }

    public function testMissingOrChangedPersistedVectorIsNeverAnEmptyResult(): void
    {
        Fixture::run(function (Fixture $f): void {
            $db = $f->lexical->catalog->db;
            $role = $db->execute('SELECT current_user')->fetchColumn();
            $db->pdo->exec('RESET ROLE');
            try {
                $db->pdo->exec('ALTER TABLE vector_documents DISABLE TRIGGER vector_document_guard');
                $db->execute('DELETE FROM vector_documents WHERE tenant_id=? AND generation_id=? AND chunk_id=?',
                    [Fixture::id(1), Fixture::id(501), $f->lexical->sets['allowed']->chunkSet->chunks[0]->ref->chunkId->value]);
            } finally {
                $db->pdo->exec('ALTER TABLE vector_documents ENABLE TRIGGER vector_document_guard');
                $db->pdo->exec('SET ROLE ' . $role);
            }
            LexicalFixture::failure(fn () => $f->index()->search($f->scope(), new D\Query('question'), 1, Fixture::space()),
                'vector_generation_unreadable', D\Outcome::Unavailable);
            self::assertSame(0, $f->queryCalls);
        });
    }

    public function testRogueDocumentIdAndAlteredIdentityAreRejected(): void
    {
        Fixture::run(function (Fixture $f): void {
            $unknown = Fixture::id(6999);
            $original = $f->lexical->sets['allowed']->chunkSet->chunks[0]->ref;
            $unknownRef = new D\ChunkRef($original->revisionRef, $original->chunkSetId, new D\ChunkId($unknown), $original->chunkDigest);
            $documents = new DocumentVectors(Fixture::space(), 'passage', [$unknown => new D\Vector([0, 0, 0], Fixture::space())], [$unknown => $unknownRef]);
            $tx = $f->lexical->catalog->tx;
            LexicalFixture::failure(function () use ($f, $tx, $documents): void {
                $tx->run(function () use ($f, $tx, $documents): void {
                    $tx->lockTenant($f->scope()->tenantId, D\LockMode::Update);
                    $f->index(documents: $documents)->index(new D\GenerationId(Fixture::id(501)),
                        $f->lexical->sets['allowed']->chunkSet, Fixture::space());
                });
            }, 'candidate_not_in_manifest');
            CatalogFixture::sqlReject($f->lexical->catalog->db->pdo,
                'INSERT INTO vector_documents SELECT tenant_id,generation_id,?,space_digest,vector_digest,embedding FROM vector_documents LIMIT 1', [$unknown]);
            $manifest = $f->backend()->inspect($f->candidates['1:501']->manifest);
            $chunk = $manifest->chunk($f->lexical->sets['allowed']->chunkSet->chunks[0]->ref->chunkId->value);
            $ref = $chunk->ref;
            $rogue = new D\ChunkRef($ref->revisionRef, $ref->chunkSetId, $ref->chunkId, new D\Sha256(str_repeat('f', 64)));
            LexicalFixture::failure(fn () => $manifest->candidate->assertCandidate($rogue), 'candidate_not_in_manifest');
            $foreign = $f->lexical->sets['foreign']->chunkSet->chunks[0]->ref;
            $wrongTenant = new DocumentVectors(Fixture::space(), 'passage',
                [$foreign->chunkId->value => new D\Vector([0, 0, 0], Fixture::space())], [$foreign->chunkId->value => $foreign]);
            LexicalFixture::failure(fn () => $wrongTenant->assertInventory($manifest), 'scope_mismatch', D\Outcome::Denied);
            $db = $f->lexical->catalog->db;
            $other = $f->lexical->sets['other-snapshot']->chunkSet->chunks[0]->ref;
            $db->execute('INSERT INTO index_memberships (tenant_id,id,generation_id,chunk_id,chunk_digest,source_version_id,revision_id,chunk_set_id)
                VALUES (?,?,?,?,?,?,?,?)', [Fixture::id(1), Fixture::id(99991), Fixture::id(501), $other->chunkId->value,
                    $other->chunkDigest->value, $other->revisionRef->sourceRef->sourceVersionId->value,
                    $other->revisionRef->documentRevisionId->value, $other->chunkSetId->value]);
            $db->execute('INSERT INTO vector_documents SELECT tenant_id,generation_id,?,space_digest,vector_digest,embedding
                FROM vector_documents WHERE tenant_id=? AND generation_id=? LIMIT 1', [$other->chunkId->value, Fixture::id(1), Fixture::id(501)]);
            LexicalFixture::failure(fn () => $f->backend()->reload($manifest->candidate->manifest),
                'vector_generation_unreadable', D\Outcome::Unavailable);
        }, false);
    }

    public function testPersistedVectorDigestMismatchIsRejectedBeforeEmbedding(): void
    {
        Fixture::run(function (Fixture $f): void {
            $db = $f->lexical->catalog->db;
            $role = $db->execute('SELECT current_user')->fetchColumn();
            $db->pdo->exec('RESET ROLE');
            try {
                $db->pdo->exec('ALTER TABLE vector_documents DISABLE TRIGGER vector_document_guard');
                $db->execute("UPDATE vector_documents SET embedding=CAST('[9,0,0]' AS public.vector)
                    WHERE tenant_id=? AND generation_id=? AND chunk_id=?", [Fixture::id(1), Fixture::id(501),
                    $f->lexical->sets['allowed']->chunkSet->chunks[0]->ref->chunkId->value]);
            } finally {
                $db->pdo->exec('ALTER TABLE vector_documents ENABLE TRIGGER vector_document_guard');
                $db->pdo->exec('SET ROLE ' . $role);
            }
            LexicalFixture::failure(fn () => $f->index()->search($f->scope(), new D\Query('question'), 1, Fixture::space()),
                'vector_digest_mismatch');
            self::assertSame(0, $f->queryCalls);
        });
    }

    public function testLifecycleChangeDuringEmbeddingClosesTheCorpus(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->queryResponse = static function ($scope, $query, $space) use ($f): QueryVector {
                $source = $f->lexical->sets['allowed']->chunkSet->revisionRef->sourceRef->sourceId;
                $f->lexical->catalog->db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1
                    WHERE tenant_id=? AND id=?", [$scope->tenantId->value, $source->value]);

                return QueryVector::bind(new D\Vector([0, 0, 0], Fixture::space()), $space->queryRole, $scope, $query);
            };
            $result = $f->index()->search($f->scope(['allowed']), new D\Query('question'), 1, Fixture::space());
            self::assertSame([], $result->candidates);
        });
    }
}
