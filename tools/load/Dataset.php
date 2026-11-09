<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoLexicalIndex;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use KnowKit\Tooling\Qa;

require_once Report::root() . '/tests/retrieval/lexical/bootstrap.php';

/** Deterministic bulk fixture loader, excluded from ingestion throughput measurements. */
final class Dataset
{
    public array $sets = [];
    private array $rows = [];
    private array $templates = [];
    private int $rowId = 200000000;
    private array $loadMs = [];
    public int $manifestBytes = 0;

    public function __construct(
        public readonly LexicalFixture $fixture,
    ) {}

    public static function id(int $number): string
    {
        return LexicalFixture::id($number);
    }

    public function load(): void
    {
        $db = $this->fixture->catalog->db;
        $this->prepare();
        $db->pdo->beginTransaction();
        try {
            for ($number = 0; $number < 10000; ++$number) {
                $this->document($number);
                if ($number % 100 === 99) {
                    $this->flush();
                }
                if ($number % 1000 === 999) {
                    $this->analyze();
                }
            }
            $db->pdo->commit();
        } catch (\Throwable $error) {
            $db->pdo->rollBack();
            throw $error;
        }
        $coverage = StrictJson::decode(json_encode($this->fixture->data['allowed']['ir']['coverage'], JSON_THROW_ON_ERROR));
        $candidate = (new Builder(PortFixture::validator()))->build(
            scope: $this->fixture->catalog->scope(), id: new D\GenerationId(self::id(900)),
            sets: $this->sets, resources: [], coverage: $coverage,
        );
        $this->manifestBytes = strlen($candidate->manifest->canonical());
        $db->execute("UPDATE index_generations SET state='sealed',manifest_json=?,manifest_digest=?,chunk_count=100000 WHERE tenant_id=? AND id=?",
            [$candidate->manifest->canonical(), $candidate->digest->value, self::id(1), self::id(900)]);
        $db->execute('UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?', [self::id(1), self::id(901)]);
        $this->analyze();
        $count = $db->execute('SELECT count(*) FROM lexical_documents WHERE tenant_id=? AND generation_id=?',
            [self::id(1), self::id(900)])->fetchColumn();
        Qa::ensure((int) $count === 100000 && count($this->sets) === 10000, 'reference corpus incomplete');
    }

    private function analyze(): void
    {
        $db = $this->fixture->catalog->db;
        $role = $db->execute('SELECT current_user')->fetchColumn();
        \KnowKit\Adapter\Postgres\Catalog\Connection::schema($role);
        $db->pdo->exec('RESET ROLE');
        try {
            foreach (['sources', 'resource_ledger', 'source_versions', 'document_revisions', 'documents', 'segments',
                'blocks', 'chunk_sets', 'chunks', 'index_memberships', 'snapshot_memberships', 'lexical_documents'] as $table) {
                $db->pdo->exec('ANALYZE ' . $db->table($table));
            }
        } finally {
            $db->pdo->exec('SET ROLE ' . $role);
        }
    }

    private function prepare(): void
    {
        $db = $this->fixture->catalog->db;
        foreach (['sources', 'resource_ledger', 'source_versions', 'document_revisions', 'documents',
            'segments', 'blocks', 'chunk_sets', 'chunks', 'index_memberships', 'lexical_documents'] as $table) {
            $this->templates[$table] = $db->row('SELECT * FROM ' . $db->table($table) . ' WHERE tenant_id=? ORDER BY id LIMIT 1', [self::id(1)]);
        }
        $db->execute("INSERT INTO index_generations (tenant_id,id,kb_id,profile_id,state) VALUES (?,?,?,?,'building')",
            [self::id(1), self::id(900), self::id(100), PdoLexicalIndex::PROFILE]);
        $db->execute("INSERT INTO publication_snapshots VALUES (?,?,?,?,clock_timestamp(),false)",
            [self::id(1), self::id(901), self::id(100), str_repeat('b', 64)]);
        $db->execute('INSERT INTO snapshot_generations VALUES (?,?,?,?,?)',
            [self::id(1), self::id(901), self::id(100), self::id(900), PdoLexicalIndex::PROFILE]);
    }

