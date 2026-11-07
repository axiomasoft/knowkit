<?php
declare(strict_types=1);
namespace KnowKit\Tests\Publication\Manifest;

use KnowKit\Application\Publication\Manifest\ProjectionReadiness;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Port\Outbox;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/Fixture.php';

#[Group('publication:manifest')]
final class PostgresTest extends TestCase
{
    private function previous(Fixture $f): void
    {
        $c = $f->candidate(500); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c); $store->seal($f->lease,$c); $f->snapshot(500,600,true);
    }
    public function testMissingObjectKeepsPreviousSnapshot(): void
    {
        Fixture::run(function(Fixture $f): void {
            $this->previous($f); $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c);
            unlink($f->jobs->directory.'/resource-'.Fixture::id(311));
            Fixture::failure(fn() => $store->seal($f->lease,$c),'resource_missing',D\Outcome::Unavailable);
            self::assertSame('failed',$f->state()); self::assertSame(Fixture::id(600),$f->pointer());
            self::assertSame('resource_missing',$f->jobs->catalog->db->execute('SELECT failure_code FROM generation_checks WHERE generation_id=?',[Fixture::id(501)])->fetchColumn());
        });
    }
    public function testCorruptPersistedManifestCannotSeal(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c);
            $f->jobs->catalog->db->execute("UPDATE index_generations SET manifest_json='{}' WHERE tenant_id=? AND id=?",[Fixture::id(1),Fixture::id(501)]);
            Fixture::failure(fn() => $store->seal($f->lease,$c),'manifest_mismatch',D\Outcome::Incompatible);
            self::assertSame('building',$f->state()); self::assertNull($f->pointer());
        });
    }
    public function testRequiredLexicalFailureAndUnreadableWriteNeverSeal(): void
    {
        foreach ([false,true] as $writeSucceeded) {
            Fixture::run(function(Fixture $f) use ($writeSucceeded): void {
                $this->previous($f); $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c);
                if ($writeSucceeded) {
                    $f->index($c); // A real write receipt is insufficient when persisted reads fail.
                    $f->jobs->catalog->db->execute('DELETE FROM lexical_documents WHERE tenant_id=? AND generation_id=?',[Fixture::id(1),Fixture::id(501)]);
                }
                Fixture::failure(fn() => $store->seal($f->lease,$c),'index_unreadable',D\Outcome::Unavailable);
                self::assertSame('failed',$f->state()); self::assertSame(Fixture::id(600),$f->pointer()); self::assertSame(4,$f->reads-4);
            });
        }
    }
    public function testOptionalFailureIsSealedWithDegradedCapabilities(): void
    {
        Fixture::run(function(Fixture $f): void {
            $optional = new ProjectionReadiness([
                'vector'=>static function(): void { throw new D\DomainFailure(D\Outcome::Unavailable,'optional_backend_unavailable'); },
                'graph'=>static function(): void { throw new D\DomainFailure(D\Outcome::Unknown,'optional_state_unknown'); },
            ]);
            $c = $f->candidate(); $store = $f->store($optional); $store->stage($f->lease,$c); $f->index($c); $receipt = $store->seal($f->lease,$c);
            self::assertSame('sealed',$f->state()); self::assertSame(['graph','vector'],$receipt->get('effectiveProfile')->get('degraded'));
            self::assertSame('postgres-fts-simple-v1',$receipt->get('effectiveProfile')->get('profile'));
            self::assertSame('success',$receipt->get('effectiveProfile')->get('capabilities')->get('lexical')->get('outcome'));
            self::assertSame('unknown',$receipt->get('effectiveProfile')->get('capabilities')->get('graph')->get('outcome'));
            self::assertSame('unavailable',$receipt->get('effectiveProfile')->get('capabilities')->get('vector')->get('outcome'));
            self::assertCount(4,$receipt->get('resources')); self::assertSame(4,$f->reads); self::assertNull($f->pointer());
            self::assertSame(Jcs::canonicalize($receipt),Jcs::canonicalize($store->seal($f->lease,$c))); self::assertSame(4,$f->reads);
        });
    }
    public function testMissingAndCorruptAssetsCannotSeal(): void
    {
        foreach (['missing','corrupt','omitted'] as $mode) {
            Fixture::run(function(Fixture $f) use($mode): void {
                $c = $f->candidate(); $store = $f->store();
                if ($mode === 'omitted') {
                    $w = $c->manifest->toWire()->with('resources',array_slice($c->resources,0,3))->with('counts', $c->manifest->toWire()->get('counts')->with('resources',3));
                    $bad = Fixture::candidateValue($w);
                    Fixture::failure(fn() => $store->stage($f->lease,$bad),'manifest_mismatch',D\Outcome::Incompatible);
                    self::assertSame(0,(int)$f->jobs->catalog->db->execute('SELECT count(*) FROM index_generations')->fetchColumn()); return;
                }
                $store->stage($f->lease,$c); $f->index($c); $asset = $f->jobs->directory.'/resource-'.Fixture::id(313);
                if ($mode === 'missing') { unlink($asset); } else { Jobs::durableFile($asset,'forged asset'); }
                Fixture::failure(fn() => $store->seal($f->lease,$c), $mode === 'missing' ? 'resource_missing' : 'resource_digest_mismatch', D\Outcome::Unavailable);
                self::assertSame('failed',$f->state()); self::assertNull($f->pointer());
            });
        }
    }
    public function testForgedBackendTextCannotReplaceCorruptCanonicalBytes(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c);
            $db = $f->jobs->catalog->db;
            // Building permits replacing the projection; immutable UPDATE guards remain intact.
            $row = $db->row('SELECT * FROM lexical_documents WHERE tenant_id=? AND generation_id=? AND chunk_id=?', [Fixture::id(1),Fixture::id(501),Fixture::id(6001)]);
            $db->execute('DELETE FROM lexical_documents WHERE tenant_id=? AND id=?',[Fixture::id(1),$row['id']]);
            $db->execute("INSERT INTO lexical_documents(tenant_id,id,generation_id,chunk_id,source_id,source_version_id,simple_tsvector,exact_tokens,text_digest)
                VALUES (?,?,?,?,?,?,to_tsvector('simple','valid looking forged citation'),ARRAY[]::text[],?)",
                [Fixture::id(1),$row['id'],Fixture::id(501),$row['chunk_id'],$row['source_id'],$row['source_version_id'],$row['text_digest']]);
            Jobs::durableFile($f->jobs->directory.'/resource-'.Fixture::id(311),'forged canonical');
            Fixture::failure(fn() => $store->seal($f->lease,$c),'resource_digest_mismatch',D\Outcome::Unavailable);
            self::assertSame('failed',$f->state());
            $hit = new D\Candidate($f->set->chunkSet->chunks[0]->ref,1,42);
            self::assertSame(['ref','rank','score'],array_keys(get_object_vars($hit))); // There is no backend quotation field.
        });
        // Exercise the existing real local-blob disclosure-content boundary as well as the seal gate.
        require_once dirname(__DIR__,2).'/storage/blobs/bootstrap.php';
        \KnowKit\Tests\Storage\Blobs\BlobFixture::run(function($f): void {
            $blob=$f->put();
            $source=new D\ExactSourceRef($blob->tenantId,$blob->sourceId,new D\SourceVersionId(Fixture::id(500)),new D\SourceDigest($blob->digest->value));
            $reader=new \KnowKit\Application\Resources\CanonicalContent($f->store,static function($exact,$artifact) use($source,$blob): void {
                $source->assertSame($exact);
                if ($artifact!=$blob) { throw new D\DomainFailure(D\Outcome::Incompatible,'immutable_conflict'); }
            },100);
            $backendText='credible forged backend citation'; self::assertSame('abc',$reader->read($source,$blob));
            chmod($f->path($blob),0600); file_put_contents($f->path($blob),'abx');
            foreach (['corrupt','missing'] as $mode) {
                if ($mode==='missing') { unlink($f->path($blob)); }
                $disclosed=[];
                try { $disclosed[]=$reader->read($source,$blob); self::fail('Forged backend text replaced canonical object'); }
                catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Incompatible,$failure->outcome); }
                self::assertSame([],$disclosed); self::assertSame('credible forged backend citation',$backendText);
            }
        });
    }
    public function testProjectionRebuildKeepsImmutableCitationCoordinates(): void
    {
        Fixture::run(function(Fixture $f): void {
            $this->previous($f); $db = $f->jobs->catalog->db; $role = $db->pdo->query('SELECT current_user')->fetchColumn();
            // Out-of-band backend loss, deliberately outside normal immutable adapter writes.
            $db->pdo->exec('RESET ROLE');
            try {
                $db->pdo->exec('ALTER TABLE lexical_documents DISABLE TRIGGER lexical_seal');
                try { $db->execute('DELETE FROM lexical_documents WHERE tenant_id=? AND generation_id=?',[Fixture::id(1),Fixture::id(500)]); }
                finally { $db->pdo->exec('ALTER TABLE lexical_documents ENABLE TRIGGER lexical_seal'); }
            } finally { $db->pdo->exec('SET ROLE '.$role); }
            Fixture::failure(fn() => $f->backend->reload($f->candidate(500)->manifest),'index_unreadable',D\Outcome::Unavailable);
            $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c); $store->seal($f->lease,$c); $f->snapshot(501,601);
            $hits = $f->lexical->search($this->searchScope($f,501,601),new D\Query('gravity'),10);
            self::assertCount(1,$hits->candidates); self::assertEquals($f->set->chunkSet->chunks[0]->ref,$hits->candidates[0]->ref);
            self::assertEquals($f->candidate(500)->manifest->toWire()->get('members'),$c->manifest->toWire()->get('members'));
            self::assertSame(Fixture::id(600),$f->pointer()); self::assertNotSame($f->candidate(500)->digest->value,$c->digest->value);
        });
    }
    public function testInterruptedStagingIsDurableAndRetryable(): void
    {
        Fixture::run(function(Fixture $f): void {
            $this->previous($f); $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c);
            $db = $f->jobs->catalog->db; $role = $db->pdo->query('SELECT current_user')->fetchColumn();
            $process = proc_open([PHP_BINARY,__DIR__.'/interrupted.php',$db->schema,$role], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,Fixture::root());
            self::assertIsResource($process);
            try {
                stream_set_timeout($pipes[1],10); self::assertSame("PARTIAL\n",fgets($pipes[1]));
                self::assertSame('building',$f->state()); self::assertSame(Fixture::id(600),$f->pointer());
                self::assertSame(1,(int)$db->execute('SELECT count(*) FROM lexical_documents WHERE generation_id=?',[Fixture::id(501)])->fetchColumn());
                self::assertTrue(proc_terminate($process,9));
            } finally {
                foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process);
            }
            $fresh = $f->jobs->connection();
            self::assertSame($c->digest->value,$fresh->execute('SELECT manifest_digest FROM generation_checks WHERE tenant_id=? AND generation_id=?',[Fixture::id(1),Fixture::id(501)])->fetchColumn());
            $store = $f->store(); $store->stage($f->lease,$c); $f->index($c); $store->seal($f->lease,$c);
            self::assertSame('sealed',$f->state()); self::assertSame(Fixture::id(600),$f->pointer());
        });
    }
    public function testSnapshotAndOutboxCommitAndRollbackTogether(): void
    {
        Fixture::run(function(Fixture $f): void {
            $this->previous($f); $catalog = $f->jobs->catalog; $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c);
            $bad = new class($catalog->outbox) implements Outbox {
                public function __construct(private Outbox $outbox) {}
                public function append(D\DomainEvent $event): void { $this->outbox->append($event); throw new D\DomainFailure(D\Outcome::Unavailable,'interrupted_transaction'); }
            };
            $before = $catalog->count('outbox');
            Fixture::failure(fn() => $f->store(outbox:$bad)->seal($f->lease,$c),'interrupted_transaction',D\Outcome::Unavailable);
            self::assertSame('building',$f->state()); self::assertSame($before,$catalog->count('outbox'));
            self::assertNull($catalog->db->execute('SELECT receipt_json FROM generation_checks WHERE generation_id=?',[Fixture::id(501)])->fetchColumn());
            $store->seal($f->lease,$c); self::assertSame($before+1,$catalog->count('outbox'));
            foreach ([true,false] as $rollback) {
                $event = $catalog->events->create($f->jobs->scope->tenantId,'knowledge_base',$f->jobs->scope->kbId,new D\Decimal('1'),'snapshot.activated',
                    new JsonObject(['kbId'=>Fixture::id(100),'snapshotId'=>Fixture::id(601),'manifestDigest'=>hash('sha256','snapshot-601'),'pointerVersion'=>'1']));
                $publish = function() use($catalog,$f,$event,$rollback): void {
                    $s = $f->jobs->scope; $catalog->tx->lockTenant($s->tenantId,D\LockMode::Update); $catalog->tx->lockKb($s->kbId,D\LockMode::Update);
                    $catalog->tx->lockSources([new D\SourceId(Fixture::id(2))],D\LockMode::Update);
                    $catalog->tx->lockGeneration(new D\GenerationId(Fixture::id(501)),D\LockMode::Update); $catalog->tx->lockJob($f->lease->jobId,D\LockMode::Update);
                    $f->snapshot(501,601); self::assertTrue($catalog->tx->casPointer($s->kbId,new D\SnapshotId(Fixture::id(601)),new D\Decimal('0')));
                    $catalog->outbox->append($event);
                    if ($rollback) { throw new D\DomainFailure(D\Outcome::Unavailable,'interrupted_transaction'); }
                };
                if ($rollback) {
                    Fixture::failure(fn() => $catalog->tx->run($publish),'interrupted_transaction',D\Outcome::Unavailable);
                    self::assertSame(Fixture::id(600),$f->pointer());
                    self::assertSame(0,(int)$catalog->db->execute('SELECT count(*) FROM publication_snapshots WHERE id=?',[Fixture::id(601)])->fetchColumn());
                } else { $catalog->tx->run($publish); self::assertSame(Fixture::id(601),$f->pointer()); }
                $fresh = $f->jobs->connection();
                self::assertSame($rollback?0:1,(int)$fresh->execute('SELECT count(*) FROM outbox WHERE tenant_id=? AND id=?', [Fixture::id(1),$event->toWire()->get('eventId')])->fetchColumn());
            }
            // No dispatcher was invoked: committed events remain durably available after connection reload.
            self::assertSame($before+2,$catalog->count('outbox'));
        });
    }
    public function testTenantLifecyclePolicyAndLeaseGuards(): void
    {
        foreach (['tenant','expired','corrected','revoked','epoch','denied','unknown','processing'] as $mode) {
            Fixture::run(function(Fixture $f) use($mode): void {
                $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c); $db = $f->jobs->catalog->db;
                $lease = $f->lease; $outcome = D\Outcome::Denied; $code = 'source_changed';
                switch ($mode) {
                    case 'tenant':
                        $s = $lease->scope; $foreign = new D\Scope(new D\TenantId(Fixture::id(2)),$s->kbId,$s->actorId,$s->purpose,$s->policyEpoch,$s->hostScopeHash);
                        $ref = $lease->sourceRef; $ref = new D\SourceVersionRef($foreign->tenantId,$ref->sourceId,$ref->sourceVersionId,$ref->sourceDigest);
                        $lease = new D\Lease($foreign,$lease->jobId,$lease->attemptId,$ref,$lease->generationId,$lease->fence,str_repeat('x',32),$lease->leaseUntil,$lease->attemptDeadline,$lease->sourceEpoch);
                        $code = 'scope_mismatch'; break;
                    case 'expired': $f->jobs->expire($lease); $code = 'writer_fenced'; $outcome = D\Outcome::Incompatible; break;
                    case 'corrected': $db->execute("UPDATE source_version_state SET usability='corrected' WHERE tenant_id=? AND id=?",[Fixture::id(1),Fixture::id(3)]); break;
                    case 'revoked': $db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?",[Fixture::id(1),Fixture::id(2)]); break;
                    case 'epoch': $db->execute('UPDATE sources SET source_policy_epoch=source_policy_epoch+1 WHERE tenant_id=? AND id=?',[Fixture::id(1),Fixture::id(2)]); $code='staging_conflict'; $outcome=D\Outcome::Incompatible; break;
                    case 'denied': $f->jobs->catalog->owner->decision = D\Decision::Deny; $code='policy_denied'; break;
                    case 'unknown': $f->jobs->catalog->owner->decision = D\Decision::Unknown; $code='policy_unknown'; $outcome=D\Outcome::Unknown; break;
                    case 'processing':
                        $f->jobs->queue->enqueue($f->jobs->command('ingest'),new D\IdempotencyKey('wrong-processing-profile'));
                        $lease=$f->jobs->lease('ingest',902); $code='processing_incompatible'; $outcome=D\Outcome::Incompatible; break;
                }
                Fixture::failure(fn() => $store->seal($lease,$c),$code,$outcome); self::assertSame('building',$f->state()); self::assertNull($f->pointer());
            });
        }
    }
    public function testReceiptAndSealedMembershipAreImmutable(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c = $f->candidate(); $store = $f->store(); $store->stage($f->lease,$c); $f->index($c); $store->seal($f->lease,$c); $pdo = $f->jobs->catalog->db->pdo;
            CatalogFixture::sqlReject($pdo,"UPDATE generation_checks SET receipt_json='{}' WHERE generation_id='".Fixture::id(501)."'");
            CatalogFixture::sqlReject($pdo,"UPDATE generation_checks SET source_epochs='[]' WHERE generation_id='".Fixture::id(501)."'");
            CatalogFixture::sqlReject($pdo,"DELETE FROM index_memberships WHERE generation_id='".Fixture::id(501)."'");
            CatalogFixture::sqlReject($pdo,"UPDATE lexical_documents SET text_digest='".str_repeat('0',64)."' WHERE generation_id='".Fixture::id(501)."'");
            self::assertSame('sealed',$f->state());
        });
    }
    public function testStoredLexicalTamperFailsWithValidCanonicalObjects(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c=$f->candidate(); $store=$f->store(); $store->stage($f->lease,$c); $f->index($c); $db=$f->jobs->catalog->db;
            $row=$db->row('SELECT * FROM lexical_documents WHERE tenant_id=? AND generation_id=? AND chunk_id=?',[Fixture::id(1),Fixture::id(501),Fixture::id(6001)]);
            $db->execute('DELETE FROM lexical_documents WHERE tenant_id=? AND id=?',[Fixture::id(1),$row['id']]);
            $db->execute("INSERT INTO lexical_documents(tenant_id,id,generation_id,chunk_id,source_id,source_version_id,simple_tsvector,exact_tokens,text_digest)
                VALUES (?,?,?,?,?,?,to_tsvector('simple','forged'),ARRAY[]::text[],?)",
                [Fixture::id(1),$row['id'],Fixture::id(501),$row['chunk_id'],$row['source_id'],$row['source_version_id'],$row['text_digest']]);
            Fixture::failure(fn() => $store->seal($f->lease,$c),'index_unreadable',D\Outcome::Unavailable);
            self::assertSame('failed',$f->state()); self::assertSame(4,$f->reads);
            CatalogFixture::sqlReject($db->pdo,"UPDATE index_generations SET state='sealed' WHERE tenant_id='".Fixture::id(1)."' AND id='".Fixture::id(501)."'");
        });
    }
    public function testPartialCoverageRequiresSavedPolicyAndRequiredFeatures(): void
    {
        $coverage=Fixture::data()->get('ir')->get('coverage')->with('state','partial')->with('missingFeatures',['tables']);
        foreach (['default','allowed','required'] as $mode) {
            Fixture::run(function(Fixture $f) use($mode): void {
                $db=$f->jobs->catalog->db;
                if ($mode !== 'default') {
                    $policy=new JsonObject(['allowPartial'=>true,'requiredFeatures'=>$mode==='required'?['tables']:[]]);
                    $db->execute('UPDATE knowledge_bases SET coverage_policy=? WHERE tenant_id=? AND id=?',[Jcs::canonicalize($policy),Fixture::id(1),Fixture::id(100)]);
                }
                $c=$f->candidate(); $store=$f->store();
                if ($mode !== 'allowed') {
                    Fixture::failure(fn() => $store->stage($f->lease,$c),'coverage_required',D\Outcome::Unavailable);
                    self::assertSame(0,(int)$db->execute('SELECT count(*) FROM index_generations')->fetchColumn());
                } else {
                    $store->stage($f->lease,$c); $f->index($c); $store->seal($f->lease,$c); self::assertSame('sealed',$f->state());
                }
                self::assertNull($f->pointer());
            },$coverage);
        }
    }
    public function testResourceReaderBindsExactAcceptedStorageVersion(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c=$f->candidate(); $store=$f->store(); $store->stage($f->lease,$c); $f->index($c);
            $blobs=$this->createMock(\KnowKit\Port\BlobStore::class);
            $blobs->expects(self::exactly(4))->method('open')->willReturnCallback(function(D\BlobRef $ref) use($f): \KnowKit\Port\ReadableStream {
                self::assertSame(Fixture::id(1),$ref->tenantId->value); self::assertSame(Fixture::id(2),$ref->sourceId->value);
                self::assertSame('fixture',$ref->storageId); self::assertSame('version-1',$ref->objectVersion);
                foreach ([310,311,312,313] as $id) {
                    $bytes=file_get_contents($f->jobs->directory.'/resource-'.Fixture::id($id));
                    if (hash('sha256',$bytes)===$ref->digest->value) { return new \KnowKit\Tests\Jobs\Leases\Bytes($bytes); }
                }
                throw new \LogicException('Unexpected immutable object');
            });
            $reader=new \KnowKit\Adapter\Postgres\IndexGenerations\PdoResourceReader($f->jobs->catalog->db,$blobs);
            $probe=new \KnowKit\Application\Publication\IndexReadabilityProbe($f->backend,$reader->open(...));
            self::assertCount(4,$probe->verify($c));
            Fixture::failure(fn() => $reader->open(new D\TenantId(Fixture::id(2)),$c->resources[0]),'resource_unavailable',D\Outcome::Unavailable);
            Fixture::failure(fn() => $reader->open($f->jobs->scope->tenantId,$c->resources[0]->with('digest',str_repeat('f',64))),'resource_unavailable',D\Outcome::Unavailable);
        });
    }
    private function searchScope(Fixture $f, int $generation, int $snapshot): D\AuthorizedSearchScope
    {
        $scope = $f->jobs->scope; $eligible = [new D\SourceVersionId(Fixture::id(3))];
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds'=>[],'allowedSourceVersionIds'=>[Fixture::id(3)],'grantEpoch'=>'0',
            'policyBindingDigest'=>str_repeat('c',64),'expiresAt'=>'2026-11-07T00:00:30Z']),PortFixture::validator());
        return D\AuthorizedSearchScope::materialize($scope,$grant,new D\SnapshotRef($scope->tenantId,$scope->kbId,new D\SnapshotId(Fixture::id($snapshot)),
            new D\Fingerprint(hash('sha256','snapshot-'.$snapshot))),new D\GenerationId(Fixture::id($generation)),$eligible,new D\UtcInstant('2026-11-07T00:00:01Z'));
    }
}
