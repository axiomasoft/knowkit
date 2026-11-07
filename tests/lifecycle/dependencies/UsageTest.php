<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Dependencies;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:dependencies')]
final class UsageTest extends TestCase
{
    public function testInvocationLineageSurvivesAbsentPayloadAndRevocationWithoutRewritingHistory(): void
    {
        Fixture::run(function($f) {
            $f->chunks(); $a=$f->usage(16,'same-run'); $b=$f->usage(17,'same-run');
            $fragment=$b->toWire()->get('fragmentRefs')[0]->with('fragmentDigest',hash('sha256','different-fragment'));
            $b=new D\Usage($b->toWire()->with('fragmentRefs',[$fragment,...$b->toWire()->get('fragmentRefs')]),PortFixture::validator());
            $f->atomic(fn()=>$f->graph->recordUsage($a)); $f->atomic(fn()=>$f->graph->recordUsage($b)); $f->atomic(fn()=>$f->graph->recordUsage($a));
            $reverse=new D\Usage($b->toWire()->with('fragmentRefs',array_reverse($b->toWire()->get('fragmentRefs'))),PortFixture::validator());
            $f->atomic(fn()=>$f->graph->recordUsage($reverse));
            self::assertSame(2,$f->catalog->count('usage_refs'));
            $usages=$f->catalog->db->execute('SELECT * FROM evidence_usages ORDER BY id')->fetchAll();
            $refs=$f->catalog->db->execute('SELECT * FROM usage_refs ORDER BY id')->fetchAll();
            self::assertNull($usages[0]['payload_resource_id']); self::assertNull($usages[1]['payload_resource_id']);
            self::assertStringNotContainsString('text',json_encode($refs,JSON_THROW_ON_ERROR));
            self::assertSame($a->toWire()->get('fragmentRefs')[0]->get('chunkRef')->get('sourceVersionId'),
                json_decode($refs[0]['exact_version_ref'],true,512,JSON_THROW_ON_ERROR)['fragmentRefs'][0]['chunkRef']['sourceVersionId']);
            $event=$f->event(); $f->atomic(fn()=>$f->revoke($event)); $f->atomic(fn()=>$f->graph->invalidate($event));
            self::assertSame(2,$f->catalog->count('usage_invalidations'));
            self::assertSame($usages,$f->catalog->db->execute('SELECT * FROM evidence_usages ORDER BY id')->fetchAll());
            self::assertSame($refs,$f->catalog->db->execute('SELECT * FROM usage_refs ORDER BY id')->fetchAll());
            $f->atomic(fn()=>$f->graph->recordUsage($a)); // history replay is independent of permission for a new invocation
            $late=$f->usage(18,'same-run');
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->recordUsage($late)),'ancestor_unusable',D\Outcome::Denied);
            self::assertSame(2,$f->catalog->count('usage_refs'));
        });
    }
    public function testUsageBindingExactIdentityAndImmutableFragmentsAreChecked(): void
    {
        Fixture::run(function($f) {
            $f->chunks(); $u=$f->usage(); $f->atomic(fn()=>$f->graph->recordUsage($u));
            foreach (['hostScopeHash'=>str_repeat('f',64),'purpose'=>'other.read','checkedAt'=>'2026-11-06T12:00:01Z'] as $key=>$value) {
                $changed=new D\Usage($u->toWire()->with($key,$value),PortFixture::validator());
                Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->recordUsage($changed)),'immutable_conflict');
            }
            $fragment=$u->toWire()->get('fragmentRefs')[0];
            foreach (['sourceDigest'=>str_repeat('f',64),'irDigest'=>str_repeat('f',64),'chunkDigest'=>str_repeat('f',64),'sourceVersionId'=>Fixture::id(30)] as $key=>$value) {
                $changed=new D\Usage($u->toWire()->with('fragmentRefs',[$fragment->with('chunkRef',$fragment->get('chunkRef')->with($key,$value))]),PortFixture::validator());
                Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->recordUsage($changed)),'immutable_conflict');
            }
            $changed=new D\Usage($u->toWire()->with('fragmentRefs',[$fragment->with('fragmentDigest',str_repeat('f',64))]),PortFixture::validator());
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->recordUsage($changed)),'immutable_conflict');
            $missing=$f->usage(99,prepare:false); Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->recordUsage($missing)),'usage_not_prepared');
            $foreign=$fragment->with('chunkRef',$fragment->get('chunkRef')->with('tenantId',Fixture::id(9)));
            // Schema-bound Usage itself must reject cross-tenant exact refs, before persistence if the validator enforces it.
            try {
                $bad=new D\Usage($u->toWire()->with('fragmentRefs',[$foreign]),PortFixture::validator());
                Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->recordUsage($bad)),'scope_mismatch',D\Outcome::Denied);
            } catch (\KnowKit\Domain\Contract\ContractViolation $e) { self::assertNotEmpty($e->getMessage()); }
            self::assertSame(1,$f->catalog->count('usage_refs'));
        });
    }
    public function testRevokedGraphParentPreventsConfirmedSupportingEvidence(): void
    {
        Fixture::run(function($f) {
            $f->catalog->source(8); $f->version(80,8);
            $f->atomic(fn()=>$f->register('source_version',80));
            $f->chunks([$f->ref('source_version',80)]); $u=$f->usage(); $f->atomic(fn()=>$f->graph->recordUsage($u));
            $event=$f->event(source:8); $f->atomic(fn()=>$f->graph->invalidate($event));
            $f->atomic(fn()=>self::assertSame(D\Decision::Deny,$f->decision($f->ref('chunk',11))));
            $marker=$f->catalog->db->row('SELECT * FROM usage_invalidations');
            self::assertSame(Fixture::id(8),$marker['source_id']); self::assertNull($marker['source_version_id']);
            self::assertSame('usable',$f->catalog->db->row('SELECT usability FROM source_version_state WHERE id=?',[Fixture::id(3)])['usability']);
            $late=$f->usage(17); Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->recordUsage($late)),'ancestor_unusable',D\Outcome::Denied);
        });
    }
    public function testExactCorrectionMarksOnlyAffectedUsageAndLateUnregisteredRootIsDenied(): void
    {
        Fixture::run(function($f) {
            $f->chunks(); $u=$f->usage(); $f->atomic(fn()=>$f->graph->recordUsage($u));
            $f->version(30); $correct=$f->event('source_version.corrected'); $f->atomic(fn()=>$f->graph->invalidate($correct));
            $marker=$f->catalog->db->row('SELECT * FROM usage_invalidations'); self::assertSame(Fixture::id(3),$marker['source_version_id']);
            $f->atomic(fn()=>$f->register('source_version',30));
            self::assertSame(1,$f->catalog->count('usage_invalidations'));
            $f->version(31); $event=$f->event('source_version.corrected',version:31,epoch:'2'); $f->atomic(fn()=>$f->graph->invalidate($event));
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('source_version',31)),'ancestor_unusable',D\Outcome::Denied);
            self::assertSame(1,$f->catalog->count('usage_invalidations'));
        });
    }
}
