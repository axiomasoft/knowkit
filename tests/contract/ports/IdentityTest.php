<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Ports;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractViolation, Jcs, JsonObject};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('contract:ports')]
final class IdentityTest extends TestCase
{
    public function testProcessingAxesAndHistoricalRefs(): void
    {
        $fixture = PortFixture::fixture();
        $base = PortFixture::processing($fixture->get('base'));
        self::assertSame($fixture->get('baseDigest'), $base->digest()->value);
        foreach ($fixture->get('scenarios') as $case) {
            $next = PortFixture::processing($case->get('payload'));
            self::assertSame($case->get('expectedDigest'), $next->digest()->value, $case->get('id'));
            self::assertSame($case->get('changedAxes'), $base->changedAxes($next), $case->get('id'));
            self::assertNotSame($base->digest()->value, $next->digest()->value);
        }
        $historical = [];
        $guard = new D\ExactIdentityGuard(PortFixture::scope()->tenantId);
        foreach ($fixture->get('history') as $i => $item) {
            $ref = PortFixture::chunk($i); $guard->remember($ref);
            self::assertSame($item->get('irDigest'), $ref->revisionRef->irDigest->value);
            $historical[Jcs::canonicalize($ref->toWire())] = new D\IrDocument($item->get('ir'), PortFixture::validator());
        }
        self::assertCount(2, $historical);
        $old = PortFixture::chunk();
        self::assertSame($fixture->get('history')[0]->get('irDigest'),
            hash('sha256', $historical[Jcs::canonicalize($old->toWire())]->canonical()));
        self::assertNotSame(PortFixture::revision()->irDigest->value, PortFixture::revision(1)->irDigest->value);
        self::assertSame($old->revisionRef->sourceRef->sourceDigest->value, PortFixture::source()->sourceDigest->value);
    }

