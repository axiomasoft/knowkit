<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Vector;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\IndexGenerations\ResourceInventory;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\PgVector\Domain\DocumentVectors;
use KnowKit\PgVector\Domain\ManifestView;
use KnowKit\PgVector\Domain\QueryVector;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Vector\PdoGenerationBackend;
use KnowKit\PgVector\Vector\PdoVectorIndex;
use KnowKit\PgVector\Vector\PdoVectorWriter;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Publication\Manifest\Fixture as Publication;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use KnowKit\Tests\Storage\Catalog\TestIds;

final class Fixture
{
    public int $queryCalls = 0;
    public ?\Closure $queryResponse = null;
    public array $candidates = [];

    public function __construct(public LexicalFixture $lexical) {}

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function corpus(): array
    {
        return json_decode(file_get_contents(self::root() . '/tests/fixtures/retrieval/vector/corpus.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function space(array $changes = []): D\SpaceRef
    {
        return new D\SpaceRef(...array_replace(self::corpus()['space'], $changes));
    }

    public static function vectorDatabase(callable $run): void
    {
        $old = getenv('TEST_PG_DSN');
        $dsn = getenv('TEST_VECTOR_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55433;dbname=knowkit_test';
        putenv('TEST_PG_DSN=' . $dsn);
        try {
            self::ensureVectorExtension($dsn);
            $run();
        } finally {
            putenv($old === false ? 'TEST_PG_DSN' : 'TEST_PG_DSN=' . $old);
        }
    }

    private static function ensureVectorExtension(string $dsn): void
    {
        static $ready = [];
        if (isset($ready[$dsn])) {
            return;
        }
        $pdo = new \PDO($dsn, getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public');
        $ready[$dsn] = true;
    }

    public static function migrate(Connection $db): void
    {
        $role = $db->execute('SELECT current_user')->fetchColumn();
        Connection::schema($role);
        $db->pdo->exec('RESET ROLE');
        try {
            $db->pdo->exec(file_get_contents(self::root() . '/packages/pgvector/migrations/002_vector_generations.sql'));
            $db->pdo->exec(file_get_contents(self::root() . '/packages/pgvector/migrations/003_vector_guard_search_path.sql'));
            $db->pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $db->schema . ' TO ' . $role);
        } finally {
            $db->pdo->exec('SET ROLE ' . $role);
        }
    }

    public static function run(callable $run, bool $seal = true): void
    {
        self::vectorDatabase(static function () use ($run, $seal): void {
            LexicalFixture::run(static function (LexicalFixture $lexical) use ($run, $seal): void {
                self::migrate($lexical->catalog->db);
                $f = new self($lexical);
                $f->seed($seal);
                $run($f);
            }, false);
        });
    }

    public static function publication(callable $run): void
    {
        self::vectorDatabase(static function () use ($run): void {
            Publication::run(static function (Publication $f) use ($run): void {
                self::migrate($f->jobs->catalog->db);
                $run($f);
            });
        });
    }

    private function seed(bool $seal): void
    {
        $db = $this->lexical->catalog->db;
        foreach ($this->lexical->sets as $set) {
            $s = $set->chunkSet->revisionRef->sourceRef;
            $db->execute("INSERT INTO source_version_state VALUES (?,?,'usable',NULL,NULL)",
                [$s->tenantId->value, $s->sourceVersionId->value]);
            $db->execute("INSERT INTO source_memberships (tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,'{}')",
                [$s->tenantId->value, $this->lexical->catalog->ids->next()->value, $s->sourceId->value, self::id(100)]);
        }
        $this->build(['allowed', 'tie', 'denied'], 1, 501, 510, $seal);
        $this->build(['other-snapshot'], 1, 502, 511, $seal);
        $this->build(['foreign'], 2, 501, 510, $seal);
    }

    private function build(array $names, int $tenant, int $generation, int $snapshot, bool $seal): void
    {
        $db = $this->lexical->catalog->db;
        $sets = array_map(fn (string $name): D\ChunkingResult => $this->lexical->sets[$name], $names);
        $inventory = new ResourceInventory($db);
        $scope = $this->lexical->catalog->scope($tenant);
        $candidate = (new Builder(PortFixture::validator()))->build($scope, new D\GenerationId(self::id($generation)), $sets,
            $inventory->resources($scope->tenantId, $sets), $inventory->coverage($scope->tenantId, $sets), ManifestView::PROFILE, self::space());
        $this->candidates[$tenant . ':' . $generation] = $candidate;
        $db->execute("INSERT INTO index_generations (tenant_id,id,kb_id,profile_id,state,manifest_json,manifest_digest,embedding_space_digest)
            VALUES (?,?,?,?,'building',?,?,?)", [$scope->tenantId->value, self::id($generation), $scope->kbId->value,
                ManifestView::PROFILE, $candidate->manifest->canonical(), $candidate->digest->value, self::space()->digest()->value]);
        $index = $this->index($tenant, $generation);
        $tx = $this->lexical->catalog->tx;
        $tx->run(static function () use ($tx, $scope, $sets, $generation, $index): void {
            $tx->lockTenant($scope->tenantId, D\LockMode::Update);
            foreach ($sets as $set) {
                $index->index(new D\GenerationId(self::id($generation)), $set->chunkSet, self::space());
            }
        });
        $this->backend()->reload($candidate->manifest);
        if (!$seal) {
            return;
        }
        $db->execute("UPDATE index_generations SET state='sealed' WHERE tenant_id=? AND id=?", [$scope->tenantId->value, self::id($generation)]);
        $db->execute('INSERT INTO publication_snapshots (tenant_id,id,kb_id,manifest_digest,created_at) VALUES (?,?,?,?,clock_timestamp())',
            [$scope->tenantId->value, self::id($snapshot), $scope->kbId->value, str_repeat('b', 64)]);
        $db->execute('INSERT INTO snapshot_generations VALUES (?,?,?,?,?)',
            [$scope->tenantId->value, self::id($snapshot), $scope->kbId->value, self::id($generation), ManifestView::PROFILE]);
        foreach ($sets as $set) {
            $r = $set->chunkSet->revisionRef;
            $db->execute('INSERT INTO snapshot_memberships VALUES (?,?,?,?,?,?,?,?,?,?)', [$scope->tenantId->value,
                $this->lexical->catalog->ids->next()->value, self::id($snapshot), $scope->kbId->value, $r->sourceRef->sourceId->value,
                $r->sourceRef->sourceVersionId->value, $r->documentRevisionId->value, $set->chunkSet->chunkSetId->value,
                self::id($generation), ManifestView::PROFILE]);
        }
        $db->execute('UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?', [$scope->tenantId->value, self::id($snapshot)]);
    }

    public function backend(?D\SpaceRef $space = null): PdoGenerationBackend
    {
        return new PdoGenerationBackend($this->lexical->catalog->db, new CanonicalChunkSets(array_values($this->lexical->sets)),
            PortFixture::validator(), $space ?? self::space());
    }

    public function index(int $tenant = 1, int $generation = 501, ?SearchPolicy $policy = null, ?DocumentVectors $documents = null): PdoVectorIndex
    {
        $candidate = $this->candidates[$tenant . ':' . $generation];
        $vectors = [];
        $references = [];
        foreach ($candidate->sets as $set) {
            foreach ($set->chunkSet->chunks as $chunk) {
                $name = array_search($set, $this->lexical->sets, true);
                $vectors[$chunk->ref->chunkId->value] = new D\Vector(self::corpus()['vectors'][$name], self::space());
                $references[$chunk->ref->chunkId->value] = $chunk->ref;
            }
        }
        $db = $this->lexical->catalog->db;
        $tx = $this->lexical->catalog->tx;
        $writer = new PdoVectorWriter($db, $tx, $this->backend(),
            $documents ?? new DocumentVectors(self::space(), self::space()->documentRole, $vectors, $references), $this->lexical->catalog->ids);

        return new PdoVectorIndex($db, $tx, PortFixture::validator(), $this->backend(), $writer,
            function (D\AuthorizedSearchScope $scope, D\Query $query, D\EmbeddingSpace $space): QueryVector {
                ++$this->queryCalls;
                if ($this->queryResponse !== null) {
                    return ($this->queryResponse)($scope, $query, $space);
                }

                return QueryVector::bind(new D\Vector(self::corpus()['query'], self::space()), $space->queryRole, $scope, $query);
            }, $policy ?? new SearchPolicy());
    }

    public function scope(array $names = ['allowed', 'tie'], int $tenant = 1, int $generation = 501, int $snapshot = 510): D\AuthorizedSearchScope
    {
        $scope = $this->lexical->catalog->scope($tenant);
        $eligible = array_map(fn (string $name): D\SourceVersionId => $this->lexical->sets[$name]->chunkSet->revisionRef->sourceRef->sourceVersionId, $names);
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $eligible),
            'grantEpoch' => $scope->policyEpoch->value, 'policyBindingDigest' => str_repeat('c', 64),
            'expiresAt' => '2026-11-06T12:00:30Z']), PortFixture::validator());

        return D\AuthorizedSearchScope::materialize($scope, $grant,
            new D\SnapshotRef($scope->tenantId, $scope->kbId, new D\SnapshotId(self::id($snapshot)), new D\Fingerprint(str_repeat('b', 64))),
            new D\GenerationId(self::id($generation)), $eligible, new D\UtcInstant('2026-11-06T12:00:01Z'), self::space()->digest());
    }

    public static function id(int $number): string
    {
        return PortFixture::id($number);
    }

    public function names(D\CandidateList $list): array
    {
        return $this->lexical->names($list);
    }
}
