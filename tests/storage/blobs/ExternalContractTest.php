<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Blobs;
use KnowKit\Application\Resources\Upload;
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/ExternalStoreDouble.php';

#[Group('storage:blobs')]
final class ExternalContractTest extends TestCase
{
    public function testProviderTimeoutAfterUploadLeavesDurableUncertainWriterEvenWithoutDigest(): void
    {
        BlobFixture::run(function($f) {
            $provider=new ExternalStoreDouble($f,'unknown');
            $upload=new Upload($provider,$f->ledger->reserveWriter(...),$f->ledger->requireAccepted(...),$f->ledger->markUncertain(...));
            try { $upload->put($f->reservation(),new Bytes('abc')); self::fail('Provider timeout accepted'); }
            catch (D\DomainFailure $e) { self::assertSame('provider_timeout',$e->errorCode); self::assertSame(D\Outcome::Unavailable,$e->outcome); }
            self::assertTrue($provider->uploaded); self::assertSame('unknown',$f->row()['state']); self::assertNull($f->row()['digest']); self::assertNull($f->row()['byte_length']);
            self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
            try { $f->ledger->reconcileWriter($f->reservation(),static fn()=>false,static fn()=>true); self::fail('Unknown provider quiescence accepted'); }
            catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Unknown,$e->outcome); }
            try { $f->catalog->db->execute("UPDATE resource_ledger SET state='deleting',digest=repeat('a',64),byte_length=3"); self::fail('Uncertain writer GC allowed'); }
            catch (D\DomainFailure $e) { self::assertSame('storage_conflict',$e->errorCode); }
        });
    }
    public function testUnsupportedAndUnknownExternalDeletionReceiptsNeverClaimRemoval(): void
    {
        BlobFixture::run(function($f) {
            $ref=$f->put();
            foreach (['unsupported','unknown'] as $mode) {
                $provider=new ExternalStoreDouble($f,$mode);
                $receipt=$provider->delete($f->reservation()->resource,new D\AttemptId(BlobFixture::id(351)))->toWire();
                self::assertSame($mode,$receipt->get('result')); self::assertFalse($receipt->get('inFlight')); self::assertNull($receipt->get('providerReceipt'));
                self::assertSame(BlobFixture::id(350),$receipt->get('taskId')); self::assertSame(BlobFixture::id(300),$receipt->get('resourceId'));
                self::assertTrue($provider->exists($ref)); self::assertSame('abc',BlobFixture::read($provider->open($ref)));
            }
        });
    }
    public function testLocalDeleteRequiresExactAttemptAndReportsDeletedAbsentAndDurabilityUnknownHonestly(): void
    {
        BlobFixture::run(function($f) {
            $ref=$f->put(); $resource=$f->reservation()->resource; $attempt=new D\AttemptId(BlobFixture::id(351));
            try { $f->store->delete($resource,$attempt); self::fail('Unreserved delete accepted'); }
            catch (D\DomainFailure $e) { self::assertSame('deletion_not_reserved',$e->errorCode); }
            $inventory=json_encode([['resourceId'=>$resource->id->value,'digest'=>$ref->digest->value,'objectVersion'=>$ref->objectVersion]],JSON_THROW_ON_ERROR);
            $f->catalog->db->execute("INSERT INTO deletion_tasks VALUES (?,?,?,'pending',?::jsonb,0,?,NULL)",[BlobFixture::id(1),BlobFixture::id(350),BlobFixture::id(2),$inventory,str_repeat('a',64)]);
            $f->catalog->db->execute('INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight,storage_key,object_version,staging_id) VALUES (?,?,?,?,true,?,?,?)',
                [BlobFixture::id(1),$attempt->value,BlobFixture::id(350),$resource->id->value,$f->row()['storage_key'],$ref->objectVersion,$f->row()['staging_id']]);
            try { $f->store->delete($resource,$attempt); self::fail('Accepted resource was deleted'); }
            catch (D\DomainFailure $e) { self::assertSame('resource_in_use',$e->errorCode); }
            $f->catalog->db->execute("UPDATE resource_ledger SET ref_count=0,state='deleting'");
            $f->catalog->db->execute("UPDATE deletion_attempts SET object_version='forged'");
            try { $f->store->delete($resource,$attempt); self::fail('Mismatched exact coordinates deleted'); }
            catch (D\DomainFailure $e) { self::assertSame('deletion_not_reserved',$e->errorCode); }
            self::assertFileExists($f->path($ref));
            $f->catalog->db->execute('UPDATE deletion_attempts SET object_version=?',[$ref->objectVersion]);
            $receipt=$f->store->delete($resource,$attempt)->toWire(); self::assertSame('deleted',$receipt->get('result')); self::assertFileDoesNotExist($f->path($ref));
            self::assertSame('absent',$f->store->delete($resource,$attempt)->toWire()->get('result'));
            // P3.6 owns storing/aggregating this receipt. The adapter leaves metadata in deleting.
            self::assertSame('deleting',$f->row()['state']);
            $ref=$f->put(310,311); $resource=$f->reservation(310,311)->resource; $attempt=new D\AttemptId(BlobFixture::id(352));
            $inventory=json_encode([['resourceId'=>$resource->id->value,'digest'=>$ref->digest->value,'objectVersion'=>$ref->objectVersion]],JSON_THROW_ON_ERROR);
            $f->catalog->db->execute("INSERT INTO deletion_tasks VALUES (?,?,?,'pending',?::jsonb,0,?,NULL)",[BlobFixture::id(1),BlobFixture::id(360),BlobFixture::id(2),$inventory,str_repeat('b',64)]);
            $f->catalog->db->execute('INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight,storage_key,object_version,staging_id) VALUES (?,?,?,?,true,?,?,?)',
                [BlobFixture::id(1),$attempt->value,BlobFixture::id(360),$resource->id->value,$f->row(310)['storage_key'],$ref->objectVersion,$f->row(310)['staging_id']]);
            $f->catalog->db->execute("UPDATE resource_ledger SET ref_count=0,state='deleting' WHERE id=?",[$resource->id->value]);
            $store=$f->makeStore(static fn($h,string $kind): bool=>$kind!=='directory' && fsync($h));
            self::assertSame('unknown',$store->delete($resource,$attempt)->toWire()->get('result')); self::assertFileDoesNotExist($f->path($ref));
        });
    }
}
