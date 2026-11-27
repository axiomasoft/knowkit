<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Application\Retrieval\Fusion\HybridSearch;
use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Semantic\Hybrid\Fixture as Host;
use KnowKit\Tests\Semantic\Vector\Fixture as Vector;
use KnowKit\Tooling\Qa;

require_once Report::root() . '/tests/semantic/vector/bootstrap.php';
require_once Report::root() . '/tests/semantic/hybrid/bootstrap.php';

final class Hybrid
{
    public static function run(): array
    {
        $report = [];
        Vector::run(function (Vector $f) use (&$report): void {
            $host = new Host();
            $db = $f->lexical->catalog->db;
            self::prepareSnapshot($f);
            $documents = self::documents($f);
            $snapshot = new D\SnapshotRef($host->host->identity->scope->tenantId, $host->host->identity->scope->kbId,
                new D\SnapshotId(Vector::id(912)), new D\Fingerprint(str_repeat('b', 64)));
            $lexical = self::corpus(host: $host, f: $f, snapshot: $snapshot, documents: $documents, vector: false);
            $vector = self::corpus(host: $host, f: $f, snapshot: $snapshot, documents: $documents, vector: true);
            $service = new HybridSearch(lexical: $f->lexical->index, fusion: new ReciprocalRankFusion(), vector: $f->index());
            $profile = new D\SearchProfile(id: 'hybrid-v1', space: Vector::space());
            $query = $host->query(profile: $profile, text: 'gravity');
            $samples = [];
            $cpu = Metrics::cpuMs();
            for ($i = 0; $i < 20; ++$i) {
                $result = Metrics::timed(operation: fn () => $service->search(context: $host->host->context(), lexical: $lexical,
                    query: $query, profile: $profile, vector: $vector), samples: $samples);
                self::assertResult(result: $result, vector: $vector);
            }
            $report = Report::save('hybrid', ['latencyMs' => Metrics::percentiles($samples), 'cpuMs' => Metrics::cpuMs() - $cpu,
                'providerCalls' => 0, 'embedding' => 'Pinned synthetic document/query vectors in real pgvector; no remote model latency claim.',
                'sources' => 3, 'allowedSources' => 2, 'deniedReturned' => 0,
                'postgres' => $db->execute('SHOW server_version')->fetchColumn(),
                'vectorVersion' => $db->execute("SELECT extversion FROM pg_extension WHERE extname='vector'")->fetchColumn(),
                'referenceScaleQualified' => false]);
        });

        return $report;
    }

