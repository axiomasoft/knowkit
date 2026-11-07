<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Text;

use KnowKit\Application\Ingest\Markdown\MarkdownParser;
use KnowKit\Application\Ingest\Text\TextParser;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{JsonObject, LocatorConversion};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

/** Synthetic contract-boundary examples, NOT PDF/OCR/EPUB/search adapter qualification. */
#[Group('ingest:text')]
final class AuditTest extends TestCase
{
    public function testPdfOffsetContractUsesCanonicalTextNotOriginalByteCoordinates(): void
    {
        $f = TextFixture::manifest('markdown')['auditContracts'];
        self::assertFalse($f['externalAdaptersImplemented']);
        $ir = new D\IrDocument(TextFixture::json($f['canonicalPageIr']), TextFixture::validator());
        $page = $ir->toWire()->get('pages')[0]; $span = $ir->toWire()->get('blocks')[0]->get('spans')[0];
        self::assertSame('ﬁ', LocatorConversion::resolveSpan($span->with('text', $page->get('text')))->get('text'));
        self::assertSame(hash('sha256', $page->get('text')), $page->get('textDigest'));
        self::assertSame($f['originalDigest'], hash('sha256', $f['syntheticOriginalBytes']));
        self::assertNotSame($f['originalDigest'], $page->get('textDigest'));
        self::assertNotSame('ﬁ', substr($f['syntheticOriginalBytes'], $span->get('byteStart'), $span->get('byteEnd') - $span->get('byteStart')));
    }

    public function testNormalizedSearchProjectionContractKeepsExplicitCanonicalMapping(): void
    {
        $f = TextFixture::manifest('markdown')['auditContracts']['normalizedSearch'];
        self::assertSame('é', $f['normalized']);
        self::assertSame("e\u{0301}", $f['canonical']);
        $m = $f['mapping'];
        self::assertSame(strlen($f['normalized']), $m['normalizedEnd']);
        self::assertSame(strlen($f['canonical']), $m['canonicalEnd']);
        $locator = D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), $m['canonicalStart'], $m['canonicalEnd']);
        self::assertSame($f['canonical'], $locator->slice($f['canonical']));
        self::assertNotSame($f['canonical'], substr($f['canonical'], $m['normalizedStart'], $m['normalizedEnd']));
        self::assertNull($locator->physicalNo());
    }

    public function testNonpaginatedHeadingAndParagraphLocatorsResolveWithoutGeometry(): void
    {
        $p = new MarkdownParser(TextFixture::validator()); $text = "# 漢字\n\nТекст\n";
        $r = $p->extract(TextFixture::source($text, 'text/markdown'), TextFixture::profile('markdown'));
        $w = $r->document->toWire(); self::assertSame([], $w->get('pages'));
        foreach ($w->get('blocks') as $i => $block) {
            $span = $block->get('spans')[0];
            self::assertSame($i === 0 ? "# 漢字\n" : "Текст\n", LocatorConversion::resolveSpan($span->with('text',$text))->get('text'));
            foreach (['pageId','physicalNo','printedLabel','bbox'] as $key) { self::assertFalse($span->has($key)); }
        }
        $span = $w->get('blocks')[0]->get('spans')[0]->with('physicalNo', 1);
        $badBlock = $w->get('blocks')[0]->with('spans', [$span]);
        $bad = $w->with('blocks', [$badBlock, $w->get('blocks')[1]]);
        self::assertNotSame([], TextFixture::validator()->validateValue($bad, 'urn:knowkit:schema:document-ir:1.0'));
        self::assertCount(1, (new TextParser(TextFixture::validator()))->parse(TextFixture::source("a\fb"), TextFixture::profile('text'))->toWire()->get('segments'));
    }

    public function testBlankFailedAndVisualUnknownContractsStayDistinctWithoutDummyText(): void
    {
        $p = new TextParser(TextFixture::validator()); $profile = TextFixture::profile('text');
        $empty = $p->extract(TextFixture::source(''), $profile);
        $failed = $p->extract(TextFixture::source("\xff"), $profile);
        self::assertSame('empty', $empty->outcome->value); self::assertSame('complete', $empty->document->toWire()->get('coverage')->get('state'));
        self::assertSame('incompatible', $failed->outcome->value); self::assertSame(1, $failed->document->toWire()->get('coverage')->get('counts')->get('failed'));
        foreach (TextFixture::manifest('markdown')['auditContracts']['scanStates'] as $case) {
            $ir = new D\IrDocument(TextFixture::json($case['ir']), TextFixture::validator()); $w = $ir->toWire();
            self::assertSame('', $w->get('pages')[0]->get('text')); self::assertSame([], $w->get('blocks'));
            self::assertSame($case['status'], $w->get('pages')[0]->get('status'));
            self::assertSame($case['coverage'], $w->get('coverage')->get('state'));
            if ($case['status'] === 'empty') { self::assertContains('visual_inventory', $w->get('coverage')->get('missingFeatures')); }
        }
    }

    public function testUnsupportedPdfNeverClaimsReadingOrderOrCompleteness(): void
    {
        foreach (['text' => new TextParser(TextFixture::validator()), 'markdown' => new MarkdownParser(TextFixture::validator())] as $format => $parser) {
            $r = $parser->extract(TextFixture::source('%PDF synthetic rotated two-column table', 'application/pdf'), TextFixture::profile($format));
            self::assertSame('unsupported_media_type', $r->errorCode); self::assertSame('incompatible', $r->outcome->value);
            $w = $r->document->toWire(); self::assertSame([], $w->get('pages')); self::assertSame([], $w->get('blocks'));
            self::assertSame('partial', $w->get('coverage')->get('state'));
            self::assertSame(1, $w->get('coverage')->get('counts')->get('skipped'));
            self::assertSame(['canonical_text'], $w->get('coverage')->get('missingFeatures'));
        }
        $f = TextFixture::manifest('markdown')['auditContracts']['incompleteMulticolumnIr'];
        $ir = new D\IrDocument(TextFixture::json($f), TextFixture::validator()); $w = $ir->toWire();
        self::assertSame('partial', $w->get('coverage')->get('state'));
        self::assertContains('reading_order', $w->get('coverage')->get('missingFeatures'));
        self::assertSame([1,2], array_map(static fn(JsonObject $p) => $p->get('physicalNo'), $w->get('pages')));
        $spans = $w->get('blocks')[0]->get('spans');
        self::assertSame(['p1','p2'], array_map(static fn(JsonObject $s) => $s->get('pageId'), $spans));
    }
}
