<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Commands;

use KnowKit\Adapter\Postgres\Dependencies\{NodeMetadata, PdoDependencyStore};
use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Adapter\Postgres\PolicyEpoch\PdoPolicyEpoch;
use KnowKit\Application\Catalog\SourceCommands;
use KnowKit\Application\Lifecycle\{LifecycleCommands, LifecycleGuarantee};
use KnowKit\Domain as D;
use KnowKit\Domain\Access\OwnerFreshness;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__, 2).'/storage/catalog/bootstrap.php';

/** Synthetic immutable metadata and real PostgreSQL transactions for P3.2 lifecycle command qualification. */
final class Fixture
{
    private static ?array $expectations = null;
    public readonly D\TenantId $tenant;
    public readonly PdoDependencyStore $graph;
    public readonly PdoLifecycleStore $store;
    public readonly PdoPolicyEpoch $epochs;
    public readonly LifecycleCommands $lifecycle;
    public readonly SourceCommands $sources;

    public function __construct(public readonly CatalogFixture $catalog)
    {
        $this->tenant = new D\TenantId(self::id(1));
        $this->graph = new PdoDependencyStore($catalog->db, $catalog->tx, $this->tenant, $catalog->ids);
        $this->store = new PdoLifecycleStore(
            $catalog->db,
            $catalog->tx,
            $this->tenant,
            $catalog->clock,
            $catalog->ids,
            PortFixture::validator(),
            $catalog->events->create(...),
            $this->graph,
            $catalog->outbox,
            $catalog->authority->requireAllow(...),
        );
        $this->epochs = new PdoPolicyEpoch($catalog->db, $catalog->tx, $this->tenant);
        $this->sources = new SourceCommands(
            $catalog->repo->registerSourceMetadata(...),
            $catalog->repo->acceptSourceVersion(...),
            $catalog->tx,
            $catalog->authority,
        );
        $this->lifecycle = new LifecycleCommands(
            $this->store,
            $catalog->repo,
            $catalog->tx,
            $catalog->authority,
            $catalog->repo->acceptSourceVersion(...),
            new LifecycleGuarantee(new OwnerFreshness('owner-contract:v1', 5)),
        );
        $catalog->tenant();
        $catalog->kb();
        $catalog->source();
        $this->addMembership(2, 100, 'm-init');
        $this->seedVersionWithGraph(3, 2, 4, 5, 7, 8, 10, 11);
    }

    public static function id(int $n): string
    {
        return CatalogFixture::id($n);
    }

    public static function assertOutcome(string $id, array $actual): void
    {
        if (self::$expectations === null) {
            $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/v1/cases.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$expectations = array_column($fixture['cases'], 'expected', 'id');
        }
        Assert::assertSame(self::$expectations[$id], $actual, $id);
    }

    public static function run(callable $test): void
    {
        CatalogFixture::run(fn (CatalogFixture $f) => $test(new self($f)));
    }

    public function scope(int $kb = 100): D\Scope
    {
        $row = $this->catalog->db->row('SELECT kb_policy_epoch FROM '.$this->catalog->db->table('knowledge_bases').' WHERE tenant_id=? AND id=?',
            [$this->tenant->value, self::id($kb)]);
        return new D\Scope(
            $this->tenant,
            new D\KbId(self::id($kb)),
            new D\ActorId(self::id(101)),
            'catalog.manage',
            new D\Decimal((string)($row['kb_policy_epoch'] ?? '0')),
            new D\Sha256(str_repeat('a', 64)),
        );
    }

    public function atomic(callable $operation, D\LockMode $mode = D\LockMode::Update): mixed
    {
        return $this->catalog->tx->run(function () use ($operation, $mode) {
            $this->catalog->db->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->catalog->db->execute("SET LOCAL lock_timeout='500ms'");
            $this->catalog->db->execute("SET LOCAL statement_timeout='2s'");
            $this->catalog->tx->lockTenant($this->tenant, $mode);
            return $operation();
        });
    }

    public function addMembership(int $source, int $kb, string $key): void
    {
        $s = $this->scope($kb);
        $kbRow = $this->catalog->repo->getKb($this->tenant, $s->kbId);
        $this->catalog->commands->addMembership(
            $s,
            new D\AddMembership(new D\SourceId(self::id($source)), null, new D\CatalogMetadata()),
            $this->catalog->context($key, $kbRow?->revision->value ?? '0', $s->policyEpoch->value),
        );
    }