    private function identities(int $number, string $text): array
    {
        $base = $this->fixture->sets['allowed'];
        $old = $base->chunkSet->revisionRef;
        $ids = [$old->sourceRef->sourceId->value => self::id(10000000 + $number),
            $old->sourceRef->sourceVersionId->value => self::id(20000000 + $number),
            $old->documentRevisionId->value => self::id(30000000 + $number),
            $base->chunkSet->chunks[0]->documentId->value => self::id(40000000 + $number),
            $base->chunkSet->chunkSetId->value => self::id(50000000 + $number),
            self::id(7010) => self::id(80000000 + $number * 2),
            self::id(7011) => self::id(80000001 + $number * 2),
            'gravity.' => $text,
            $base->chunkSet->chunks[0]->ref->chunkDigest->value => hash('sha256', $text)];

        return $ids;
    }

    private function document(int $number): void
    {
        $old = $this->fixture->sets['allowed']->chunkSet->revisionRef;
        $text = $number % 2 === 0 ? 'gravity.' : 'тест';
        $ids = $this->identities(number: $number, text: $text);
        $ir = $this->fixture->data['allowed']['ir'];
        $ir = $this->replace($ir, $ids);
        $irDigest = hash('sha256', Jcs::canonicalize(StrictJson::decode(json_encode($ir, JSON_THROW_ON_ERROR))));
        $ids[$old->irDigest->value] = $irDigest;
        foreach (['sources', 'source_versions', 'document_revisions', 'documents', 'segments', 'blocks', 'chunk_sets'] as $table) {
            $row = $this->replace($this->templates[$table], $ids);
            if (in_array($table, ['segments', 'blocks'], true)) {
                $row['id'] = self::id(++$this->rowId);
            }
            if ($table === 'source_versions') {
                $row['idempotency_fingerprint'] = hash('sha256', 'capacity-v1:' . $number);
            }
            $this->rows[$table][] = $row;
        }
        $this->resources(number: $number, ids: $ids, ir: $ir);
        $set = $this->chunkSet(number: $number, ids: $ids, text: $text);
        $last = array_key_last($this->rows['chunk_sets']);
        $this->rows['chunk_sets'][$last]['manifest_digest'] = $set->manifestDigest->value;
        $this->sets[] = $set;
        $this->rows['source_memberships'][] = ['tenant_id' => self::id(1), 'id' => self::id(++$this->rowId),
            'source_id' => $set->chunkSet->revisionRef->sourceRef->sourceId->value, 'kb_id' => self::id(100), 'metadata' => '{}'];
        $this->rows['snapshot_memberships'][] = ['tenant_id' => self::id(1), 'id' => self::id(++$this->rowId),
            'snapshot_id' => self::id(901), 'kb_id' => self::id(100),
            'source_id' => $set->chunkSet->revisionRef->sourceRef->sourceId->value,
            'source_version_id' => $set->chunkSet->revisionRef->sourceRef->sourceVersionId->value,
            'revision_id' => $set->chunkSet->revisionRef->documentRevisionId->value,
            'chunk_set_id' => $set->chunkSet->chunkSetId->value, 'generation_id' => self::id(900), 'profile_id' => PdoLexicalIndex::PROFILE];
    }

    private function resources(int $number, array $ids, array $ir): void
    {
        foreach ([7010, 7011] as $resource) {
            $row = $this->templates['resource_ledger'];
            $row['id'] = self::id($resource);
            $row = $this->replace($row, $ids);
            $row['storage_key'] = 'capacity-v1-' . $number . '-' . $resource;
            if ($resource === 7011) {
                $canonical = Jcs::canonicalize(StrictJson::decode(json_encode($ir, JSON_THROW_ON_ERROR)));
                $row['digest'] = hash('sha256', $canonical);
                $row['byte_length'] = strlen($canonical);
            }
            $this->rows['resource_ledger'][] = $row;
        }
    }

    private function chunkSet(int $number, array $ids, string $text): D\ChunkingResult
    {
        $base = $this->fixture->sets['allowed'];
        $wire = $this->replace(json_decode($base->canonicalManifest(), true, 64, JSON_THROW_ON_ERROR), $ids);
        $r = $wire['revisionRef'];
        $source = new D\SourceVersionRef(new D\TenantId($r['tenantId']), new D\SourceId($r['sourceId']),
            new D\SourceVersionId($r['sourceVersionId']), new D\SourceDigest($r['sourceDigest']));
        $revision = new D\DocumentRevisionRef($source, new D\DocumentRevisionId($r['documentRevisionId']),
            new D\IrDigest($r['irDigest']), $base->chunkSet->revisionRef->parserSignature);
        $chunks = [];
        $entries = [];
        for ($offset = 0; $offset < 10; ++$offset) {
            $id = self::id(60000000 + $number * 10 + $offset);
            $entry = $wire['chunks'][0];
            $entry['chunkId'] = $id;
            $entry['ordinal'] = $offset;
            $entries[] = $entry;
            $chunk = $base->chunkSet->chunks[0];
            $digest = new D\Sha256(hash('sha256', $text));
            $span = new D\Span($chunk->spans[0]->locator, $digest);
            $chunks[] = new D\Chunk(new D\ChunkRef($revision, new D\ChunkSetId($wire['chunkSetId']), new D\ChunkId($id), $digest),
                new D\DocumentId($wire['documentId']), [$span], $text);
            $this->persistChunk(id: $id, number: $number, ids: $ids);
        }
        $wire['chunks'] = $entries;

        return new D\ChunkingResult(new D\ChunkSet($revision, new D\ChunkSetId($wire['chunkSetId']), $base->chunkSet->chunkerSignature, $chunks),
            StrictJson::decode(json_encode($wire, JSON_THROW_ON_ERROR)));
    }