    /**
     * Benchmark float32, halfvec, and binary_quantized (2-stage bit + halfvec rescore)
     * across synthetic vectors with 1% permitted rows per tenant in real PG 17 + pgvector 0.8.
     *
     * @return array<string, mixed>
     */
    public static function benchmarkFormats(int $totalVectors = 100_000, int $dimensions = 128): array
    {
        $dsn = getenv('TEST_VECTOR_PG_DSN') ?: (getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55433;dbname=knowkit_test');
        $pdo = new \PDO(
            $dsn,
            getenv('TEST_PG_USER') ?: 'knowkit_test',
            getenv('TEST_PG_PASSWORD') ?: null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public');

        $permittedCount = max(1, (int) floor($totalVectors * 0.01));
        $k = min(20, $permittedCount);
        $oversampleFactor = (new SearchPolicy())->oversampleFactor;
        $suffix = bin2hex(random_bytes(6));
        $tables = [
            'float32' => 'kk_bench_f32_' . $suffix,
            'halfvec' => 'kk_bench_hv_' . $suffix,
            'binary_quantized' => 'kk_bench_bin_' . $suffix,
        ];
        $results = [];

        try {
            $pdo->exec("CREATE TEMP TABLE {$tables['float32']} (id integer PRIMARY KEY, permitted boolean NOT NULL, embedding public.vector($dimensions) NOT NULL)");
            $pdo->exec("CREATE TEMP TABLE {$tables['halfvec']} (id integer PRIMARY KEY, permitted boolean NOT NULL, embedding public.halfvec($dimensions) NOT NULL)");
            $pdo->exec("CREATE TEMP TABLE {$tables['binary_quantized']} (id integer PRIMARY KEY, permitted boolean NOT NULL, quantized bit($dimensions) NOT NULL, rescore_halfvec public.halfvec($dimensions) NOT NULL)");

            $batchSize = 1000;
            $pdo->beginTransaction();
            $stmtF32 = $pdo->prepare("INSERT INTO {$tables['float32']} (id, permitted, embedding) VALUES (?, ?, CAST(? AS public.vector))");
            $stmtHv = $pdo->prepare("INSERT INTO {$tables['halfvec']} (id, permitted, embedding) VALUES (?, ?, CAST(? AS public.halfvec))");
            $stmtBin = $pdo->prepare("INSERT INTO {$tables['binary_quantized']} (id, permitted, quantized, rescore_halfvec) VALUES (?, ?, CAST(? AS bit varying), CAST(? AS public.halfvec))");

            for ($i = 1; $i <= $totalVectors; ++$i) {
                $permitted = $i <= $permittedCount;
                $vec = self::syntheticUnitVector($i, $dimensions);
                $literal = json_encode($vec, JSON_THROW_ON_ERROR);
                $bits = '';
                foreach ($vec as $v) {
                    $bits .= $v > 0 ? '1' : '0';
                }
                $stmtF32->execute([$i, $permitted ? 'true' : 'false', $literal]);
                $stmtHv->execute([$i, $permitted ? 'true' : 'false', $literal]);
                $stmtBin->execute([$i, $permitted ? 'true' : 'false', $bits, $literal]);
                if ($i % $batchSize === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                }
            }
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            $pdo->exec("CREATE INDEX ON {$tables['float32']} USING hnsw (embedding public.vector_l2_ops) WITH (m=16,ef_construction=64)");
            $pdo->exec("CREATE INDEX ON {$tables['halfvec']} USING hnsw (embedding public.halfvec_cosine_ops) WITH (m=16,ef_construction=64)");
            $pdo->exec("CREATE INDEX ON {$tables['binary_quantized']} USING hnsw (quantized public.bit_hamming_ops) WITH (m=16,ef_construction=64)");
            $pdo->exec("ANALYZE {$tables['float32']}");
            $pdo->exec("ANALYZE {$tables['halfvec']}");
            $pdo->exec("ANALYZE {$tables['binary_quantized']}");

            $queryCount = 10;
            $queries = [];
            $groundTruth = [];
            $exactStmt = $pdo->prepare("SELECT id FROM {$tables['float32']} WHERE permitted = true ORDER BY embedding OPERATOR(public.<->) CAST(? AS public.vector), id ASC LIMIT ?");
            for ($q = 0; $q < $queryCount; ++$q) {
                $qVec = self::syntheticUnitVector(1_000_000 + $q, $dimensions);
                $qLiteral = json_encode($qVec, JSON_THROW_ON_ERROR);
                $qBits = '';
                foreach ($qVec as $v) {
                    $qBits .= $v > 0 ? '1' : '0';
                }
                $exactStmt->execute([$qLiteral, $k]);
                $groundTruth[$q] = array_map('intval', $exactStmt->fetchAll(\PDO::FETCH_COLUMN));
                $queries[$q] = ['literal' => $qLiteral, 'bits' => $qBits];
            }

            $pdo->exec("SET hnsw.ef_search = 64");
            $pdo->exec("SET hnsw.iterative_scan = 'strict_order'");

            foreach (['float32', 'halfvec', 'binary_quantized'] as $format) {
                $space = new D\SpaceRef('synthetic', 'bench-mrl', 'v1', $dimensions, 'l2', 'v1', 'doc', 'query', $format, $dimensions);
                $table = $tables[$format];
                $sizes = $pdo->query("SELECT pg_relation_size('$table') AS table_bytes, pg_indexes_size('$table') AS index_bytes, pg_total_relation_size('$table') AS total_bytes")->fetch(\PDO::FETCH_ASSOC);

                $samples = [];
                $recalls = [];
                $deniedReturned = 0;
                $explain = null;

                foreach ($queries as $qIdx => $qData) {
                    $rows = Metrics::timed(operation: function () use ($pdo, $format, $table, $qData, $k, $oversampleFactor): array {
                        if ($format === 'float32') {
                            $stmt = $pdo->prepare("SELECT id, permitted FROM $table WHERE permitted = true ORDER BY embedding OPERATOR(public.<->) CAST(? AS public.vector), id ASC LIMIT ?");
                            $stmt->execute([$qData['literal'], $k]);
                        } elseif ($format === 'halfvec') {
                            $stmt = $pdo->prepare("SELECT id, permitted FROM $table WHERE permitted = true ORDER BY embedding OPERATOR(public.<=>) CAST(? AS public.halfvec), id ASC LIMIT ?");
                            $stmt->execute([$qData['literal'], $k]);
                        } else {
                            $stmt = $pdo->prepare("WITH coarse AS (
                                SELECT id, permitted, rescore_halfvec FROM $table
                                WHERE permitted = true
                                ORDER BY quantized OPERATOR(public.<~>) CAST(? AS bit varying), id ASC
                                LIMIT LEAST(512, CAST(? AS integer) * CAST(? AS integer))
                            )
                            SELECT id, permitted FROM coarse
                            ORDER BY rescore_halfvec OPERATOR(public.<=>) CAST(? AS public.halfvec) ASC, id ASC
                            LIMIT CAST(? AS integer)");
                            $stmt->execute([$qData['bits'], $k, $oversampleFactor, $qData['literal'], $k]);
                        }

                        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    }, samples: $samples);

                    $ids = [];
                    foreach ($rows as $row) {
                        if (!$row['permitted']) {
                            ++$deniedReturned;
                        }
                        $ids[] = (int) $row['id'];
                    }
                    $expected = $groundTruth[$qIdx];
                    $recalls[] = $expected === [] ? 1.0 : count(array_intersect($expected, $ids)) / count($expected);

                    if ($qIdx === 0) {
                        $q0 = $queries[0];
                        if ($format === 'float32') {
                            $expStmt = $pdo->prepare("EXPLAIN (ANALYZE, FORMAT JSON) SELECT id FROM $table WHERE permitted = true ORDER BY embedding OPERATOR(public.<->) CAST(? AS public.vector), id ASC LIMIT ?");
                            $expStmt->execute([$q0['literal'], $k]);
                        } elseif ($format === 'halfvec') {
                            $expStmt = $pdo->prepare("EXPLAIN (ANALYZE, FORMAT JSON) SELECT id FROM $table WHERE permitted = true ORDER BY embedding OPERATOR(public.<=>) CAST(? AS public.halfvec), id ASC LIMIT ?");
                            $expStmt->execute([$q0['literal'], $k]);
                        } else {
                            $expStmt = $pdo->prepare("EXPLAIN (ANALYZE, FORMAT JSON) WITH coarse AS (
                                SELECT id, rescore_halfvec FROM $table
                                WHERE permitted = true
                                ORDER BY quantized OPERATOR(public.<~>) CAST(? AS bit varying), id ASC
                                LIMIT LEAST(512, CAST(? AS integer) * CAST(? AS integer))
                            )
                            SELECT id FROM coarse
                            ORDER BY rescore_halfvec OPERATOR(public.<=>) CAST(? AS public.halfvec) ASC, id ASC
                            LIMIT CAST(? AS integer)");
                            $expStmt->execute([$q0['bits'], $k, $oversampleFactor, $q0['literal'], $k]);
                        }
                        $explain = json_decode((string) $expStmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
                    }
                }

                Qa::ensure($deniedReturned === 0, "benchmark permission leak for $format");
                $results[$format] = [
                    'spaceDigest' => $space->digest()->value,
                    'totalVectors' => $totalVectors,
                    'permittedVectors' => $permittedCount,
                    'tableBytes' => (int) $sizes['table_bytes'],
                    'indexBytes' => (int) $sizes['index_bytes'],
                    'totalBytes' => (int) $sizes['total_bytes'],
                    'recallAt20' => array_sum($recalls) / count($recalls),
                    'p95Ms' => Metrics::percentiles($samples)['p95'],
                    'explainAnalyze' => $explain,
                    'deniedReturned' => $deniedReturned,
                ];
            }
        } finally {
            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS $table");
            }
        }

        return $results;
    }

    /** @return list<float> */
    private static function syntheticUnitVector(int $seed, int $dimensions): array
    {
        $values = [];
        $normSq = 0.0;
        $state = $seed * 2654435761;
        for ($d = 0; $d < $dimensions; ++$d) {
            $state = ($state * 1664525 + 1013904223) & 0x7FFFFFFF;
            $v = (($state / 0x7FFFFFFF) * 2.0) - 1.0;
            $values[] = $v;
            $normSq += $v * $v;
        }
        $invNorm = $normSq > 0.0 ? 1.0 / sqrt($normSq) : 1.0;
        foreach ($values as $d => $v) {
            $values[$d] = $v * $invNorm;
        }

        return $values;
    }

    private static function assertResult(D\Result $result, D\SearchCorpus $vector): void
    {
        if ($result->outcome !== D\Outcome::Success || !$result->data instanceof D\CandidateList) {
            throw new \RuntimeException('Real hybrid backend failed');
        }
        Qa::ensure(count($result->data->candidates) === 2, 'real hybrid backend failed');
        foreach ($result->data->candidates as $candidate) {
            Qa::ensure(in_array($candidate->ref->revisionRef->sourceRef->sourceVersionId->value,
                array_map(static fn ($id): string => $id->value, $vector->scope->allowedSourceVersionIds), true), 'hybrid permission leak');
            Qa::ensure($candidate->score > 0 && $candidate->score <= 2 / 61, 'RRF rank score invalid');
        }
    }

    private static function documents(Vector $f): array
    {
        $documents = [];
        foreach ($f->lexical->data as $doc) {
            $ir = new D\IrDocument(StrictJson::decode(json_encode($doc['ir'], JSON_THROW_ON_ERROR)), PortFixture::validator());
            $documents[hash('sha256', $ir->canonical())] = $ir;
        }

        return $documents;
    }

    private static function prepareSnapshot(Vector $f): void
    {
        $db = $f->lexical->catalog->db;
        $f->lexical->seal();
        $db->execute('INSERT INTO publication_snapshots VALUES (?,?,?,?,clock_timestamp(),false)',
            [Vector::id(1), Vector::id(912), Vector::id(100), str_repeat('b', 64)]);
        foreach ([14 => 'postgres-fts-simple-v1', 501 => 'pgvector-l2-v1'] as $id => $profileId) {
            $db->execute('INSERT INTO snapshot_generations VALUES (?,?,?,?,?)',
                [Vector::id(1), Vector::id(912), Vector::id(100), Vector::id($id), $profileId]);
        }
        foreach (['allowed', 'tie'] as $name) {
            $r = $f->lexical->sets[$name]->chunkSet->revisionRef;
            foreach ([14 => 'postgres-fts-simple-v1', 501 => 'pgvector-l2-v1'] as $id => $profileId) {
                $db->execute('INSERT INTO snapshot_memberships VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [Vector::id(1), $f->lexical->catalog->ids->next()->value, Vector::id(912), Vector::id(100),
                        $r->sourceRef->sourceId->value, $r->sourceRef->sourceVersionId->value, $r->documentRevisionId->value,
                        $f->lexical->sets[$name]->chunkSet->chunkSetId->value, Vector::id($id), $profileId]);
            }
        }
        $db->execute('UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?', [Vector::id(1), Vector::id(912)]);
    }

    private static function corpus(Host $host, Vector $f, D\SnapshotRef $snapshot, array $documents, bool $vector): D\SearchCorpus
    {
        $versions = array_map(fn (string $name): D\SourceVersionId => $f->lexical->sets[$name]->chunkSet->revisionRef->sourceRef->sourceVersionId,
            ['allowed', 'tie']);
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn ($id): string => $id->value, $versions),
            'grantEpoch' => '0', 'policyBindingDigest' => HashDomain::PolicyBinding->digest($host->host->binding),
            'expiresAt' => $host->host->now->plusSeconds(30)->value]), PortFixture::validator());
        $generation = new D\GenerationId(Vector::id($vector ? 501 : 14));
        $scope = D\AuthorizedSearchScope::materialize($host->host->identity->scope, $grant, $snapshot, $generation,
            $versions, $host->host->now, $vector ? Vector::space()->digest() : null);
        $ownSets = array_values(array_filter($f->lexical->sets,
            static fn (D\ChunkingResult $set): bool => $set->chunkSet->revisionRef->sourceRef->tenantId->value === Vector::id(1)));
        $manifest = $vector ? $f->candidates['1:501'] : new D\ManifestCandidate($f->lexical->manifests[1], $ownSets, []);

        return new D\SearchCorpus($scope, (new CanonicalUnits())->build($snapshot, $manifest, $documents));
    }
}
