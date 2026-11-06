<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Catalog;

use KnowKit\Adapter\Postgres\Catalog\{Connection,Migrate};
use KnowKit\Application\Catalog\SourceCommands;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs,JsonObject};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('storage:catalog')]
final class BoundaryTest extends TestCase
{
    public function testGuardsRejectMissingTenantWrongTenantAndUnsortedSourceLocks(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->source(2); $f->source(3); $s=$f->scope();
            try { $f->tx->lockSources([],D\LockMode::Share); self::fail('Missing tenant guard'); } catch (\LogicException) {}
            try {
                $f->tx->run(function() use($f,$s) { $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->tx->lockSources([new D\SourceId(CatalogFixture::id(3)),new D\SourceId(CatalogFixture::id(2))],D\LockMode::Update); });
                self::fail('Unsorted guard accepted');
            } catch (\LogicException $e) { self::assertSame('Sorted unique lock order required',$e->getMessage()); }
            try { $f->repo->insertTenant($s->tenantId,new D\Tenant(new D\TenantId(CatalogFixture::id(9)))); self::fail('Foreign insert'); }
            catch (D\DomainFailure $e) { self::assertSame('scope_mismatch',$e->errorCode); }
            $f->db->pdo->exec('RESET ROLE');
            try { new Connection($f->db->pdo,$f->sandbox->schema); self::fail('Superuser app connection'); }
            catch (D\DomainFailure $e) { self::assertSame('privileged_database_role',$e->errorCode); }
        });
    }
    public function testSourceMetadataCommandsReplayExpectedStateAndImmutableHistory(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $s=$f->scope(); $commands=new SourceCommands($f->repo->registerSourceMetadata(...),$f->repo->acceptSourceVersion(...),$f->tx,$f->authority);
            $register=new D\RegisterSource(new D\SourceId(CatalogFixture::id(2)),'owner:source','host:upload');
            $source=$commands->register($s,$register,$f->context('source','0'));
            self::assertEquals($source,$commands->register($s,$register,$f->context('source','0')));
            self::assertSame(1,$f->count('sources')); self::assertSame(1,$f->count('outbox'));
            $f->resource(); $ref=new D\SourceVersionRef($s->tenantId,$source->sourceId,new D\SourceVersionId(CatalogFixture::id(3)),new D\SourceDigest(hash('sha256','synthetic bytes')));
            $version=new D\SourceVersion($ref,new D\BlobRef($s->tenantId,$source->sourceId,$ref->sourceDigest,new D\Uint64('15'),'test','object-300','owned'),'text/plain','owner:rights',$f->clock->now());
            $v1=$commands->acceptVersion($s,$version,$f->context('version','0'));
            // Wall clock/request ID changes do not change a semantic command's accepted result.
            $retry=new D\SourceVersion($ref,$version->blob,$version->mime,$version->rightsRef,new D\UtcInstant('2026-11-07T00:00:00Z'));
            self::assertEquals($v1,$commands->acceptVersion($s,$retry,$f->context('version','0')));
            try { $commands->acceptVersion($s,$version,$f->context('other-version-key','0')); self::fail('Stale source revision accepted'); }
            catch (D\DomainFailure $e) { self::assertSame('stale_write',$e->errorCode); }
            $ref2=new D\SourceVersionRef($s->tenantId,$source->sourceId,new D\SourceVersionId(CatalogFixture::id(5)),$ref->sourceDigest);
            $v2=new D\SourceVersion($ref2,$version->blob,$version->mime,$version->rightsRef,$f->clock->now());
            $commands->acceptVersion($s,$v2,$f->context('version-2','1'));
            self::assertEquals($v1->ref,$f->repo->getVersion($s->tenantId,$ref)->ref);
            $f->db->execute("UPDATE source_version_state SET usability='corrected',corrected_by_version_id=? WHERE tenant_id=? AND id=?",[$ref2->sourceVersionId->value,$s->tenantId->value,$ref->sourceVersionId->value]);
            self::assertSame('corrected',$f->db->execute('SELECT usability FROM source_version_state WHERE tenant_id=? AND id=?',[$s->tenantId->value,$ref->sourceVersionId->value])->fetchColumn());
            self::assertEquals($v1->ref,$f->repo->getVersion($s->tenantId,$ref)->ref);
            self::assertSame(3,$f->count('outbox')); self::assertSame(3,$f->count('idempotency_receipts'));
        });
    }
    public function testFixtureVersionProvenanceDigestsAndRequiredCaseMappingAreComplete(): void
    {
        $root=CatalogFixture::root(); $manifest=json_decode(file_get_contents($root.'/tests/fixtures/check-groups/storage-catalog.json'),true,512,JSON_THROW_ON_ERROR);
        (new \KnowKit\Tooling\Qa($root))->fixtures($manifest);
        $fixture=CatalogFixture::fixture(); self::assertSame('storage-catalog-fixtures:1',$fixture['schemaVersion']);
        $scope=new D\Scope(new D\TenantId(CatalogFixture::id(1)),new D\KbId(CatalogFixture::id(100)),new D\ActorId(CatalogFixture::id(101)),
            'catalog.manage',new D\Decimal('0'),new D\Sha256(str_repeat('a',64)));
        $context=new D\MutationContext(new JsonObject(['idempotencyKey'=>'golden','requestId'=>CatalogFixture::id(900),'expectedEpoch'=>'0']),\KnowKit\Tests\Contract\Ports\PortFixture::validator());
        $command=new D\CreateKB($scope->kbId,'Same local name','owner:policy',new D\ProfileId('postgres-fts-simple-v1'),new D\CoveragePolicy());
        self::assertSame($manifest['fixtures'][0]['expected']['createKbFingerprint'],$command->requestDigest($scope,$context)->value);
        self::assertCount(count(array_unique($manifest['requiredCaseIds'])),$manifest['requiredCaseIds']);
        foreach (['R02-A01','R02-A02','R35-A01','R35-A02','CAT-SAME-LOCAL-IDS','CAT-FOREIGN-MEMBERSHIP','CAT-CYCLE','CAT-REPLAY','CAT-PAYLOAD-CONFLICT','CAT-STALE-REVISION'] as $id) {
            self::assertContains($id,$manifest['requiredCaseIds']); self::assertNotEmpty($manifest['testCaseMap'][$id]);
        }
    }
    public function testPolicyEpochChangeAfterOwnerCheckCannotReuseReceipt(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $s=$f->scope();
            $f->authority->prepare($s,new D\ResourceRef($s->tenantId,'knowledge_base',$s->kbId),D\Operation::CollectionCreate);
            $calls=$f->owner->calls;
            $f->db->execute('UPDATE knowledge_bases SET kb_policy_epoch=1 WHERE tenant_id=? AND id=?',[$s->tenantId->value,$s->kbId->value]);
            try {
                $f->tx->run(function() use($f,$s) {
                    $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->tx->lockKb($s->kbId,D\LockMode::Update);
                    $f->repo->createCollection($s,new D\CreateCollection(new D\CollectionId(CatalogFixture::id(200)),null,new D\CatalogMetadata()),$f->context('changed','0'));
                }); self::fail('Stale receipt became an authority');
            } catch (D\DomainFailure $e) { self::assertSame('POLICY_CHANGED',$e->errorCode); self::assertSame(D\Outcome::Denied,$e->outcome); }
            self::assertSame($calls,$f->owner->calls); self::assertSame(0,$f->count('collections')); self::assertSame(1,$f->count('idempotency_receipts'));
            try { $f->db->row('SELECT * FROM missing_table'); self::fail('Backend failure became absence'); }
            catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Unavailable,$e->outcome); }
        });
    }
}
