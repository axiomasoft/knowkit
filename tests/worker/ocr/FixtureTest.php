<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Ocr;

use KnowKit\Adapter\ProcessWorker\OcrMetadataReader;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\LocalId;
use KnowKit\Tests\Worker\Protocol\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OcrFixture.php';
require_once Fixture::root() . '/tools/qa.php';

#[Group('worker:ocr')]
final class FixtureTest extends TestCase
{
    public function testVersionedRealCorpusAndMetadataPassIndependentPhpValidation(): void
    {
        $qa = new Qa(Fixture::root());
        $qa->fixtures($qa->json('tests/fixtures/check-groups/worker-ocr.json'));
        $manifest = $qa->json('tests/fixtures/documents/scans/v1/manifest.json');
        self::assertSame('1.5.0', $manifest['fixtureVersion']);
        foreach ($manifest['expected'] as $name => $expected) {
            $document = OcrFixture::document($name);
            self::assertSame($expected['irDigest'], hash('sha256', $document->canonical()));
            self::assertSame(file_get_contents(OcrFixture::corpus() . "/{$name}.ir.json"), $document->canonical());
            self::assertSame($expected['outcome'], $document->toWire()->get('coverage')->get('state'));
            foreach ($document->toWire()->get('blocks') as $block) {
                if ($block->get('origin') === 'ocr') {
                    $metadata = (new OcrMetadataReader())->forBlock($document, new LocalId($block->get('blockId')));
                    self::assertSame($expected['language'], $metadata->language);
                    self::assertGreaterThanOrEqual(0, $metadata->confidence);
                    self::assertLessThanOrEqual(1000, $metadata->confidence);
                }
            }
        }
    }

    public function testMetadataReaderRejectsFalsePageAndMissingOriginalPreview(): void
    {
        $document = OcrFixture::document('approved-eng');
        $id = OcrFixture::firstOcrId($document);
        foreach (['false-page', 'missing-preview'] as $case) {
            $candidateId = $id;
            $blocks = $document->toWire()->get('blocks');
            foreach ($blocks as $index => $block) {
                if ($block->get('blockId') !== $id->value) {
                    continue;
                }
                if ($case === 'missing-preview') {
                    $blocks[$index] = $block->with('provenance', $block->get('provenance')->with('supportingRefs', []));
                    continue;
                }
                $changedId = str_replace('ocr-p1-', 'ocr-p2-', $id->value);
                $candidateId = new LocalId($changedId);
                $spans = [$block->get('spans')[0]->with('blockId', $changedId)];
                $blocks[$index] = $block->with('blockId', $changedId)->with('spans', $spans);
            }
            $changed = new IrDocument($document->toWire()->with('blocks', $blocks), Fixture::validator());
            $this->assertMetadataRejected($changed, $candidateId);
        }
    }

    private function assertMetadataRejected(IrDocument $document, LocalId $id): void
    {
        try {
            (new OcrMetadataReader())->forBlock($document, $id);
            self::fail('Forged OCR provenance was accepted');
        } catch (DomainFailure $failure) {
            self::assertContains($failure->errorCode, ['invalid_ocr_locator', 'ocr_preview_missing']);
        }
    }

    public function testRequiredCasesFailForAbsentSkippedOrEmptyExecutions(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/worker-ocr.json');
        foreach (['R05-A01', 'R05-A02', 'R13-A01', 'R13-A02', 'R13-A03', 'DT-I05-OCR'] as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']);
        }
        foreach ([[], [['id' => 'absent', 'status' => 'passed']], [['id' => 'absent', 'status' => 'skipped']]] as $cases) {
            try {
                Qa::validateCases($cases, $manifest);
                self::fail('Incomplete OCR execution report accepted');
            } catch (\RuntimeException $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }
}
