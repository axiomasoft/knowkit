<?php
declare(strict_types=1);
namespace KnowKit\Tests\Publication\Manifest;

use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\{Builder, RankFusion};
use KnowKit\Domain\ManifestCandidate as Candidate;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Port\GenerationReadBackend;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Jobs\Leases\Bytes;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/Fixture.php';

#[Group('publication:manifest')]
final class ManifestTest extends TestCase
{
    /** Contract double only: no external vector adapter qualification. */
    private function backend(array $refs, ?D\EmbeddingSpace $space = null): GenerationReadBackend
    {
        return new class($refs, $space) implements GenerationReadBackend {
            public function __construct(private array $refs, private ?D\EmbeddingSpace $space) {}
            public function reload(D\IndexManifest $manifest): D\PersistedIndexRead
            { return new D\PersistedIndexRead(new D\ProfileId($manifest->toWire()->get('profile')->get('id')), $this->space?->digest(), $this->refs); }
        };
    }
    public function testIndependentFixtureDigestsAndCaseInventory(): void
    {
        $data = Fixture::data(); $candidate = Fixture::candidateValue();
        self::assertSame('1.0.0', $data->get('fixtureVersion')); self::assertNotEmpty($data->get('versionRationale')); self::assertNotEmpty($data->get('rights')->get('license'));
        self::assertSame($data->get('manifestDigest'), $candidate->digest->value);
        self::assertSame($data->get('manifest')->get('members')[0]->get('irDigest'), hash('sha256', Jcs::canonicalize($data->get('ir'))));
        $built = (new Builder(PortFixture::validator()))->build(new D\Scope(new D\TenantId(Fixture::id(1)), new D\KbId(Fixture::id(100)),
            new D\ActorId(Fixture::id(101)), 'catalog.manage', new D\Decimal('0'), new D\Sha256(str_repeat('a',64))),
            new D\GenerationId(Fixture::id(501)), [Fixture::set()], array_reverse($candidate->resources), $data->get('ir')->get('coverage'));
        self::assertSame($candidate->digest->value, $built->digest->value);
        self::assertSame($data->get('manifest')->get('members')[0]->get('chunkSetDigest'), HashDomain::ChunkSetManifest->digest($data->get('chunkSetManifest')));
        $group = json_decode(file_get_contents(Fixture::root().'/tests/fixtures/check-groups/publication-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        (new Qa(Fixture::root()))->fixtures($group);
        foreach ($data->get('cases') as $id=>$outcome) { self::assertContains($id, $group['requiredCaseIds']); self::assertNotEmpty($outcome); }
        foreach ($group['testCaseMap'] as $methods) {
            foreach ($methods as $method) { [$class,$name] = explode('::',$method); require_once __DIR__.'/'.substr($class,strrpos($class,'\\')+1).'.php'; self::assertTrue(method_exists($class,$name)); }
        }
        $reuse = json_decode(file_get_contents(__DIR__.'/evidence/P3.3-reuse.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(7, $reuse['sources']);
        foreach ($reuse['sources'] as $source) { self::assertSame($source['sha256'], $source['observedSha256']); self::assertNotEmpty($source['rationale']); }
    }
    public function testDuplicateAndMissingPersistedChunksAreRejected(): void
    {
        $c = Fixture::candidateValue(); $refs = array_map(static fn($chunk) => $chunk->ref, $c->sets[0]->chunkSet->chunks);
        foreach ([[$refs[0],$refs[0]], [$refs[0]]] as $bad) {
            $probe = new IndexReadabilityProbe($this->backend($bad), static fn() => new Bytes(''));
            Fixture::failure(fn() => $probe->verifyBackend($c), 'index_membership_mismatch', D\Outcome::Unavailable);
        }
        $set = Fixture::set(); $bad = new D\ChunkingResult($set->chunkSet, $set->manifest()->with('chunks', [$set->manifest()->get('chunks')[0]]));
        Fixture::failure(fn() => new Candidate($c->manifest, [$bad], $c->resources), 'manifest_mismatch', D\Outcome::Incompatible);
    }
    public function testFullEmbeddingFingerprintIsCheckedWithoutQueryEmbedding(): void
    {
        $a = new D\EmbeddingSpace('synthetic','model','r1',2,'l2','v1','document','query');
        $b = new D\EmbeddingSpace('synthetic','model','r2',2,'l2','v1','document','query');
        $w = Fixture::data()->get('manifest');
        $w = $w->with('profile', $w->get('profile')->with('id','synthetic-vector-v1')->with('kind','vector'))->with('embeddingSpaceDigest', $a->digest()->value);
        $candidate = Fixture::candidateValue($w); $refs = array_map(static fn($chunk) => $chunk->ref, $candidate->sets[0]->chunkSet->chunks);
        (new IndexReadabilityProbe($this->backend($refs, $a), static fn() => throw new \LogicException('No query embedding')))->verifyBackend($candidate);
        Fixture::failure(fn() => (new IndexReadabilityProbe($this->backend($refs, $b), static fn() => new Bytes('')))->verifyBackend($candidate),
            'space_or_profile_mismatch', D\Outcome::Incompatible);
        self::assertNotSame($a->digest()->value, $b->digest()->value);
    }
    public function testForgedCandidateAndWrongRevisionAreRefused(): void
    {
        $c = Fixture::candidateValue(); $ref = $c->sets[0]->chunkSet->chunks[0]->ref; $r = $ref->revisionRef;
        foreach ([new D\ChunkRef($r,$ref->chunkSetId,new D\ChunkId(Fixture::id(6999)),$ref->chunkDigest),
            new D\ChunkRef(new D\DocumentRevisionRef($r->sourceRef,new D\DocumentRevisionId(Fixture::id(444)), $r->irDigest,$r->parserSignature),$ref->chunkSetId,$ref->chunkId,$ref->chunkDigest)] as $forged) {
            Fixture::failure(fn() => $c->assertCandidate($forged), 'candidate_not_in_manifest', D\Outcome::Incompatible);
        }
    }
    public function testRankFusionIgnoresIncompatibleScoreScales(): void
    {
        $c = Fixture::candidateValue(); [$a,$b] = array_map(static fn($chunk) => $chunk->ref, $c->sets[0]->chunkSet->chunks);
        $fused = RankFusion::fuse($c, [[new D\Candidate($a,1,0.001),new D\Candidate($b,2,0.0001)], [new D\Candidate($b,1,999999),new D\Candidate($a,2,80000)]]);
        self::assertSame([$a,$b], array_map(static fn($hit) => $hit->ref, $fused));
        self::assertSame(1/61+1/62, $fused[0]->score); self::assertSame($fused[0]->score, $fused[1]->score);
        self::assertSame('postgres-fts-simple-v1', $c->manifest->toWire()->get('profile')->get('id'));
        self::assertSame([], RankFusion::fuse($c, [[],[]]));
        self::assertSame($b, RankFusion::fuse($c, [[],[new D\Candidate($b,1,999999)]])[0]->ref);
        Fixture::failure(fn() => RankFusion::fuse($c, [[new D\Candidate($a,1),new D\Candidate($a,2)]]),'ranking_invalid',D\Outcome::Incompatible);
    }
    public function testRequiredMissingSkippedAndEmptyReportsFailClosed(): void
    {
        $group = json_decode(file_get_contents(Fixture::root().'/tests/fixtures/check-groups/publication-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($group['testCaseMap'] as $methods) { foreach ($methods as $id) { $cases[$id] = ['id'=>$id,'status'=>'passed']; } }
        self::assertCount(count($group['requiredCaseIds']), Qa::validateCases(array_values($cases),$group));
        $missing = $cases; unset($missing[array_key_first($missing)]); $skipped = $cases; $skipped[array_key_first($skipped)]['status']='skipped';
        foreach ([[],array_values($missing),array_values($skipped)] as $bad) {
            try { Qa::validateCases($bad,$group); self::fail('Invalid required-case inventory accepted'); } catch (\RuntimeException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }
    public function testNonProgressingAndSameLengthCorruptStreamsCannotPassProbe(): void
    {
        $candidate=Fixture::candidateValue(); $backend=$this->createMock(GenerationReadBackend::class);
        $backend->expects(self::never())->method('reload');
        $bad=new class implements \KnowKit\Port\ReadableStream {
            public function read(int $maxBytes): string { return ''; }
            public function eof(): bool { return false; }
            public function close(): void {}
        };
        $probe=new IndexReadabilityProbe($backend,static fn() => $bad);
        Fixture::failure(fn() => $probe->verify($candidate),'resource_stream_invalid',D\Outcome::Unavailable);
        $probe=new IndexReadabilityProbe($backend,static function(D\TenantId $tenant,JsonObject $resource): Bytes {
            $data=Fixture::data();
            $bytes=match ($resource->get('resourceId')) {
                Fixture::id(310)=>Jcs::canonicalize($data->get('ir')),
                Fixture::id(311)=>'xxxxxxx.',
                Fixture::id(312)=>$data->get('ir')->get('segments')[1]->get('text'),
                Fixture::id(313)=>base64_decode($data->get('assetBase64'),true),
            };
            return new Bytes($bytes);
        });
        Fixture::failure(fn() => $probe->verify($candidate),'resource_digest_mismatch',D\Outcome::Unavailable);
        self::assertSame(strlen('gravity.'),strlen('xxxxxxx.'));
    }
}
