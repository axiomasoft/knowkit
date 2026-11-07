<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Chunking;

use KnowKit\Adapter\Postgres\ChunkSets\PdoChunkSetStore;
use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, StrictJson};
use KnowKit\Port\ChunkSetBuilder;
use KnowKit\Tests\Ingest\Validation\{StorageFixture, ValidationFixture};
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/validation/StorageFixture.php';

#[Group('ingest:chunking')]
final class StoreTest extends TestCase
{
    public function testStoreUsesBuilderPortAndRejectsMismatchedReturnedBindings(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $storage = $f->storage($ir); $f->write($ir, $storage);
            $revision = $f->input->revision; $tenant = $revision->sourceRef->tenantId;
            $profile = ChunkingFixture::profile();
            $structural = new StructuralChunker($revision, $storage->documentId);
            self::assertInstanceOf(ChunkSetBuilder::class, $structural);
            self::assertSame($revision, $structural->revision()); self::assertSame($storage->documentId, $structural->documentId());
            $candidate = $structural->build($ir, $profile);
            self::assertInstanceOf(\KnowKit\Application\Chunking\ChunkingResult::class, $candidate);
            $resources = [];
            foreach ($candidate->chunkSet->chunks as $chunk) { $resources[$chunk->ref->chunkId->value] = $f->resource($chunk->text()); }
            $store = new PdoChunkSetStore($f->catalog->db, $f->catalog->tx, $f->catalog->repo);
            // A different trusted builder can return the shared Domain value without any Application type.
            $save = function(D\ChunkingResult $result) use ($f, $store, $tenant, $revision, $storage, $ir, $profile, $resources): D\ChunkingResult {
                $builder = new class($revision, $storage->documentId, $result) implements ChunkSetBuilder {
                    public function __construct(private D\DocumentRevisionRef $revision, private D\DocumentId $documentId, private D\ChunkingResult $result) {}
                    public function revision(): D\DocumentRevisionRef { return $this->revision; }
                    public function documentId(): D\DocumentId { return $this->documentId; }
                    public function build(D\IrDocument $document, D\ChunkerProfile $profile): D\ChunkingResult { return $this->result; }
                    public function chunk(D\IrDocument $document, D\ChunkerProfile $profile): D\ChunkSet { return $this->build($document, $profile)->chunkSet; }
                };
                return $f->catalog->tx->run(function() use ($f, $store, $tenant, $revision, $builder, $ir, $profile, $resources): D\ChunkingResult {
                    $f->catalog->tx->lockTenant($tenant, D\LockMode::Update);
                    $f->catalog->tx->lockSources([$revision->sourceRef->sourceId], D\LockMode::Update);
                    return $store->save($tenant, $builder, $ir, $profile, $resources);
                });
            };
            $set = $candidate->chunkSet;
            $otherRevision = new D\DocumentRevisionRef($revision->sourceRef, $revision->documentRevisionId, new D\IrDigest(str_repeat('f', 64)), $revision->parserSignature);
            $wrongRevision = new D\ChunkSet($otherRevision, $set->chunkSetId, $set->chunkerSignature, []);
            $wrongProfile = new D\ChunkSet($revision, $set->chunkSetId, ChunkingFixture::profile(['maxBytes' => 1199])->signature, $set->chunks);
            $wrongChunks = array_map(static fn(D\Chunk $c): D\Chunk => new D\Chunk($c->ref, new D\DocumentId(ValidationFixture::id(999)), $c->spans, $c->text()), $set->chunks);
            $wrongDocument = new D\ChunkSet($revision, $set->chunkSetId, $set->chunkerSignature, $wrongChunks);
            foreach ([$wrongRevision, $wrongProfile, $wrongDocument] as $bad) {
                ChunkingFixture::failure(fn() => $save(new D\ChunkingResult($bad, $candidate->manifest())), 'binding_mismatch');
                self::assertSame(0, $f->catalog->count('chunk_sets'));
            }
            $shared = new D\ChunkingResult($set, $candidate->manifest());
            self::assertSame($shared, $save($shared));
            self::assertSame($candidate->canonicalManifest(), $shared->canonicalManifest());
            self::assertEquals($candidate->manifestDigest, $shared->manifestDigest);
            self::assertSame(1, $f->catalog->count('chunk_sets'));
        });
    }

    public function testHistoricalChunksetsAndMetadataAreImmutableAndTenantQualified(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $storage = $f->storage($ir); $f->write($ir, $storage); $revision = $f->input->revision; $tenant = $revision->sourceRef->tenantId;
            $builder = new StructuralChunker($revision, $storage->documentId);
            $store = new PdoChunkSetStore($f->catalog->db, $f->catalog->tx, $f->catalog->repo);
            $save = function(D\ChunkerProfile $profile, ?array $override = null, ?StructuralChunker $other = null) use ($f, $ir, $tenant, $store, $builder): array {
                $candidate = ($other ?? $builder)->build($ir, $profile); $resources = [];
                foreach ($candidate->chunkSet->chunks as $chunk) { $resources[$chunk->ref->chunkId->value] = $f->resource($chunk->text()); }
                $saved = $f->catalog->tx->run(function() use ($f, $tenant, $other, $store, $builder, $profile, $ir, $override, $resources) {
                    $f->catalog->tx->lockTenant($tenant, D\LockMode::Update);
                    $f->catalog->tx->lockSources([$builder->revision->sourceRef->sourceId], D\LockMode::Update);
                    return $store->save($tenant, $other ?? $builder, $ir, $profile, $override ?? $resources);
                });
                return [$saved, $resources];
            };
            $profile = ChunkingFixture::profile(); [$first, $resources] = $save($profile);
            // Replay must use the same canonical resource refs, not allocate replacements.
            [$replay] = $save($profile, $resources); self::assertSame($first->canonicalManifest(), $replay->canonicalManifest());
            $db = $f->catalog->db; $setId = $first->chunkSet->chunkSetId->value;
            $historical = $db->execute('SELECT * FROM ' . $db->table('chunks') . ' WHERE tenant_id=? AND chunk_set_id=? ORDER BY id', [$tenant->value, $setId])->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(count($first->chunkSet->chunks), $historical);
            foreach ($historical as $row) {
                $entry = $first->metadata($row['id']); $provenance = StrictJson::decode($row['provenance_json']);
                self::assertSame($entry->get('origin'), $provenance->get('origin'));
                self::assertSame($entry->get('kind'), $provenance->get('kind'));
                self::assertSame(Jcs::canonicalize($entry->get('spans')), Jcs::canonicalize(StrictJson::decode($row['spans_json'])));
                self::assertSame($entry->get('structurePath'), StrictJson::decode($row['structure_path']));
                foreach ($provenance->get('context') as $context) { self::assertFalse($context->has('text')); self::assertFalse($context->get('isQuote')); }
            }
            [$second] = $save(ChunkingFixture::profile(['maxBytes' => 1199]));
            self::assertNotEquals($first->chunkSet->chunkSetId, $second->chunkSet->chunkSetId);
            self::assertSame(2, $f->catalog->count('chunk_sets'));
            self::assertSame($historical, $db->execute('SELECT * FROM ' . $db->table('chunks') . ' WHERE tenant_id=? AND chunk_set_id=? ORDER BY id', [$tenant->value, $setId])->fetchAll(\PDO::FETCH_ASSOC));
            $row = $db->row('SELECT manifest_digest FROM ' . $db->table('chunk_sets') . ' WHERE tenant_id=? AND id=?', [$tenant->value, $setId]);
            self::assertSame($first->manifestDigest->value, $row['manifest_digest']);
            CatalogFixture::sqlReject($db->pdo, 'UPDATE ' . $db->table('chunk_sets') . ' SET manifest_digest=? WHERE tenant_id=? AND id=?', [str_repeat('f', 64), $tenant->value, $setId], ['23514']);
            ChunkingFixture::failure(fn() => $save(ChunkingFixture::profile(['maxBytes' => 1000]), []), 'chunk_metadata_required');
            $wrong = new StructuralChunker($revision, new D\DocumentId(ValidationFixture::id(999)));
            ChunkingFixture::failure(fn() => $save($profile, null, $wrong), 'binding_mismatch');
            $foreign = new D\TenantId(ValidationFixture::id(9)); $f->catalog->tenant(9);
            $f->catalog->tx->run(function() use ($f, $store, $foreign, $builder, $ir, $profile, $resources): void {
                $f->catalog->tx->lockTenant($foreign, D\LockMode::Update);
                ChunkingFixture::failure(fn() => $store->save($foreign, $builder, $ir, $profile, $resources), 'scope_mismatch', D\Outcome::Denied);
            });
            // An accepted but wrong payload cannot satisfy the canonical resource boundary.
            $thirdProfile = ChunkingFixture::profile(['maxBytes' => 1198]);
            $third = $builder->build($ir, $thirdProfile); $bad = [];
            foreach ($third->chunkSet->chunks as $chunk) { $bad[$chunk->ref->chunkId->value] = $f->resource('forged'); }
            ChunkingFixture::failure(fn() => $save($thirdProfile, $bad), 'chunk_resource_required');
            self::assertSame(2, $f->catalog->count('chunk_sets'));
        });
    }
}
