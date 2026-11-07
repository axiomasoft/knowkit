<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Documents;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{Decimal, DocumentId, DocumentRevisionRef, DomainFailure, IrDocument, LocalId, Outcome, Parents, ResourceId, ResourceRef, TenantId, Ulid, UtcInstant};
use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};
use KnowKit\Port\Outbox;

/**
 * Immutable revision bundle. One schema-bound IR maps to one explicit logical document.
 * Caller owns the guarded short SQL transaction and must let failures roll it back.
 * Asset source_locators_json retains a singleton array of the original IR AssetRef;
 * local asset ID, original digest, page and bbox remain bound to the canonical IR.
 * Origin/parent/resource/ULID allocation is explicit adapter metadata, never guessed.
 * getRefs returns internal metadata only; disclosure still owns fresh access checks.
 */
final readonly class PdoDocumentStore
{
    private PdoRevisionLineage $lineage;

    /** @param \Closure(TenantId,string,Ulid,Decimal,string,JsonObject):\KnowKit\Domain\DomainEvent $createEvent Local event assembly only. */
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx, private Outbox $outbox, private \Closure $createEvent)
    {
        if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared transaction required'); }
        $this->lineage = new PdoRevisionLineage($db, $tx);
    }

    /** Preload/verify bytes before this call; this primitive neither grants publication nor performs blob I/O. */
    public function save(TenantId $tenant, DocumentRevisionRef $revision, IrDocument $document, DocumentStorage $storage, Parents $parents): StoredDocument
    {
        $this->tx->requireTenant($tenant, true);
        if ($revision->sourceRef->tenantId != $tenant || $parents->tenantId != $tenant) { self::fail('scope_mismatch', Outcome::Denied); }
        if (strlen($document->canonical()) > 67108864) { self::fail('document_too_large'); }
        $ir = $document->toWire();
        if ($ir->get('tenantId') !== $tenant->value) { self::fail('scope_mismatch', Outcome::Denied); }
        if ($ir->get('sourceVersionId') !== $revision->sourceRef->sourceVersionId->value
            || $ir->get('parserSignature') != $revision->parserSignature->toWire()
            || hash('sha256', $document->canonical()) !== $revision->irDigest->value) { self::fail('immutable_conflict'); }
        foreach (['pages' => 'pageId', 'segments' => 'segmentId', 'blocks' => 'blockId', 'assets' => 'assetId'] as $collection => $key) {
            $ids = array_map(static fn(JsonObject $v): string => $v->get($key), $ir->get($collection));
            $actual = array_map('strval', array_keys($storage->$collection));
            sort($ids, SORT_STRING); sort($actual, SORT_STRING);
            if ($ids !== $actual) { self::fail('storage_inventory_mismatch'); }
        }
        $this->lineage->register($revision, $parents);
        $this->resource($tenant, $revision, $storage->irResourceId, $revision->irDigest->value, (string) strlen($document->canonical()));
        foreach (['pages', 'segments'] as $collection) {
            $key = $collection === 'pages' ? 'pageId' : 'segmentId';
            foreach ($ir->get($collection) as $unit) {
                $this->resource($tenant, $revision, $storage->$collection[$unit->get($key)]->textResourceId,
                    $unit->get('textDigest'), (string) strlen($unit->get('text')));
            }
        }
        foreach ($ir->get('assets') as $asset) {
            $this->resource($tenant, $revision, $storage->assets[$asset->get('assetId')]->resourceId, $asset->get('digest'), $asset->get('byteLength'));
        }
        $coverage = $ir->get('coverage');
        $inserted = $this->insert('document_revisions', [
            'tenant_id' => $tenant->value, 'id' => $revision->documentRevisionId->value, 'source_version_id' => $revision->sourceRef->sourceVersionId->value,
            'parser_name' => $revision->parserSignature->name, 'parser_version' => $revision->parserSignature->version,
            'profile_digest' => $revision->parserSignature->profileDigest->value, 'ir_schema' => $ir->get('schemaVersion'), 'ir_digest' => $revision->irDigest->value,
            'ir_resource_id' => $storage->irResourceId->value, 'coverage_state' => $coverage->get('state'), 'coverage_json' => Jcs::canonicalize($coverage),
            'created_at' => $storage->createdAt->value]);
        $oldDocuments = $this->db->execute('SELECT id FROM ' . $this->db->table('documents') . ' WHERE tenant_id=? AND revision_id=?',
            [$tenant->value, $revision->documentRevisionId->value])->fetchAll(\PDO::FETCH_COLUMN);
        if ($oldDocuments !== [] && $oldDocuments !== [$storage->documentId->value]) { self::fail('immutable_conflict'); }
        $this->insert('documents', ['tenant_id' => $tenant->value, 'id' => $storage->documentId->value,
            'revision_id' => $revision->documentRevisionId->value, 'logical_path' => $storage->logicalPath]);
        foreach ($ir->get('pages') as $page) {
            $unit = $storage->pages[$page->get('pageId')];
            $this->insert('pages', ['tenant_id' => $tenant->value, 'id' => $unit->id->value, 'document_id' => $storage->documentId->value,
                'local_page_id' => $page->get('pageId'), 'physical_no' => $page->get('physicalNo'), 'printed_label' => $page->get('printedLabel'),
                'text_resource_id' => $unit->textResourceId->value, 'text_digest' => $page->get('textDigest'), 'status' => $page->get('status'),
                'width_millionths' => null, 'height_millionths' => null]);
        }
        foreach ($ir->get('segments') as $order => $segment) {
            $unit = $storage->segments[$segment->get('segmentId')];
            $this->insert('segments', ['tenant_id' => $tenant->value, 'id' => $unit->id->value, 'document_id' => $storage->documentId->value,
                'local_segment_id' => $segment->get('segmentId'), 'text_resource_id' => $unit->textResourceId->value,
                'text_digest' => $segment->get('textDigest'), 'status' => $segment->get('status'), 'logical_order' => $order]);
        }
        // SQL parent FKs are immediate. Insert parents first without rewriting reading_order.
        $blocks = $children = []; $queue = [];
        foreach ($ir->get('blocks') as $block) {
            $id = $block->get('blockId'); $blocks[$id] = $block;
            if ($block->get('parentId') === null) { $queue[] = $id; }
            else { $children[$block->get('parentId')][] = $id; }
        }
        for ($index = 0; $index < count($queue); $index++) {
            $id = $queue[$index]; $block = $blocks[$id]; $parent = $block->get('parentId');
            $this->insert('blocks', ['tenant_id' => $tenant->value, 'id' => $storage->blocks[$id]->value,
                'revision_id' => $revision->documentRevisionId->value, 'document_id' => $storage->documentId->value, 'local_block_id' => $id,
                'parent_block_id' => $parent === null ? null : $storage->blocks[$parent]->value, 'kind' => $block->get('kind'),
                'origin' => $block->get('origin'), 'provenance_json' => Jcs::canonicalize($block->get('provenance')),
                'reading_order' => $block->get('order'), 'spans_json' => Jcs::canonicalize($block->get('spans')),
                'structure_json' => Jcs::canonicalize($block->without('spans')->without('provenance'))]);
            foreach ($children[$id] ?? [] as $child) { $queue[] = $child; }
        }
        if (count($queue) !== count($blocks)) { self::fail('reference_cycle'); }
        foreach ($ir->get('assets') as $asset) {
            $meta = $storage->assets[$asset->get('assetId')]; $parent = $meta->parentBlockId?->value;
            if ($parent !== null && (!isset($blocks[$parent]) || !in_array($asset->get('assetId'), $blocks[$parent]->get('assetRefs') ?? [], true))) {
                self::fail('asset_parent_mismatch');
            }
            $this->insert('assets', ['tenant_id' => $tenant->value, 'id' => $meta->id->value, 'revision_id' => $revision->documentRevisionId->value,
                'resource_id' => $meta->resourceId->value, 'media_type' => $asset->get('mediaType'), 'source_locators_json' => Jcs::canonicalize([$asset]),
                'origin' => $meta->origin, 'parent_block_id' => $parent === null ? null : $storage->blocks[$parent]->value]);
        }
        if ($inserted) {
            $this->outbox->append(($this->createEvent)($tenant, 'document_revision', $revision->documentRevisionId, new Decimal('0'),
                'document_revision.accepted', $revision->toWire()->without('tenantId')));
        }
        return new StoredDocument($revision, $storage, $parents);
    }

    public function getRefs(TenantId $tenant, DocumentRevisionRef $revision): ?StoredDocument
    {
        $this->tx->requireTenant($tenant);
        if ($revision->sourceRef->tenantId != $tenant) { self::fail('scope_mismatch', Outcome::Denied); }
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('document_revisions') . ' WHERE tenant_id=? AND id=?',
            [$tenant->value, $revision->documentRevisionId->value]);
        if ($row === null) { return null; }
        if ($this->lineage->revisionWire($tenant, $revision->documentRevisionId->value) != $revision->toWire()) { self::fail('immutable_conflict'); }
        $this->lineage->assertUsable($tenant, new Parents($tenant, [new ResourceRef($tenant, 'document_revision', $revision->documentRevisionId)]));
        $docs = $this->db->execute('SELECT * FROM ' . $this->db->table('documents') . ' WHERE tenant_id=? AND revision_id=?',
            [$tenant->value, $revision->documentRevisionId->value])->fetchAll(\PDO::FETCH_ASSOC);
        if (count($docs) !== 1) { self::fail('document_inventory_mismatch'); }
        $doc = $docs[0]; $pages = $segments = $blocks = $assets = $localBlocks = [];
        foreach (['pages' => 'local_page_id', 'segments' => 'local_segment_id'] as $table => $key) {
            foreach ($this->db->execute('SELECT * FROM ' . $this->db->table($table) . ' WHERE tenant_id=? AND document_id=?',
                [$tenant->value, $doc['id']])->fetchAll(\PDO::FETCH_ASSOC) as $unit) {
                ${$table}[$unit[$key]] = new UnitStorage(new Ulid($unit['id']), new ResourceId($unit['text_resource_id']));
            }
        }
        foreach ($this->db->execute('SELECT id,local_block_id FROM ' . $this->db->table('blocks') . ' WHERE tenant_id=? AND revision_id=? AND document_id=?',
            [$tenant->value, $revision->documentRevisionId->value, $doc['id']])->fetchAll(\PDO::FETCH_ASSOC) as $block) {
            $blocks[$block['local_block_id']] = new Ulid($block['id']); $localBlocks[$block['id']] = $block['local_block_id'];
        }
        foreach ($this->db->execute('SELECT * FROM ' . $this->db->table('assets') . ' WHERE tenant_id=? AND revision_id=?',
            [$tenant->value, $revision->documentRevisionId->value])->fetchAll(\PDO::FETCH_ASSOC) as $asset) {
            $locators = StrictJson::decode($asset['source_locators_json']);
            if (!is_array($locators) || count($locators) !== 1 || !$locators[0] instanceof JsonObject || !is_string($locators[0]->get('assetId'))) {
                self::fail('asset_inventory_mismatch');
            }
            $parent = $asset['parent_block_id'];
            if ($parent !== null && !isset($localBlocks[$parent])) { self::fail('asset_parent_mismatch'); }
            $id = $locators[0]->get('assetId');
            if (isset($assets[$id])) { self::fail('asset_inventory_mismatch'); }
            $assets[$id] = new AssetStorage(new Ulid($asset['id']), new ResourceId($asset['resource_id']), $asset['origin'],
                $parent === null ? null : new LocalId($localBlocks[$parent]));
        }
        $instant = (new \DateTimeImmutable($row['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        return new StoredDocument($revision, new DocumentStorage(new DocumentId($doc['id']), $doc['logical_path'], new ResourceId($row['ir_resource_id']),
            new UtcInstant($instant), $pages, $segments, $blocks, $assets), $this->lineage->parents($tenant, $revision->documentRevisionId->value));
    }

    private function resource(TenantId $tenant, DocumentRevisionRef $revision, ResourceId $id, string $digest, string $length): void
    {
        if ($this->db->row('SELECT id FROM ' . $this->db->table('resource_ledger') .
            ' WHERE tenant_id=? AND source_id=? AND id=? AND digest=? AND byte_length=? AND state=\'accepted\'',
            [$tenant->value, $revision->sourceRef->sourceId->value, $id->value, $digest, $length]) === null) { self::fail('canonical_resource_required'); }
    }

    private function insert(string $table, array $values): bool
    {
        $columns = array_keys($values);
        $inserted = $this->db->execute('INSERT INTO ' . $this->db->table($table) . '(' . implode(',', $columns) . ') VALUES (' .
            implode(',', array_fill(0, count($columns), '?')) . ') ON CONFLICT DO NOTHING', array_values($values))->rowCount() === 1;
        if ($inserted) { return true; }
        $row = $this->db->row('SELECT * FROM ' . $this->db->table($table) . ' WHERE tenant_id=? AND id=?', [$values['tenant_id'], $values['id']]);
        if ($row === null) { self::fail('immutable_conflict'); }
        foreach ($values as $key => $value) {
            if (str_ends_with($key, '_json')) {
                if (Jcs::canonicalize(StrictJson::decode($row[$key], 67108864)) !== $value) { self::fail('immutable_conflict'); }
            } elseif ($key === 'created_at') {
                if (new \DateTimeImmutable($row[$key]) != new \DateTimeImmutable($value)) { self::fail('immutable_conflict'); }
            } elseif ($value === null ? $row[$key] !== null : (string) $row[$key] !== (string) $value) { self::fail('immutable_conflict'); }
        }
        return false;
    }

    private static function fail(string $code, Outcome $outcome = Outcome::Incompatible): never { throw new DomainFailure($outcome, $code); }
}
