<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Text;

use KnowKit\Application\Ingest\Markdown\MarkdownParser;
use KnowKit\Domain\Contract\{JsonObject, LocatorConversion};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:text')]
final class MarkdownTest extends TestCase
{
    public function testVersionedSubsetFixturesPreserveExactCanonicalSyntax(): void
    {
        foreach (TextFixture::manifest('markdown')['cases'] as $case) {
            $raw = TextFixture::read($case['path']); $e = $case['expected'];
            $p = new MarkdownParser(TextFixture::validator());
            $r = $p->extract(TextFixture::source($raw, 'text/markdown'), TextFixture::profile('markdown', $case['options']));
            $wire = $r->document->toWire();
            self::assertSame($case['sourceDigest'], hash('sha256', $raw));
            self::assertSame($case['canonicalDigest'], hash('sha256', $r->normalization->text));
            self::assertSame($raw, $r->normalization->text, $case['id']);
            self::assertSame($e['kinds'], array_map(static fn(JsonObject $b) => $b->get('kind'), $wire->get('blocks')));
            self::assertSame($e['outcome'], $r->outcome->value); self::assertSame($e['coverage'], $wire->get('coverage')->get('state'));
            self::assertEqualsCanonicalizing($e['diagnostics'], array_map(static fn(JsonObject $d) => $d->get('code'), $wire->get('coverage')->get('diagnostics')));
            foreach ($wire->get('blocks') as $i => $b) {
                self::assertSame($i, $b->get('order')); $span = $b->get('spans')[0];
                self::assertSame('segment', $span->get('kind')); self::assertFalse($span->has('physicalNo')); self::assertFalse($span->has('bbox'));
                $ast = $r->sidecar()->get('structure')[$i];
                self::assertSame($b->get('blockId'), $ast->get('blockId'));
                self::assertSame($span->get('byteStart'), $ast->get('byteStart'));
                self::assertSame($span->get('byteEnd'), $ast->get('byteEnd'));
                self::assertNotSame('', LocatorConversion::resolveSpan($span->with('text', $raw))->get('text'));
            }
        }
    }

    public function testHeadingListFenceAndTableStructureHasExactSourceSpans(): void
    {
        $case = TextFixture::manifest('markdown')['cases'][0]; $raw = TextFixture::read($case['path']);
        $r = (new MarkdownParser(TextFixture::validator()))->extract(TextFixture::source($raw, 'text/markdown'), TextFixture::profile('markdown'));
        $blocks = $r->document->toWire()->get('blocks'); $ast = $r->sidecar()->get('structure');
        self::assertSame([null, 'b1', 'b1', 'b3', 'b4', 'b3', 'b3', 'b3'], array_map(static fn(JsonObject $b) => $b->get('parentId'), $blocks));
        self::assertSame(1, $ast[0]->get('level')); self::assertSame(2, $ast[2]->get('level'));
        self::assertSame('list_item', $ast[3]->get('kind')); self::assertFalse($ast[3]->get('ordered'));
        self::assertSame(2, $ast[4]->get('indent')); self::assertSame('2', $ast[5]->get('ordinal'));
        self::assertTrue($ast[5]->get('ordered')); self::assertNotSame($ast[3]->get('listId'), $ast[5]->get('listId'));
        self::assertSame('```', $ast[6]->get('fence')); self::assertSame('php', $ast[6]->get('info')); self::assertTrue($ast[6]->get('closed'));
        self::assertSame("<x> & code\n", substr($raw, $ast[6]->get('contentStart'), $ast[6]->get('contentEnd') - $ast[6]->get('contentStart')));
        self::assertSame(['left', 'right'], $ast[7]->get('alignment'));
        $table = $blocks[7]->get('table'); self::assertSame(3, $table->get('rows')); self::assertSame(2, $table->get('columns'));
        $values = [];
        foreach ($table->get('cells') as $cell) {
            $spans = $cell->get('spans');
            $values[] = $spans === [] ? '' : LocatorConversion::resolveSpan($spans[0]->with('text', $raw))->get('text');
            self::assertSame(1, $cell->get('rowSpan')); self::assertSame(1, $cell->get('colSpan'));
            self::assertSame($cell->get('row') === 0 ? [] : ['b8.r0c' . $cell->get('column')], $cell->get('headerRefs'));
        }
        self::assertSame(['Имя', '值', "e\u{0301}", '42', 'a\\|b', ''], $values);
    }

    public function testSupportedBoundariesAndIncompleteSuffixNeverDisappear(): void
    {
        $p = new MarkdownParser(TextFixture::validator());
        $raw = "#A stays paragraph\n\n   ### Head ###\n\n1. one\n2. two\n\n~~~~ info\n~~~ shorter is content\n~~~~~\n";
        $r = $p->extract(TextFixture::source($raw, 'text/markdown'), TextFixture::profile('markdown'));
        self::assertSame('success', $r->outcome->value); $ast = $r->sidecar()->get('structure');
        self::assertSame('paragraph', $ast[0]->get('kind')); self::assertSame(3, $ast[1]->get('level'));
        self::assertSame($ast[2]->get('listId'), $ast[3]->get('listId'));
        self::assertTrue($ast[4]->get('closed'));
        $case = TextFixture::manifest('markdown')['cases'][4];
        $r = $p->extract(TextFixture::source(TextFixture::read($case['path']), 'text/markdown'), TextFixture::profile('markdown', $case['options']));
        self::assertStringContainsString('# B', $r->normalization->text);
        self::assertSame(['markdown_structure'], $r->document->toWire()->get('coverage')->get('missingFeatures'));
        // Thousands of pipes cannot allocate an unbounded table-cell structure or claim completeness.
        $pipes = str_repeat('|x', 32) . "|\n";
        $r = $p->extract(TextFixture::source($pipes, 'text/markdown'), TextFixture::profile('markdown', ['maxTableCells' => 4]));
        self::assertSame($pipes, $r->normalization->text); self::assertSame('incompatible', $r->outcome->value);
    }
}