    private function persistChunk(string $id, int $number, array $ids): void
    {
        foreach (['chunks', 'index_memberships', 'lexical_documents'] as $table) {
            $row = $this->replace($this->templates[$table], $ids);
            $row['id'] = $table === 'chunks' ? $id : self::id(++$this->rowId);
            if ($table !== 'chunks') {
                $row['chunk_id'] = $id;
                $row['generation_id'] = self::id(900);
            }
            if ($table === 'lexical_documents' && $number % 2 !== 0) {
                $row['simple_tsvector'] = "'тест':1";
            }
            $this->rows[$table][] = $row;
        }
    }

    private function replace(mixed $value, array $ids): mixed
    {
        if (is_string($value)) {
            if (isset($ids[$value])) {
                return $ids[$value];
            }
            if ($value !== '{}' && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                $decoded = json_decode($value);
                if (is_array($decoded) || $decoded instanceof \stdClass) {
                    return json_encode($this->replace($decoded, $ids), JSON_THROW_ON_ERROR);
                }
            }

            return $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->replace($item, $ids), $value);
        }
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->$key = $this->replace($item, $ids);
            }
        }

        return $value;
    }

    private function flush(): void
    {
        $order = ['sources', 'resource_ledger', 'source_versions', 'document_revisions', 'documents', 'segments', 'blocks',
            'chunk_sets', 'chunks', 'source_memberships', 'index_memberships', 'snapshot_memberships', 'lexical_documents'];
        $db = $this->fixture->catalog->db;
        foreach ($order as $table) {
            $started = hrtime(true);
            foreach (array_chunk($this->rows[$table], 200) as $batch) {
                $columns = array_keys($batch[0]);
                $values = [];
                foreach ($batch as $row) {
                    foreach ($row as $value) {
                        $values[] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                    }
                }
                $tuple = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                $sql = 'INSERT INTO ' . $db->table($table) . ' (' . implode(',', $columns) . ') VALUES '
                    . implode(',', array_fill(0, count($batch), $tuple));
                $db->execute($sql, $values);
            }
            $this->loadMs[$table] = ($this->loadMs[$table] ?? 0) + (hrtime(true) - $started) / 1e6;
        }
        $this->rows = [];
        file_put_contents(Report::directory() . '/fixture-load.json', json_encode([
            'schemaVersion' => 'fixture-load:1', 'loadedSources' => count($this->sets), 'tableMs' => $this->loadMs,
        ], JSON_THROW_ON_ERROR));
    }

    public function scope(array $sets): D\AuthorizedSearchScope
    {
        $f = $this->fixture;
        $scope = $f->catalog->scope();
        $eligible = array_map(static fn (D\ChunkingResult $set): D\SourceVersionId => $set->chunkSet->revisionRef->sourceRef->sourceVersionId, $sets);
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $eligible),
            'grantEpoch' => '0', 'policyBindingDigest' => str_repeat('c', 64), 'expiresAt' => '2026-11-07T00:00:30Z']), PortFixture::validator());

        return D\AuthorizedSearchScope::materialize(scope: $scope, grant: $grant,
            snapshot: new D\SnapshotRef($scope->tenantId, $scope->kbId, new D\SnapshotId(self::id(901)), new D\Fingerprint(str_repeat('b', 64))),
            generation: new D\GenerationId(self::id(900)), eligibleVersions: $eligible, now: new D\UtcInstant('2026-11-07T00:00:01Z'));
    }

    public function index(array $sets): PdoLexicalIndex
    {
        $c = $this->fixture->catalog;

        return new PdoLexicalIndex(db: $c->db, transaction: $c->tx, validator: PortFixture::validator(),
            canonical: new CanonicalChunkSets($sets), ids: $c->ids);
    }
}
