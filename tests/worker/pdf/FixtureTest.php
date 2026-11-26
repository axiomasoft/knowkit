<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Pdf;

use KnowKit\Application\Ingest\Markdown\MarkdownParser;
use KnowKit\Application\Ingest\Text\TextParser;
use KnowKit\Domain\Contract\LocatorConversion;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\IrDocument;
use KnowKit\Tests\Ingest\Text\TextFixture;
use KnowKit\Tests\Worker\Protocol\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/protocol/bootstrap.php';
require_once Fixture::root() . '/tests/ingest/text/bootstrap.php';
require_once Fixture::root() . '/tools/qa.php';

#[Group('worker:pdf')]
final class FixtureTest extends TestCase
{
    public function testRealCorpusDigestsAndNativeIrPassIndependentPhpValidation(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/worker-pdf.json');
        $qa->fixtures($manifest);
        $corpus = $qa->json('tests/fixtures/documents/pdf/v1/manifest.json');
        self::assertSame('1.1.0', $corpus['fixtureVersion']);
        foreach ($corpus['expected'] as $name => $expected) {
            $bytes = file_get_contents(Fixture::root() . "/tests/fixtures/documents/pdf/v1/{$name}.ir.json");
            self::assertIsString($bytes);
            $ir = new IrDocument(StrictJson::decode($bytes), Fixture::validator());
            self::assertSame($bytes, $ir->canonical());
            self::assertSame($expected['irDigest'], hash('sha256', $ir->canonical()));
            self::assertCount(count($expected['pageTextDigests']), $ir->pages());
            self::assertSame([], $ir->segments());
        }
    }

    public function testNativeRequiredCasesCannotBeMissingSkippedOrEmpty(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/worker-pdf.json');
        foreach (['R13-A01', 'R13-A02', 'R13-A03', 'DT-I04'] as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']);
            self::assertNotEmpty($manifest['testCaseMap'][$id]);
        }
        $cases = [];
        foreach ($manifest['testCaseMap'] as $ids) {
            foreach ($ids as $id) {
                $cases[$id] = ['id' => $id, 'status' => 'passed'];
            }
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(array_values($cases), $manifest));
        array_pop($cases);
        $this->expectException(\RuntimeException::class);
        Qa::validateCases(array_values($cases), $manifest);
    }

    public function testVisualPageCannotClaimOkWithoutText(): void
    {
        $path = Fixture::root() . '/tests/fixtures/documents/pdf/v1/coverage.ir.json';
        $ir = StrictJson::decode(file_get_contents($path));
        $pages = $ir->get('pages');
        $pages[1] = $pages[1]->with('status', 'ok');
        $this->expectException(ContractViolation::class);
        new IrDocument($ir->with('pages', $pages), Fixture::validator());
    }

    public function testTxtAndMarkdownResolveExactSegmentsWithoutPdfPages(): void
    {
        $raw = "# Heading\n\nParagraph Привет 中文.\n";
        $parsers = [
            'text' => new TextParser(TextFixture::validator()),
            'markdown' => new MarkdownParser(TextFixture::validator()),
        ];
        foreach ($parsers as $format => $parser) {
            $media = $format === 'text' ? 'text/plain' : 'text/markdown';
            $result = $parser->extract(TextFixture::source($raw, $media), TextFixture::profile($format));
            $wire = $result->document->toWire();
            self::assertSame([], $wire->get('pages'));
            foreach ($wire->get('blocks') as $block) {
                foreach ($block->get('spans') as $span) {
                    self::assertSame('segment', $span->get('kind'));
                    self::assertFalse($span->has('physicalNo'));
                    self::assertFalse($span->has('bbox'));
                    $resolved = LocatorConversion::resolveSpan($span->with('text', $raw));
                    self::assertSame(substr($raw, $span->get('byteStart'), $span->get('byteEnd') - $span->get('byteStart')), $resolved->get('text'));
                }
            }
        }
    }
}
