<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Structure;

use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\LocalId;
use KnowKit\Tests\Worker\Protocol\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:structure')]
final class FixtureTest extends TestCase
{
    public function testVersionedDigestsAndExactIrRoundTripRetainDiagnosticsAndRelationships(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/documents/structure/v1/manifest.json');
        self::assertSame('1.0.0', $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['rights']['origin']);
        foreach ($manifest['fixtures'] as $entry) {
            self::assertNotEmpty($entry['expected']);
            self::assertSame($entry['sha256'], hash_file('sha256', Fixture::root() . '/' . $entry['path']));
        }
        $qa->fixtures($qa->json('tests/fixtures/check-groups/worker-structure.json'));
        foreach (['structured', 'unpaginated', 'uncertain-ocr'] as $name) {
            $ir = StructureFixture::ir($name);
            self::assertSame(file_get_contents(StructureFixture::path("{$name}.ir.json")), $ir->canonical());
            $copy = new IrDocument($ir->toWire(), Fixture::validator());
            self::assertSame($ir->canonical(), $copy->canonical());
            self::assertEquals($ir->toWire()->get('coverage'), $copy->toWire()->get('coverage'));
        }
        $ir = StructureFixture::ir();
        self::assertSame($manifest['expected']['irDigest'], hash('sha256', $ir->canonical()));
        $table = $ir->block(new LocalId('table'));
        self::assertSame('heading', $table->get('parentId'));
        self::assertSame([1, 2], array_map(static fn ($span) => $span->get('physicalNo'), $table->get('spans')));
        self::assertSame(6, $table->get('table')->get('rows'));
        self::assertCount(10, $table->get('table')->get('cells'));
        self::assertSame(2, $table->get('table')->get('cells')[0]->get('colSpan'));
        self::assertSame(2, $table->get('table')->get('cells')[3]->get('rowSpan'));
        self::assertSame('source', $ir->block(new LocalId('caption'))->get('origin'));
        self::assertSame('generated', $ir->block(new LocalId('description'))->get('origin'));
    }

    public function testFormulaConfidenceAndUnknownSymbolsAreAccessibleWithoutSchemaExtension(): void
    {
        $ir = StructureFixture::ir();
        $metadata = $ir->formulaMetadata(new LocalId('equation'));
        self::assertSame(250, $metadata->confidence);
        self::assertSame('E = m � c²', $metadata->representation);
        self::assertSame('page-millionths-top-left-v1', $metadata->regions[0]->get('bbox')->get('space'));
        self::assertSame('E = m � c²', StructureFixture::ir('unpaginated')->formulaMetadata(new LocalId('equation'))->representation);
        $coverage = $ir->toWire()->get('coverage');
        self::assertSame('partial', $coverage->get('state'));
        self::assertContains('formula_transcription', $coverage->get('missingFeatures'));
        foreach (['unknown' => null, '0' => 0] as $value => $expected) {
            $diagnostics = $coverage->get('diagnostics');
            $code = $diagnostics[0]->get('code');
            $diagnostics[0] = $diagnostics[0]->with('code', substr($code, 0, strrpos($code, '_') + 1) . $value);
            $copy = new IrDocument($ir->toWire()->with('coverage', $coverage->with('diagnostics', $diagnostics)), Fixture::validator());
            self::assertSame($expected, $copy->formulaMetadata(new LocalId('equation'))->confidence);
        }
    }

    public function testDuplicateFormulaConfidenceIsRejectedByTypedReader(): void
    {
        $ir = StructureFixture::ir();
        $coverage = $ir->toWire()->get('coverage');
        $diagnostics = $coverage->get('diagnostics');
        $diagnostics[] = $diagnostics[0];
        $copy = new IrDocument($ir->toWire()->with('coverage', $coverage->with('diagnostics', $diagnostics)), Fixture::validator());
        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('formula_metadata_invalid');
        $copy->formulaMetadata(new LocalId('equation'));
    }

    public function testUnpaginatedStructureDoesNotAcquirePagesOrGeometry(): void
    {
        $ir = StructureFixture::ir('unpaginated');
        self::assertSame([], $ir->pages());
        foreach ($ir->toWire()->get('blocks') as $block) {
            foreach ($block->get('spans') as $span) {
                self::assertSame('segment', $span->get('kind'));
                self::assertFalse($span->has('physicalNo'));
                self::assertFalse($span->has('bbox'));
            }
        }
        $blocks = $ir->toWire()->get('blocks');
        $spans = $blocks[0]->get('spans');
        $spans[0] = $spans[0]->with('physicalNo', 1);
        $blocks[0] = $blocks[0]->with('spans', $spans);
        $this->expectException(ContractViolation::class);
        new IrDocument($ir->toWire()->with('blocks', $blocks), Fixture::validator());
    }

    public function testRequiredCaseManifestRejectsMissingSkippedFailedEmptyAndDuplicateReports(): void
    {
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/worker-structure.json');
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        $cases = array_values($cases);
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases($cases, $manifest));
        $missing = array_slice($cases, 1);
        $skipped = $failed = $cases;
        $skipped[0]['status'] = 'skipped';
        $failed[0]['status'] = 'failed';
        foreach ([[], $missing, $skipped, $failed, [...$cases, $cases[0]]] as $invalid) {
            try {
                Qa::validateCases($invalid, $manifest);
                self::fail('Invalid executed-case report accepted');
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }
}
