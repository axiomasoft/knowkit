<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Vector;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Adapter\Lexical\Bm25ProfileContract;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\PgVector\Domain\DocumentVectors;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Domain\VectorPayload;
use KnowKit\PgVector\Vector\PdoVectorSearch;
use KnowKit\PgVector\Vector\PdoVectorWriter;
use KnowKit\PgVector\Vector\SearchSql;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:vector')]
final class CompressedVectorTest extends TestCase
{
    public function testMrlAndStorageFormatValidationAndDigestSensitivity(): void
    {
        $base = new D\SpaceRef('openai', 'text-embedding-3-large', 'r1', 256, 'l2', 'v1', 'doc', 'query', 'float32', 1024);
        $equalMrl = new D\SpaceRef('openai', 'text-embedding-3-large', 'r1', 256, 'l2', 'v1', 'doc', 'query', 'float32', 256);
        $halfvec = new D\SpaceRef('openai', 'text-embedding-3-large', 'r1', 256, 'l2', 'v1', 'doc', 'query', 'halfvec', 1024);
        $binary = new D\SpaceRef('openai', 'text-embedding-3-large', 'r1', 256, 'l2', 'v1', 'doc', 'query', 'binary_quantized', 1024);

        self::assertNotSame($base->digest()->value, $equalMrl->digest()->value);
        self::assertNotSame($base->digest()->value, $halfvec->digest()->value);
        self::assertNotSame($halfvec->digest()->value, $binary->digest()->value);
        self::assertSame('halfvec', $halfvec->toWire()->get('storageFormat'));
        self::assertSame(1024, $halfvec->toWire()->get('mrlSourceDimensions'));

        $this->expectException(\InvalidArgumentException::class);
        new D\SpaceRef('openai', 'text-embedding-3-large', 'r1', 256, 'l2', 'v1', 'doc', 'query', 'float32', 128);
    }

    public function testBm25ProfileContractEnforcesIntegerJcsAndPrefilterCapability(): void
    {
        $contract = new Bm25ProfileContract();
        self::assertSame('pg_search-bm25-v1', $contract->profileId);
        $wire = $contract->toWire();
        self::assertSame(1200, $wire->get('k1Milli'));
        self::assertSame(750, $wire->get('bMilli'));
        self::assertSame('lexical.prefilter', $wire->get('requiredCapability'));
        self::assertIsInt($wire->get('k1Milli'));
        self::assertIsInt($wire->get('bMilli'));

        Bm25ProfileContract::assertCapabilities([D\Capability::LexicalPrefilter]);
        try {
            Bm25ProfileContract::assertCapabilities([D\Capability::VectorPrefilter]);
            self::fail('Expected prefilter_unsupported');
        } catch (D\DomainFailure $e) {
            self::assertSame('prefilter_unsupported', $e->errorCode);
        }
    }

