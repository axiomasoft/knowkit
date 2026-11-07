<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Validation;

use KnowKit\Adapter\Postgres\Documents\{AssetStorage, DocumentStorage, PdoDocumentStore, StoredDocument, UnitStorage};
use KnowKit\Application\Validation\ValidationInput;
use KnowKit\Domain as D;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

/** Real PostgreSQL, restricted runtime role, disposable migrated schema; no backend skips. */
final class StorageFixture
{
    public PdoDocumentStore $store;
    private int $nextResource = 50000;
    private int $nextObject = 1000;

    public function __construct(public CatalogFixture $catalog, public ValidationInput $input)
    {
        $this->store = new PdoDocumentStore($catalog->db, $catalog->tx, $catalog->outbox, $catalog->events->create(...));
    }

    public function resource(string $bytes): D\ResourceId
    {
        $id = new D\ResourceId(ValidationFixture::id($this->nextResource++));
        $ref = $this->input->source->ref;
        $this->catalog->db->execute('INSERT INTO ' . $this->catalog->db->table('resource_ledger') .
            '(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state)
             VALUES (?,?,?,\'owned\',\'test\',?,?,?,?,\'accepted\')',
            [$ref->tenantId->value, $id->value, $ref->sourceId->value, 'key-' . $id->value, 'object-' . $id->value, hash('sha256', $bytes), (string) strlen($bytes)]);
        return $id;
    }

    public function acceptSource(): void
    {
        $f = $this->catalog; $source = $this->input->source; $resource = $this->resource($source->bytes());
        $blob = new D\BlobRef($source->ref->tenantId, $source->ref->sourceId, new D\Sha256($source->ref->sourceDigest->value),
            new D\Uint64((string) $source->byteLength()), 'test', 'object-' . $resource->value, 'owned');
        $f->tx->run(function() use ($f, $source, $blob): void {
            $f->tx->lockTenant($source->ref->tenantId, D\LockMode::Update);
            $f->tx->lockSources([$source->ref->sourceId], D\LockMode::Update);
            $f->repo->insertSourceVersion($source->ref->tenantId, new D\SourceVersion($source->ref, $blob, $source->mediaType, 'license:synthetic', $f->clock->now()));
        });
    }

    public function storage(D\IrDocument $ir): DocumentStorage
    {
        $document = new D\DocumentId(ValidationFixture::id($this->nextObject++)); $pages = $segments = $blocks = $assets = [];
        $irResource = $this->resource($ir->canonical());
        foreach (['pages' => 'pageId', 'segments' => 'segmentId'] as $collection => $key) {
            foreach ($ir->toWire()->get($collection) as $unit) {
                ${$collection}[$unit->get($key)] = new UnitStorage(new D\Ulid(ValidationFixture::id($this->nextObject++)), $this->resource($unit->get('text')));
            }
        }
        $wire = $ir->toWire();
        foreach ($wire->get('blocks') as $block) { $blocks[$block->get('blockId')] = new D\Ulid(ValidationFixture::id($this->nextObject++)); }
        foreach ($wire->get('assets') as $asset) {
            $parent = null;
            foreach ($wire->get('blocks') as $block) {
                if (in_array($asset->get('assetId'), $block->get('assetRefs') ?? [], true)) { $parent = new D\LocalId($block->get('blockId')); break; }
            }
            $assets[$asset->get('assetId')] = new AssetStorage(new D\Ulid(ValidationFixture::id($this->nextObject++)),
                $this->resource($this->input->assets[$asset->get('assetId')]), 'source', $parent);
        }
        return new DocumentStorage($document, 'document', $irResource, $this->catalog->clock->now(), $pages, $segments, $blocks, $assets);
    }

    public function parents(array $additional = []): D\Parents
    {
        $ref = $this->input->source->ref;
        return new D\Parents($ref->tenantId, [new D\ResourceRef($ref->tenantId, 'source_version', $ref->sourceVersionId), ...$additional]);
    }

    public function write(D\IrDocument $ir, DocumentStorage $storage, ?D\Parents $parents = null, ?D\DocumentRevisionRef $revision = null): StoredDocument
    {
        $revision ??= $this->input->revision; $parents ??= $this->parents(); $f = $this->catalog;
        return $f->tx->run(function() use ($f, $ir, $storage, $parents, $revision): StoredDocument {
            $f->tx->lockTenant($revision->sourceRef->tenantId, D\LockMode::Update);
            $f->tx->lockSources([$revision->sourceRef->sourceId], D\LockMode::Update);
            return $this->store->save($revision->sourceRef->tenantId, $revision, $ir, $storage, $parents);
        });
    }

    public function read(D\DocumentRevisionRef $revision): ?StoredDocument
    {
        $f = $this->catalog;
        return $f->tx->run(function() use ($f, $revision): ?StoredDocument {
            $f->tx->lockTenant($revision->sourceRef->tenantId, D\LockMode::Share);
            return $this->store->getRefs($revision->sourceRef->tenantId, $revision);
        });
    }

    public static function run(callable $test, string $case = 'IR-STRUCTURED'): void
    {
        CatalogFixture::run(function(CatalogFixture $catalog) use ($test, $case): void {
            $catalog->tenant(); $catalog->source();
            $ir = ValidationFixture::ir($case); $fixture = new self($catalog, ValidationFixture::input($ir));
            $fixture->acceptSource(); $test($fixture, $ir);
        });
    }
}