    public function testChangedOriginalCannotReuseVersionOrCitation(): void
    {
        $fixture = PortFixture::fixture(); $source = PortFixture::source();
        $source->assertBytes($fixture->get('sourceBytes'));
        try {
            $source->assertBytes($fixture->get('negative')->get('changedBytes')); self::fail('Mutated source accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame('immutable_conflict', $failure->errorCode);
        }
        $guard = new D\ExactIdentityGuard($source->tenantId); $guard->remember($source);
        $forged = new D\SourceVersionRef($source->tenantId,$source->sourceId,$source->sourceVersionId,
            new D\SourceDigest(hash('sha256','different original')));
        try { $guard->remember($forged); self::fail('Identity mutation accepted'); }
        catch (D\DomainFailure $failure) { self::assertSame('immutable_conflict', $failure->errorCode); }
        $this->expectException(D\DomainFailure::class);
        PortFixture::processing($fixture->get('base'))->changedAxes(
            PortFixture::processing($fixture->get('base')->with('sourceDigest', $forged->sourceDigest->value)));
    }

    public function testRevisionIdentityCannotChangeItsIrOrSource(): void
    {
        $ref = PortFixture::revision(); $guard = new D\ExactIdentityGuard($ref->sourceRef->tenantId); $guard->remember($ref);
        $forged = new D\DocumentRevisionRef($ref->sourceRef,$ref->documentRevisionId,new D\IrDigest(str_repeat('f',64)),$ref->parserSignature);
        $this->expectException(D\DomainFailure::class);
        $guard->remember($forged);
    }

    public function testCanonicalProfilesAndSpaceAreIndependent(): void
    {
        $a = new D\ParserProfile('text-v1','text','1',new JsonObject(['language'=>'ru','mode'=>'native']));
        $b = new D\ParserProfile('text-v1','text','1',new JsonObject(['mode'=>'native','language'=>'ru']));
        self::assertSame($a->signature->profileDigest->value,$b->signature->profileDigest->value);
        $c = new D\ChunkerProfile('chunk-v1','structural','1',new JsonObject(['language'=>'ru','mode'=>'native']));
        self::assertNotSame($a->signature->profileDigest->value,$c->signature->profileDigest->value);
        $space = PortFixture::space(); self::assertSame(PortFixture::fixture()->get('spaceDigest'),$space->digest()->value);
        foreach (['provider','model','revision','indexVersion','documentRole','queryRole'] as $field) {
            $other = PortFixture::space([$field=>'changed']); self::assertNotSame($space->digest()->value,$other->digest()->value);
            try { $space->assertCompatible($other); self::fail('Space mismatch accepted'); } catch (D\DomainFailure) {}
        }
        foreach ([['dimensions'=>4],['normalization'=>'l2']] as $changes) {
            self::assertNotSame($space->digest()->value,PortFixture::space($changes)->digest()->value);
        }
        $this->expectException(\InvalidArgumentException::class);
        new D\ParserProfile('bad','text','1',new JsonObject(['apiKey'=>'never fingerprint credentials']));
    }

    public function testProfilesRejectSensitiveOptionsInsideNestedLists(): void
    {
        foreach ([D\ParserProfile::class, D\ChunkerProfile::class] as $profile) {
            foreach (['apiKey', 'credentials', 'localPath', 'signedUrl', 'accessToken'] as $key) {
                foreach ([0, 1, 2, 6] as $depth) {
                    foreach ([new JsonObject([$key => 'private-value']), [$key => 'private-value']] as $value) {
                        for ($i = 0; $i < $depth; $i++) {
                            $value = [$value];
                        }
                        try {
                            new $profile('nested', 'test', '1', new JsonObject(['steps' => $value]));
                            self::fail('Sensitive option accepted at list depth ' . $depth);
                        } catch (\InvalidArgumentException $failure) {
                            self::assertSame('Only semantic profile settings may be fingerprinted', $failure->getMessage());
                        }
                    }
                }
            }
            $options = new JsonObject(['steps' => [[new JsonObject(['language' => 'ru', 'mode' => 'native'])]]]);
            $valid = new $profile('nested', 'test', '1', $options);
            self::assertSame(Jcs::canonicalize($options), $valid->options->canonical());
        }
    }

    public function testSemanticOptionTraversalRejectsExcessiveNesting(): void
    {
        $value = 'local';
        for ($depth = 0; $depth < 65; $depth++) {
            $value = [$value];
        }
        $this->expectException(\InvalidArgumentException::class);
        D\ValueGuard::semanticOptions(new JsonObject(['steps' => $value]));
    }

    public function testImmutableRecordsAndUnicodeLocators(): void
    {
        $ref = PortFixture::source();
        try { $ref->sourceDigest = new D\SourceDigest(str_repeat('f',64)); self::fail('Mutable reference'); } catch (\Error) {}
        $text = 'Привет'; $locator = D\TextLocator::segment(new D\LocalId('s1'),new D\LocalId('b1'),0,strlen($text));
        $span = new D\Span($locator,new D\Sha256(hash('sha256',$text)));
        self::assertSame($text,$span->slice($text)); self::assertNull($span->pageNo());
        $page = new D\Span(D\TextLocator::page(new D\LocalId('p1'),1,'iv',new D\LocalId('b1'),0,2,new D\Bbox(0,0,1,1)),$span->textDigest);
        self::assertSame(1,$page->pageNo()); self::assertFalse($page->locator->toWire()->has('pageNo'));
        try { D\TextLocator::segment(new D\LocalId('s'),new D\LocalId('b'),1,2)->slice($text); self::fail('Mid-codepoint span'); }
        catch (ContractViolation $failure) { self::assertSame('utf8_boundary',$failure->errorCode); }
        $this->expectException(\InvalidArgumentException::class); new D\Bbox(10,0,1,1);
    }

    public function testTypedIdsDecimalsAndCalendarInstants(): void
    {
        self::assertFalse((new D\SourceVersionId(PortFixture::id(1)))->equals(new D\DocumentRevisionId(PortFixture::id(1))));
        self::assertSame('9223372036854775807',(new D\Decimal('9223372036854775806'))->next()->value);
        self::assertSame('18446744073709551615',(new D\Uint64('18446744073709551614'))->next()->value);
        try { (new D\Decimal('9223372036854775807'))->next(); self::fail('Fence wrapped or overflowed'); }
        catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Unavailable,$failure->outcome); }
        foreach (['01','-1','9223372036854775808'] as $bad) {
            try { new D\Decimal($bad); self::fail('Noncanonical decimal'); } catch (\InvalidArgumentException) {}
        }
        try { new D\ResourceRef(PortFixture::scope()->tenantId,'source_version',new D\DocumentRevisionId(PortFixture::id(3))); self::fail('Interchanged ID type'); }
        catch (\InvalidArgumentException) {}
        foreach (['2026-02-30T12:00:00Z','2026-11-06T24:00:00Z','2026-11-06T12:00:00+00:00'] as $bad) {
            try { new D\UtcInstant($bad); self::fail('Invalid UTC instant'); } catch (\InvalidArgumentException) {}
        }
        self::assertSame(0,(new D\UtcInstant('2026-11-06T12:00:00Z'))->compare(new D\UtcInstant('2026-11-06T12:00:00.000000Z')));
        $this->expectException(\InvalidArgumentException::class); new D\SourceId(strtolower(PortFixture::id(1)));
    }

    public function testVersionedFixtureManifestAndNegativeLoaderCases(): void
    {
        $manifest = PortFixture::json('tests/fixtures/identity/manifest.v1.json'); PortFixture::validateManifest($manifest);
        $entries = $manifest->get('entries'); $first = $entries[0];
        self::assertCount(3,$entries);
        foreach ([
            $manifest->with('entries',[]),
            $manifest->with('entries',[$first,$first]),
            $manifest->with('entries',[$first->with('path','tests/fixtures/identity/missing.json')]),
            $manifest->with('entries',[$first->with('sha256',str_repeat('0',64))]),
            $manifest->with('entries',[$first->with('schemaId','urn:unknown')]),
        ] as $invalid) {
            try { PortFixture::validateManifest($invalid); self::fail('Invalid fixture manifest accepted'); }
            catch (\InvalidArgumentException) {}
        }
    }
}