    public function secondKb(int $kbId = 102): D\KnowledgeBase
    {
        $s = $this->scope($kbId);
        return $this->catalog->commands->createKnowledgeBase(
            $s,
            new D\CreateKB($s->kbId, 'Second KB', 'owner:policy', new D\ProfileId('postgres-fts-simple-v1'), new D\CoveragePolicy()),
            $this->catalog->context('create-kb-'.$kbId, null, '0'),
        );
    }

    public function versionRef(int $versionId, int $sourceId = 2): D\SourceVersionRef
    {
        return new D\SourceVersionRef(
            $this->tenant,
            new D\SourceId(self::id($sourceId)),
            new D\SourceVersionId(self::id($versionId)),
            new D\SourceDigest(hash('sha256', 'version-'.$versionId)),
        );
    }

    public function reserveAcceptedBlob(int $versionId, int $sourceId = 2, string $owner = 'owned'): D\SourceVersion
    {
        $digest = hash('sha256', 'version-'.$versionId);
        $resId = 3000 + $versionId;
        $this->catalog->resource($resId, $sourceId, 1, $owner, $digest);
        $ref = $this->versionRef($versionId, $sourceId);
        return new D\SourceVersion(
            $ref,
            new D\BlobRef($this->tenant, $ref->sourceId, $ref->sourceDigest, new D\Uint64('15'), 'test', 'object-'.$resId, $owner),
            'text/plain',
            'rights:test',
            new D\UtcInstant('2026-11-06T12:00:00Z'),
        );
    }

    public function seedVersionWithGraph(
        int $versionId,
        int $sourceId,
        int $revisionId,
        int $docId,
        int $segId,
        int $blockId,
        int $chunkSetId,
        int $chunkId,
        string $coverageState = 'complete',
    ): D\SourceVersionRef {
        $db = $this->catalog->db;
        $t = $this->tenant->value;
        $version = $this->reserveAcceptedBlob($versionId, $sourceId);
        $this->atomic(fn () => $this->catalog->repo->insertSourceVersion($this->tenant, $version));
        $this->seedExtractedArtifacts($versionId, $sourceId, $revisionId, $docId, $segId, $blockId, $chunkSetId, $chunkId, $coverageState);
        $this->atomic(function () use ($sourceId, $versionId, $revisionId, $chunkId): void {
            $srcNode = $this->registerNode('source', $sourceId, []);
            $verNode = $this->registerNode('source_version', $versionId, [$srcNode->ref]);
            $revNode = $this->registerNode('document_revision', $revisionId, [$verNode->ref]);
            $this->registerNode('chunk', $chunkId, [$revNode->ref]);
        });
        return $version->ref;
    }

    public function seedExtractedArtifacts(
        int $versionId,
        int $sourceId,
        int $revisionId,
        int $docId,
        int $segId,
        int $blockId,
        int $chunkSetId,
        int $chunkId,
        string $coverageState = 'complete',
    ): void {
        $db = $this->catalog->db;
        $t = $this->tenant->value;
        $irDigest = hash('sha256', 'ir-'.$revisionId);
        $txtDigest = hash('sha256', 'txt-'.$chunkId);
        $this->catalog->resource(4000 + $revisionId, $sourceId, 1, 'owned', $irDigest);
        $this->catalog->resource(5000 + $chunkId, $sourceId, 1, 'owned', $txtDigest);
        $covJson = sprintf('{"state":"%s","counts":{"processed":1,"empty":0,"failed":0,"skipped":0},"missingFeatures":[],"diagnostics":[]}', $coverageState);
        $db->execute("INSERT INTO document_revisions VALUES (?,?,?,'test','1',?,'ir-1.0',?,?,?,?, '2026-11-06T12:00:00Z')", [
            $t,
            self::id($revisionId),
            self::id($versionId),
            str_repeat('a', 64),
            $irDigest,
            self::id(4000 + $revisionId),
            $coverageState,
            $covJson,
        ]);
        $db->execute("INSERT INTO documents VALUES (?,?,?,'document')", [$t, self::id($docId), self::id($revisionId)]);
        $db->execute('INSERT INTO chunk_sets VALUES (?,?,?,?,?)', [
            $t,
            self::id($chunkSetId),
            self::id($revisionId),
            '{"name":"test","version":"1","profileDigest":"'.str_repeat('a', 64).'"}',
            hash('sha256', 'cset-'.$chunkSetId),
        ]);
        $db->execute("INSERT INTO segments VALUES (?,?,?,'segment-1',?,?,'ok',0)", [
            $t,
            self::id($segId),
            self::id($docId),
            self::id(5000 + $chunkId),
            $txtDigest,
        ]);
        $db->execute("INSERT INTO blocks VALUES (?,?,?,?,'block-1',NULL,'paragraph','source','{\"kind\":\"verbatim\"}',0,'[]','{}')", [
            $t,
            self::id($blockId),
            self::id($revisionId),
            self::id($docId),
        ]);
        $spans = '[{"kind":"segment","segmentId":"segment-1","blockId":"block-1","byteStart":0,"byteEnd":4,"textDigest":"'.$txtDigest.'"}]';
        $db->execute("INSERT INTO chunks VALUES (?,?,?,?,?,?,'{}',15,'[]',?)", [
            $t,
            self::id($chunkId),
            self::id($chunkSetId),
            $txtDigest,
            self::id($docId),
            $spans,
            self::id(5000 + $chunkId),
        ]);
    }

