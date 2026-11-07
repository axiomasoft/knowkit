<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Text;

use KnowKit\Application\Ingest\Text\{ExtractionResult, TextParser};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractViolation, JsonObject, LocatorConversion};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:text')]
final class SidecarTest extends TestCase
{
    public function testNormalizationPartitionAndEveryCanonicalCodepointMapExactly(): void
    {
        $raw = "\xef\xbb\xbfA\r\n漢\re\u{0301}\n";
        $source = TextFixture::source($raw); $profile = TextFixture::profile('text');
        $r = (new TextParser(TextFixture::validator()))->extract($source, $profile);
        $normal = $r->normalization; self::assertSame("A\n漢\ne\u{0301}\n", $normal->text);
        $tuples = array_map(static fn(JsonObject $run): array => [$run->get('kind'), $run->get('sourceStart'), $run->get('sourceEnd'), $run->get('canonicalStart'), $run->get('canonicalEnd')], $normal->transforms);
        self::assertSame([['bom',0,3,0,0], ['copy',3,4,0,1], ['crlf',4,6,1,2], ['copy',6,9,2,5], ['cr',9,10,5,6], ['copy',10,14,6,10]], $tuples);
        $a = $b = 0;
        foreach ($normal->transforms as $run) {
            self::assertSame($a, $run->get('sourceStart')); self::assertSame($b, $run->get('canonicalStart'));
            $a = $run->get('sourceEnd'); $b = $run->get('canonicalEnd');
        }
        self::assertSame(strlen($raw), $a); self::assertSame(strlen($normal->text), $b);
        $offset = 0;
        foreach (preg_split('//u', $normal->text, -1, PREG_SPLIT_NO_EMPTY) as $point) {
            $map = $normal->sourceRange($offset, $offset + strlen($point));
            $original = substr($raw, $map->get('sourceStart'), $map->get('sourceEnd') - $map->get('sourceStart'));
            self::assertSame($point, str_replace(["\r\n", "\r"], "\n", $original)); $offset += strlen($point);
        }
        self::assertEquals(new JsonObject(['sourceStart' => 4, 'sourceEnd' => 6]), $normal->sourceRange(1, 2));
        foreach ([[3,5], [0,11], [0,0]] as [$start,$end]) {
            try { $normal->sourceRange($start, $end); self::fail('Invalid canonical range accepted'); }
            catch (ContractViolation $v) { self::assertContains($v->errorCode, ['utf8_boundary','span_out_of_range','invalid_span']); }
        }
    }

    public function testSidecarImmutableBindingRejectsForeignTenantVersionProfileAndIr(): void
    {
        $source = TextFixture::source('secret source'); $profile = TextFixture::profile('text');
        $p = new TextParser(TextFixture::validator()); $r = $p->extract($source, $profile);
        $r->assertBoundTo($source, $profile, $r->document);
        $wire = $r->sidecar();
        self::assertSame('text-extraction-sidecar:1', $wire->get('schemaVersion'));
        self::assertEquals($source->ref->toWire(), $wire->get('sourceRef'));
        self::assertSame(hash('sha256', $r->document->canonical()), $wire->get('irDigest'));
        $wire = $wire->with('irDigest', str_repeat('0',64));
        self::assertNotSame($wire->get('irDigest'), $r->sidecar()->get('irDigest'));
        TextFixture::failure(fn() => $r->assertBoundTo(TextFixture::source('secret source', tenant: '01JC0000000000000000000009'), $profile, $r->document), D\Outcome::Denied, 'scope_mismatch');
        foreach ([TextFixture::source('changed'), TextFixture::source('secret source', version: '01JC000000000000000000000Y'),
            TextFixture::source('secret source', media: 'text/markdown')] as $other) {
            TextFixture::failure(fn() => $r->assertBoundTo($other, $profile, $r->document), D\Outcome::Incompatible, 'immutable_conflict');
        }
        TextFixture::failure(fn() => $r->assertBoundTo($source, TextFixture::profile('text', ['maxLines' => 100]), $r->document), D\Outcome::Incompatible, 'immutable_conflict');
        $other = $p->extract(TextFixture::source('changed'), $profile);
        TextFixture::failure(fn() => $r->assertBoundTo($source, $profile, $other->document), D\Outcome::Incompatible, 'immutable_conflict');
        TextFixture::failure(fn() => new ExtractionResult(TextFixture::source('changed'), $profile, $r->normalization, [], [], [], null, TextFixture::validator()), D\Outcome::Incompatible, 'immutable_conflict');
        self::assertStringNotContainsString('secret source', print_r($r, true));
        self::assertStringNotContainsString('secret source', print_r($r->normalization, true));
    }

    public function testOriginalCanonicalRenderedAndTruncatedDigestsAreSeparate(): void
    {
        $case = TextFixture::manifest('text')['cases'][0]; $raw = TextFixture::read($case['path']);
        $r = (new TextParser(TextFixture::validator()))->extract(TextFixture::source($raw), TextFixture::profile('text'));
        $canonical = $r->normalization->text;
        self::assertSame('<pre>' . $canonical . '</pre>', $r->display(1000));
        self::assertSame('<pre>Прив</pre><p>[truncated]</p>', $r->display(8));
        $hashes = array_map(static fn(string $b): string => hash('sha256', $b), [$raw,$canonical,$r->display(1000),$r->display(8)]);
        self::assertCount(4, array_unique($hashes));
        self::assertSame($case['sourceDigest'], $hashes[0]); self::assertSame($case['expected']['canonicalDigest'], $hashes[1]);
        self::assertSame($canonical, $r->document->toWire()->get('segments')[0]->get('text'));
    }

    public function testMissingNormalizationCannotMasqueradeAsConfirmedEmpty(): void
    {
        $source = TextFixture::source('nonempty'); $profile = TextFixture::profile('text');
        $normal = (new TextParser(TextFixture::validator()))->extract($source, $profile)->normalization;
        foreach ([[null, null], [$normal, 'invalid_utf8']] as [$value, $code]) {
            try {
                new ExtractionResult($source, $profile, $value, [], [], [], $code, TextFixture::validator());
                self::fail('Contradictory extraction state accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Consistent extraction state required', $error->getMessage());
            }
        }
    }

    public function testPresentationSuffixAndHtmlNeverBecomeCanonicalQuotes(): void
    {
        $text = 'alpha & <script>alert(1)</script>';
        $r = (new TextParser(TextFixture::validator()))->extract(TextFixture::source($text), TextFixture::profile('text'));
        $before = $r->document->canonical();
        self::assertStringContainsString('&amp; &lt;script&gt;', $r->display(1000));
        self::assertStringNotContainsString('<script>', $r->display(1000));
        self::assertStringContainsString('[truncated]', $r->display(4));
        self::assertStringNotContainsString('[truncated]', $r->normalization->text);
        self::assertSame($before, $r->document->canonical());
        $span = new D\Span(D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), 0, 5), new D\Sha256(hash('sha256', $text)));
        self::assertSame('alpha', $span->slice($text));
        TextFixture::failure(fn() => $span->slice($r->display(4)), D\Outcome::Incompatible, 'digest_mismatch');
        $this->expectException(ContractViolation::class);
        LocatorConversion::resolveSpan(new JsonObject(['text' => $text, 'byteStart' => strlen($text), 'byteEnd' => strlen($r->display(4)) + strlen($text)]));
    }
}