    public function testHalfvecAndBinaryQuantizedWriteAndSearchExcludeDeniedRows(): void
    {
        foreach (['halfvec', 'binary_quantized'] as $format) {
            Fixture::run(function (Fixture $f) use ($format): void {
                $db = $f->lexical->catalog->db;
                $space = Fixture::space(['storageFormat' => $format]);
                $baseManifest = $f->candidates['1:501']->manifest;
                $genId = new D\GenerationId(Fixture::id(503));
                $manifest503 = new D\IndexManifest(
                    $baseManifest->toWire()
                        ->with('generationId', $genId->value)
                        ->with('embeddingSpaceDigest', $space->digest()->value),
                    \KnowKit\Tests\Contract\Ports\PortFixture::validator(),
                );
                $view503 = $f->backend($space)->view($manifest503);
                $db->execute("INSERT INTO index_generations (tenant_id,id,kb_id,profile_id,state,manifest_json,manifest_digest,embedding_space_digest)
                    VALUES (?,?,?,?,'building',?,?,?)",
                    [Fixture::id(1), $genId->value, Fixture::id(100), 'pgvector-l2-v1', $manifest503->canonical(), $view503->candidate->digest->value, $space->digest()->value]);
                $writer = new PdoVectorWriter(
                    $db,
                    $f->lexical->catalog->tx,
                    $f->backend($space),
                    new DocumentVectors($space, $space->documentRole, $this->vectors($f, $space), $this->refs($f)),
                    $f->lexical->catalog->ids,
                );
                $tx = $f->lexical->catalog->tx;
                $tx->run(function () use ($tx, $writer, $view503, $f, $space): void {
                    $tx->lockTenant($view503->tenant, D\LockMode::Update);
                    foreach (['allowed', 'tie', 'denied'] as $name) {
                        $writer->index($view503, $f->lexical->sets[$name]->chunkSet, $space);
                    }
                });

                $table = $format === 'halfvec' ? 'vector_documents_halfvec' : 'vector_documents_binary';
                self::assertSame(3, (int) $db->execute("SELECT count(*) FROM $table WHERE tenant_id=? AND generation_id=?",
                    [Fixture::id(1), $genId->value])->fetchColumn());

                $snapId = new D\SnapshotId(Fixture::id(512));
                $db->execute("UPDATE index_generations SET state='active' WHERE tenant_id=? AND id=?", [Fixture::id(1), $genId->value]);
                $db->execute("INSERT INTO publication_snapshots (tenant_id,id,kb_id,manifest_digest,created_at) VALUES (?,?,?,?,clock_timestamp())",
                    [Fixture::id(1), $snapId->value, Fixture::id(100), str_repeat('b', 64)]);
                $db->execute("INSERT INTO snapshot_generations VALUES (?,?,?,?,?)",
                    [Fixture::id(1), $snapId->value, Fixture::id(100), $genId->value, 'pgvector-l2-v1']);
                foreach (['allowed', 'tie', 'denied'] as $name) {
                    $r = $f->lexical->sets[$name]->chunkSet->revisionRef;
                    $db->execute("INSERT INTO snapshot_memberships VALUES (?,?,?,?,?,?,?,?,?,?)",
                        [Fixture::id(1), $f->lexical->catalog->ids->next()->value, $snapId->value, Fixture::id(100),
                            $r->sourceRef->sourceId->value, $r->sourceRef->sourceVersionId->value, $r->documentRevisionId->value,
                            $f->lexical->sets[$name]->chunkSet->chunkSetId->value, $genId->value, 'pgvector-l2-v1']);
                }
                $db->execute("UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?", [Fixture::id(1), $snapId->value]);

                $eligible = array_map(
                    static fn (string $name): D\SourceVersionId => $f->lexical->sets[$name]->chunkSet->revisionRef->sourceRef->sourceVersionId,
                    ['allowed', 'tie'],
                );
                $catScope = $f->lexical->catalog->scope(1);
                $grant = new D\QueryGrant(new \KnowKit\Domain\Contract\JsonObject([
                    'allowedCollectionIds' => [],
                    'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $eligible),
                    'grantEpoch' => $catScope->policyEpoch->value,
                    'policyBindingDigest' => str_repeat('c', 64),
                    'expiresAt' => '2026-11-06T12:00:30Z',
                ]), \KnowKit\Tests\Contract\Ports\PortFixture::validator());
                $scope = D\AuthorizedSearchScope::materialize(
                    $catScope,
                    $grant,
                    new D\SnapshotRef($catScope->tenantId, $catScope->kbId, $snapId, new D\Fingerprint(str_repeat('b', 64))),
                    $genId,
                    $eligible,
                    new D\UtcInstant('2026-11-06T12:00:00Z'),
                    $space->digest(),
                );
                $queryVec = new VectorPayload(new D\Vector([0.0, 0.0, 1.0], $space));
                $search = new PdoVectorSearch($db, new SearchPolicy('authorized-hnsw', 40, 'exact', 'sql-prefilter', 'strict_order', 20000, 4));
                $result = $f->lexical->catalog->tx->run(fn () => $search->search($scope, new D\Query('q'), 2, $view503, $queryVec, $format));

                self::assertSame(2, $result->permittedCount);
                self::assertCount(2, $result->candidates->candidates);
                $returnedIds = array_map(static fn (D\Candidate $c): string => $c->ref->chunkId->value, $result->candidates->candidates);
                $deniedId = $f->lexical->sets['denied']->chunkSet->chunks[0]->ref->chunkId->value;
                self::assertNotContains($deniedId, $returnedIds);
            });
        }
    }

    /** @return array<string, D\Vector> */
    private function vectors(Fixture $f, D\SpaceRef $space): array
    {
        $out = [];
        $coords = ['allowed' => [0.1, 0.2, 0.9], 'tie' => [0.2, 0.1, 0.8], 'denied' => [0.0, 0.0, 1.0]];
        foreach ($coords as $name => $vec) {
            $chunk = $f->lexical->sets[$name]->chunkSet->chunks[0];
            $out[$chunk->ref->chunkId->value] = new D\Vector($vec, $space);
        }

        return $out;
    }

    /** @return array<string, D\ChunkRef> */
    private function refs(Fixture $f): array
    {
        $out = [];
        foreach (['allowed', 'tie', 'denied'] as $name) {
            $chunk = $f->lexical->sets[$name]->chunkSet->chunks[0];
            $out[$chunk->ref->chunkId->value] = $chunk->ref;
        }

        return $out;
    }
}