    public function ref(string $kind, int $id): D\ResourceRef
    {
        $type = match ($kind) {
            'source' => D\SourceId::class,
            'source_version' => D\SourceVersionId::class,
            'document_revision' => D\DocumentRevisionId::class,
            'resource' => D\ResourceId::class,
            'chunk' => D\ChunkId::class,
        };
        return new D\ResourceRef($this->tenant, $kind, new $type(self::id($id)));
    }

    public function registerNode(string $kind, int $id, array $parents = []): D\Node
    {
        $ref = $this->ref($kind, $id);
        $m = (new NodeMetadata($this->catalog->db, $this->catalog->tx))->resolve($ref);
        $node = new D\Node($ref, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($m['identity'])));
        $this->graph->register($node, new D\Parents($this->tenant, $parents));
        return $node;
    }

    public function decision(D\ResourceRef ...$refs): D\Decision
    {
        return $this->graph->checkAncestors(new D\NodeRefs($this->tenant, $refs))->decision;
    }

    public function sealSnapshot(
        int $snapshotId,
        int $generationId,
        int $sourceId,
        int $versionId,
        int $revisionId,
        int $chunkSetId,
        int $chunkId,
        int $kbId = 100,
        array $additional = [],
    ): D\SnapshotId {
        $db = $this->catalog->db;
        $t = $this->tenant->value;
        $manifestDigest = hash('sha256', 'snap-manifest-'.$snapshotId);
        $db->execute("INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state,manifest_digest,manifest_json,chunk_count) VALUES (?,?,?,'postgres-fts-simple-v1','building',?,'{}',1)", [
            $t,
            self::id($generationId),
            self::id($kbId),
            hash('sha256', 'gen-'.$generationId),
        ]);
        $db->execute('INSERT INTO index_memberships VALUES (?,?,?,?,?,?,?,?)', [
            $t,
            self::id(6000 + $snapshotId),
            self::id($generationId),
            self::id($chunkId),
            hash('sha256', 'txt-'.$chunkId),
            self::id($versionId),
            self::id($revisionId),
            self::id($chunkSetId),
        ]);
        foreach ($additional as [$extraSource,$extraVersion,$extraRevision,$extraSet,$extraChunk]) {
            $db->execute('INSERT INTO index_memberships VALUES (?,?,?,?,?,?,?,?)', [
                $t,self::id(600000 + $snapshotId * 100 + $extraChunk),self::id($generationId),self::id($extraChunk),hash('sha256','txt-'.$extraChunk),
                self::id($extraVersion),self::id($extraRevision),self::id($extraSet),
            ]);
        }
        $db->execute("UPDATE index_generations SET chunk_count=?,state='sealed' WHERE tenant_id=? AND id=?", [1 + count($additional),$t,self::id($generationId)]);
        $db->execute("INSERT INTO publication_snapshots VALUES (?,?,?,?,'2026-11-06T12:00:00Z',false)", [
            $t,
            self::id($snapshotId),
            self::id($kbId),
            $manifestDigest,
        ]);
        $db->execute("INSERT INTO snapshot_generations VALUES (?,?,?,?,'postgres-fts-simple-v1')", [
            $t,
            self::id($snapshotId),
            self::id($kbId),
            self::id($generationId),
        ]);
        $db->execute("INSERT INTO snapshot_memberships VALUES (?,?,?,?,?,?,?,?,?,'postgres-fts-simple-v1')", [
            $t,
            self::id(7000 + $snapshotId),
            self::id($snapshotId),
            self::id($kbId),
            self::id($sourceId),
            self::id($versionId),
            self::id($revisionId),
            self::id($chunkSetId),
            self::id($generationId),
        ]);
        foreach ($additional as [$extraSource,$extraVersion,$extraRevision,$extraSet,$extraChunk]) {
            $db->execute("INSERT INTO snapshot_memberships VALUES (?,?,?,?,?,?,?,?,?,'postgres-fts-simple-v1')", [
                $t,self::id(700000 + $snapshotId * 100 + $extraChunk),self::id($snapshotId),self::id($kbId),self::id($extraSource),self::id($extraVersion),
                self::id($extraRevision),self::id($extraSet),self::id($generationId),
            ]);
        }
        $db->execute('UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?', [$t, self::id($snapshotId)]);
        return new D\SnapshotId(self::id($snapshotId));
    }

    public function activateSnapshot(int $snapshotId, int $kbId = 100): void
    {
        $this->atomic(function () use ($snapshotId, $kbId): void {
            $this->catalog->tx->lockKb(new D\KbId(self::id($kbId)), D\LockMode::Update);
            $kb = $this->catalog->repo->getKb($this->tenant, new D\KbId(self::id($kbId)));
            Assert::assertTrue($this->catalog->tx->casPointer(
                new D\KbId(self::id($kbId)),
                new D\SnapshotId(self::id($snapshotId)),
                $kb->pointerVersion,
            ));
        });
    }

    public function recordHistoricalUsage(int $usageId = 16, int $chunkId = 11, int $snapshotId = 110, int $generationId = 14): D\Usage
    {
        $db = $this->catalog->db;
        $t = $this->tenant->value;
        $selId = 8000 + $usageId;
        if ($db->row('SELECT id FROM publication_snapshots WHERE tenant_id=? AND id=?', [$t, self::id($snapshotId)]) === null) {
            $this->sealSnapshot($snapshotId, $generationId, 2, 3, 4, 10, $chunkId);
            $this->activateSnapshot($snapshotId);
        }
        $db->execute("INSERT INTO selections VALUES (?,?,?,?,'{}',?,NULL,?,?,?,'{}','postgres-fts-simple-v1',NULL,'[{}]','2026-11-06T12:05:00Z')", [
            $t,
            self::id($selId),
            hash('sha256', 'tok-'.$selId),
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            self::id($snapshotId),
            self::id($generationId),
        ]);
        $chunkIdentity = $this->atomic(fn () => (new NodeMetadata($db, $this->catalog->tx))->resolve($this->ref('chunk', $chunkId))['identity']);
        $wire = new JsonObject([
            'usageId' => self::id($usageId),
            'tenantId' => $t,
            'disclosureAttemptId' => self::id(1000 + $usageId),
            'selectionId' => self::id($selId),
            'actorId' => self::id(101),
            'purpose' => 'catalog.manage',
            'hostScopeHash' => str_repeat('a', 64),
            'runRef' => 'run-'.$usageId,
            'fragmentRefs' => [new JsonObject(['chunkRef' => $chunkIdentity, 'fragmentDigest' => hash('sha256', 'fragment-'.$usageId)])],
            'checkedAt' => '2026-11-06T12:00:00Z',
            'policyBindingDigest' => str_repeat('b', 64),
            'status' => 'prepared_disclosure',
        ]);
        $db->execute("INSERT INTO evidence_usages VALUES (?,?,?,?,?,'catalog.manage',?,?,?,?,'2026-11-06T12:00:00Z',NULL,?,NULL)", [
            $t,
            self::id($usageId),
            self::id(1000 + $usageId),
            self::id(100),
            self::id(101),
            'run-'.$usageId,
            self::id($selId),
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
        ]);
        $usage = new D\Usage($wire, PortFixture::validator());
        $this->atomic(fn () => $this->graph->recordUsage($usage));
        $spans = $db->row('SELECT spans_json FROM chunks WHERE tenant_id=? AND id=?', [$t,self::id($chunkId)])['spans_json'];
        $db->execute("INSERT INTO citations VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,'{}','present')", [
            $t, self::id(9000 + $usageId), self::id($usageId), str_repeat('a',64), self::id(2), self::id(3), self::id(4), self::id(5),
            self::id(10), self::id($chunkId), hash('sha256','txt-'.$chunkId), hash('sha256','version-3'), hash('sha256','ir-4'),
            $spans,
        ]);
        return $usage;
    }

    public static function failure(callable $operation, string $code, D\Outcome $outcome = D\Outcome::Incompatible): void
    {
        try {
            $operation();
            Assert::fail('Expected DomainFailure '.$code.' was not thrown');
        } catch (D\DomainFailure $e) {
            Assert::assertSame($code, $e->errorCode);
            Assert::assertSame($outcome, $e->outcome);
        }
    }
}
