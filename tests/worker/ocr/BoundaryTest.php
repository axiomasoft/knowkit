<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Ocr;

use KnowKit\Application\Ingest\Markdown\MarkdownParser;
use KnowKit\Application\Ingest\Text\TextParser;
use KnowKit\Domain as D;
use KnowKit\Tests\Ingest\Text\TextFixture;
use KnowKit\Tests\Worker\Protocol\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OcrFixture.php';
require_once Fixture::root() . '/tests/ingest/text/bootstrap.php';

#[Group('worker:ocr')]
final class BoundaryTest extends TestCase
{
    public function testPageLimitAcceptsOnlyAnExplicitlySkippedOriginalPageSuffix(): void
    {
        $ir = OcrFixture::document('page-limit');
        $signature = $ir->toWire()->get('parserSignature');
        $job = Fixture::job(file_get_contents(OcrFixture::corpus() . '/mixed.pdf'), [
            'parserProfile' => 'docling-pdf-ocr-eng-v1',
            'parserSignature' => $signature,
            'profileFingerprint' => $signature->get('profileDigest'),
            'limits' => Fixture::wire('envelope')->get('limits')->with('maxPages', 2),
        ]);
        $job->assertIr($ir);
        self::assertCount(6, $ir->pages());
        self::assertSame(4, $ir->toWire()->get('coverage')->get('counts')->get('skipped'));
        foreach (array_slice($ir->pages(), 2) as $page) {
            self::assertSame('skipped', $page->get('status'));
            self::assertSame('', $page->get('text'));
        }
        $this->expectExceptionMessage('output_limit');
        (new D\JobEnvelope(
            $job->toWire()->with('limits', $job->toWire()->get('limits')->with('maxPages', 1)),
            Fixture::validator(),
        ))->assertIr($ir);
    }

    public function testNonpaginatedLocatorsKeepExactSegmentsWithoutPdfGeometry(): void
    {
        $text = "# Heading\n\nParagraph Привет 中文.\n";
        foreach (['text' => new TextParser(TextFixture::validator()), 'markdown' => new MarkdownParser(TextFixture::validator())] as $format => $parser) {
            $source = TextFixture::source($text, $format === 'text' ? 'text/plain' : 'text/markdown');
            $document = $parser->extract($source, TextFixture::profile($format))->document;
            self::assertSame([], $document->pages());
            foreach ($document->toWire()->get('blocks') as $block) {
                foreach ($block->get('spans') as $span) {
                    self::assertSame('segment', $span->get('kind'));
                    self::assertFalse($span->has('bbox'));
                    self::assertFalse($span->has('physicalNo'));
                }
            }
        }
        // The same native locator contract supports EPUB chapter/anchor/paragraph
        // identities without claiming that this OCR task implements an EPUB importer.
        $locator = D\TextLocator::segment(new D\LocalId('chapter-1:anchor-intro:paragraph-2'), new D\LocalId('block-1'), 11, 20);
        self::assertSame('Paragraph', $locator->slice($text));
        self::assertNull($locator->physicalNo());
        self::assertFalse($locator->toWire()->has('bbox'));
    }

    public function testInvalidConfidenceAndAbbreviatedModelFingerprintsAreRejected(): void
    {
        $id = OcrFixture::firstOcrId(OcrFixture::document('approved-eng'));
        $valid = D\OcrMetadata::fromBlockId($id);
        foreach ([
            preg_replace('/conf-[0-9]+$/', 'conf-1001', $id->value),
            str_replace($valid->modelFingerprint->value, '7e548e6e', $id->value),
        ] as $invalid) {
            try {
                D\OcrMetadata::fromBlockId(new D\LocalId($invalid));
                self::fail('Invalid OCR metadata accepted');
            } catch (D\DomainFailure $failure) {
                self::assertSame('invalid_ocr_metadata', $failure->errorCode);
            }
        }
    }
}
