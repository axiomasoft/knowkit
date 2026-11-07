<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Validation;

use KnowKit\Adapter\Postgres\Documents\{DocumentStorage, UnitStorage};
use KnowKit\Application\Validation\{DefaultQualityPolicy, SourceReadiness};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, StrictJson};
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/StorageFixture.php';

#[Group('ingest:validation')]
final class StoreTest extends TestCase
{
    public function testExactStructuredBundleRoundTripsEveryRefAndRejectsSqlMutation(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $storage = $f->storage($ir); $revision = $f->input->revision; $outbox = $f->catalog->count('outbox');
            $f->write($ir, $storage); $f->write($ir, $storage);
            self::assertSame($outbox + 1, $f->catalog->count('outbox'));
            $stored = $f->read($revision); self::assertNotNull($stored);
            self::assertEquals($revision, $stored->revision);
            self::assertEquals($storage->pages, $stored->storage->pages); self::assertEquals($storage->segments, $stored->storage->segments);
            self::assertEquals($storage->blocks, $stored->storage->blocks); self::assertEquals($storage->assets, $stored->storage->assets);
            self::assertSame($f->parents()->digest()->value, $stored->parents->digest()->value);
            $db = $f->catalog->db; $t = $revision->sourceRef->tenantId->value;
            $rows = $db->execute('SELECT * FROM ' . $db->table('blocks') . ' WHERE tenant_id=? ORDER BY reading_order', [$t])->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $index => $row) {
                $block = $ir->toWire()->get('blocks')[$index];
                self::assertSame($block->get('blockId'), $row['local_block_id']);
                self::assertSame(Jcs::canonicalize($block->get('spans')), Jcs::canonicalize(StrictJson::decode($row['spans_json'])));
                self::assertSame(Jcs::canonicalize($block->get('provenance')), Jcs::canonicalize(StrictJson::decode($row['provenance_json'])));
                self::assertSame(Jcs::canonicalize($block->without('spans')->without('provenance')), Jcs::canonicalize(StrictJson::decode($row['structure_json'])));
                $parent = $block->get('parentId');
                self::assertSame($parent === null ? null : $storage->blocks[$parent]->value, $row['parent_block_id']);
            }
            $asset = $db->row('SELECT * FROM ' . $db->table('assets') . ' WHERE tenant_id=?', [$t]);
            self::assertSame(Jcs::canonicalize([$ir->toWire()->get('assets')[0]]), Jcs::canonicalize(StrictJson::decode($asset['source_locators_json'])));
            self::assertSame($storage->blocks['img1']->value, $asset['parent_block_id']);
            foreach (['document_revisions' => "ir_digest=repeat('f',64)", 'documents' => "logical_path='changed'", 'pages' => "printed_label='changed'",
                'segments' => "status='failed'", 'blocks' => 'reading_order=999', 'assets' => "origin='generated'"] as $table => $mutation) {
                CatalogFixture::sqlReject($db->pdo, 'UPDATE ' . $db->table($table) . ' SET ' . $mutation . ' WHERE tenant_id=?', [$t], ['23514']);
                self::assertTrue(true, $table);
            }
        });
    }

    public function testChangedBytesOrParentSetCannotOverwriteHistoricalRevision(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $storage = $f->storage($ir); $f->write($ir, $storage); $original = $f->read($f->input->revision);
            $pages = $ir->toWire()->get('pages'); $text = $pages[0]->get('text') . ' changed';
            $pages[0] = $pages[0]->with('text', $text)->with('textDigest', hash('sha256', $text));
            $changed = new D\IrDocument($ir->toWire()->with('pages', $pages), ValidationFixture::validator());
            $replacement = ValidationFixture::input($changed)->revision;
            $changedStorage = $f->storage($changed);
            $this->reject(fn() => $f->write($changed, $changedStorage, null, $replacement), 'immutable_conflict');
            self::assertEquals($original, $f->read($f->input->revision));
            $other = ValidationFixture::input($ir, 5)->revision;
            $f->write($ir, $f->storage($ir), null, $other);
            $parents = $f->parents([new D\ResourceRef($other->sourceRef->tenantId, 'document_revision', $other->documentRevisionId)]);
            $this->reject(fn() => $f->write($ir, $storage, $parents), 'immutable_conflict');
            self::assertSame($original->parents->digest()->value, $f->read($f->input->revision)->parents->digest()->value);
            self::assertSame($original->revision->irDigest->value, $f->read($f->input->revision)->revision->irDigest->value);
            $wrongRef = new D\DocumentRevisionRef($f->input->revision->sourceRef, $f->input->revision->documentRevisionId, new D\IrDigest(str_repeat('f', 64)), $f->input->revision->parserSignature);
            $this->reject(fn() => $f->read($wrongRef), 'immutable_conflict');
            self::assertSame(2, $f->catalog->count('document_revisions'));
        });
    }

    public function testMissingAndForeignParentsNeverMakeDerivativeVisible(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $tenant = $f->input->source->ref->tenantId; $storage = $f->storage($ir);
            $missing = new D\ResourceRef($tenant, 'document_revision', new D\DocumentRevisionId(ValidationFixture::id(99)));
            $this->reject(fn() => $f->write($ir, $storage, $f->parents([$missing])), 'required_parent_missing');
            $this->reject(fn() => $f->write($ir, $storage, new D\Parents($tenant, [])), 'required_parent_missing');
            self::assertSame(0, $f->catalog->count('document_revisions')); self::assertSame(0, $f->catalog->count('dependency_nodes'));
            self::assertNull($f->read($f->input->revision));
            $f->catalog->tenant(9); $f->catalog->source(2, 9);
            $foreignIr = new D\IrDocument($ir->toWire()->with('tenantId', ValidationFixture::id(9))->with('sourceVersionId', ValidationFixture::id(33)), ValidationFixture::validator());
            $foreignInput = ValidationFixture::input($foreignIr, 44); $foreign = new StorageFixture($f->catalog, $foreignInput);
            $foreign->acceptSource(); $foreign->write($foreignIr, $foreign->storage($foreignIr));
            $disguised = new D\ResourceRef($tenant, 'document_revision', $foreignInput->revision->documentRevisionId);
            $this->reject(fn() => $f->write($ir, $storage, $f->parents([$disguised])), 'required_parent_missing');
            $this->reject(fn() => new D\Parents($tenant, [new D\ResourceRef($foreignInput->source->ref->tenantId, 'document_revision', $foreignInput->revision->documentRevisionId)]), 'scope_mismatch', D\Outcome::Denied);
            self::assertNull($f->read($f->input->revision));
            self::assertSame(1, $f->catalog->count('document_revisions')); // Only the unrelated tenant's real parent.
            $f->catalog->tx->run(function() use ($f, $tenant, $foreignInput): void {
                $f->catalog->tx->lockTenant($tenant, D\LockMode::Share);
                $this->reject(fn() => $f->store->getRefs($tenant, $foreignInput->revision), 'scope_mismatch', D\Outcome::Denied);
            });
        });
    }

    public function testResourceInventoryAndParentBindingAreAuthoritative(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $s = $f->storage($ir);
            $missing = new DocumentStorage($s->documentId, $s->logicalPath, $s->irResourceId, $s->createdAt, [], $s->segments, $s->blocks, $s->assets);
            $this->reject(fn() => $f->write($ir, $missing), 'storage_inventory_mismatch');
            $pages = $s->pages;
            $pages['p1'] = new UnitStorage($s->pages['p1']->id, $f->resource('changed nonempty page bytes'));
            $wrong = new DocumentStorage($s->documentId, $s->logicalPath, $s->irResourceId, $s->createdAt, $pages, $s->segments, $s->blocks, $s->assets);
            $this->reject(fn() => $f->write($ir, $wrong), 'canonical_resource_required');
            $assets = $s->assets; $a = $assets['a1'];
            $assets['a1'] = new \KnowKit\Adapter\Postgres\Documents\AssetStorage($a->id, $a->resourceId, $a->origin, new D\LocalId('h1'));
            $wrong = new DocumentStorage($s->documentId, $s->logicalPath, $s->irResourceId, $s->createdAt, $s->pages, $s->segments, $s->blocks, $assets);
            $this->reject(fn() => $f->write($ir, $wrong), 'asset_parent_mismatch');
            self::assertSame(0, $f->catalog->count('document_revisions')); self::assertSame(0, $f->catalog->count('documents'));
            $f->write($ir, $s);
            $ref = $f->input->revision; $t = $ref->sourceRef->tenantId->value; $db = $f->catalog->db;
            CatalogFixture::sqlReject($db->pdo, 'INSERT INTO ' . $db->table('dependency_edges') . '(tenant_id,child_id,parent_id,relation) VALUES (?,?,?,\'required\')',
                [$t, $ref->sourceRef->sourceVersionId->value, $ref->documentRevisionId->value], ['23514']);
            self::assertSame($f->parents()->digest()->value, $f->read($ref)->parents->digest()->value);
        });
    }

    public function testCurrentInvalidationAndVersionStateBlockDescendantsSynchronously(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $f->write($ir, $f->storage($ir)); $parent = $f->input->revision;
            $child = ValidationFixture::input($ir, 5)->revision;
            $parents = $f->parents([new D\ResourceRef($parent->sourceRef->tenantId, 'document_revision', $parent->documentRevisionId)]);
            $f->write($ir, $f->storage($ir), $parents, $child);
            self::assertNotNull($f->read($child));
            $f->catalog->tx->run(function() use ($f, $parent): void {
                $f->catalog->tx->lockTenant($parent->sourceRef->tenantId, D\LockMode::Update);
                $f->catalog->db->execute('UPDATE ' . $f->catalog->db->table('dependency_nodes') . ' SET invalidated_epoch=1 WHERE tenant_id=? AND id=?',
                    [$parent->sourceRef->tenantId->value, $parent->documentRevisionId->value]);
            });
            $this->reject(fn() => $f->read($child), 'ancestor_unusable', D\Outcome::Denied);
            $next = ValidationFixture::input($ir, 6)->revision;
            $this->reject(fn() => $f->write($ir, $f->storage($ir), $parents, $next), 'ancestor_unusable', D\Outcome::Denied);
            self::assertSame(2, $f->catalog->count('document_revisions'));
            $f->catalog->db->execute('UPDATE ' . $f->catalog->db->table('source_version_state') . ' SET usability=\'revoked\' WHERE tenant_id=? AND id=?',
                [$parent->sourceRef->tenantId->value, $parent->sourceRef->sourceVersionId->value]);
            $this->reject(fn() => $f->read($parent), 'ancestor_unusable', D\Outcome::Denied);
        });
    }

    public function testUint32CoordinatesAndLateParentOrderPersistWithoutNarrowing(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $f->write($ir, $f->storage($ir)); $db = $f->catalog->db; $tenant = $f->input->source->ref->tenantId->value;
            self::assertSame('4294967295', (string) $db->execute('SELECT physical_no FROM ' . $db->table('pages') . ' WHERE tenant_id=?', [$tenant])->fetchColumn());
            self::assertSame('4294967295', (string) $db->execute('SELECT reading_order FROM ' . $db->table('blocks') . ' WHERE tenant_id=?', [$tenant])->fetchColumn());
            self::assertNotNull($f->read($f->input->revision));
        }, 'IR-UINT32');
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $blocks = $ir->toWire()->get('blocks');
            $blocks[0] = $blocks[0]->with('parentId', 'fn1'); $blocks[7] = $blocks[7]->with('parentId', null);
            $late = new D\IrDocument($ir->toWire()->with('blocks', $blocks), ValidationFixture::validator());
            $revision = ValidationFixture::input($late)->revision; $storage = $f->storage($late);
            $f->write($late, $storage, null, $revision);
            $row = $f->catalog->db->row('SELECT parent_block_id,reading_order FROM ' . $f->catalog->db->table('blocks') . ' WHERE tenant_id=? AND id=?',
                [$revision->sourceRef->tenantId->value, $storage->blocks['h1']->value]);
            self::assertSame($storage->blocks['fn1']->value, $row['parent_block_id']); self::assertSame(0, (int) $row['reading_order']);
        });
    }

    public function testMissingScanRemainsPartialInPersistenceAndReadiness(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $ir): void {
            $f->write($ir, $f->storage($ir)); $db = $f->catalog->db;
            $row = $db->row('SELECT coverage_state,coverage_json FROM ' . $db->table('document_revisions') . ' WHERE tenant_id=?', [$f->input->source->ref->tenantId->value]);
            self::assertSame('partial', $row['coverage_state']);
            self::assertSame(Jcs::canonicalize($ir->toWire()->get('coverage')), Jcs::canonicalize(StrictJson::decode($row['coverage_json'])));
            $failed = $db->row('SELECT status,physical_no FROM ' . $db->table('pages') . ' WHERE tenant_id=? AND local_page_id=\'p10\'', [$f->input->source->ref->tenantId->value]);
            self::assertSame('failed', $failed['status']); self::assertSame(10, (int) $failed['physical_no']);
            $strict = new SourceReadiness($f->input->revision, $ir, new D\CoveragePolicy(), new DefaultQualityPolicy());
            $partial = new SourceReadiness($f->input->revision, $ir, new D\CoveragePolicy(true), new DefaultQualityPolicy());
            self::assertSame(D\Decision::Deny, $strict->quality->decision); $partial->requireQualityAllowed();
            self::assertFalse($partial->eligibleForCompleteQuery());
        }, 'IR-MISSING-SCAN');
    }

    private function reject(callable $operation, string $code, D\Outcome $outcome = D\Outcome::Incompatible): void
    {
        try { $operation(); self::fail('Forbidden operation accepted'); }
        catch (D\DomainFailure $error) { self::assertSame($code, $error->errorCode); self::assertSame($outcome, $error->outcome); }
    }

    public function testInMemoryIrByteCeilingIsCheckedBeforeAnyPersistence(): void
    {
        StorageFixture::run(function(StorageFixture $f, D\IrDocument $small): void {
            $storage = $f->storage($small); $huge = ValidationFixture::oversizedIr();
            $this->reject(fn() => $f->write($huge, $storage), 'document_too_large');
            self::assertSame(0, $f->catalog->count('document_revisions')); self::assertSame(0, $f->catalog->count('dependency_nodes'));
        }, 'IR-BLANK');
    }
}
