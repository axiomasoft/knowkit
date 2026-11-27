<?php
declare(strict_types=1);

namespace KnowKit\Tests\Artifacts\Pyramid;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Application\Artifacts\DeterministicOutlineBuilder;
use KnowKit\Domain\{
    DocumentRevisionId,
    DocumentRevisionRef,
    ExactSourceRef,
    IrDigest,
    IrDocument,
    ParserSignature,
    SourceDigest,
    SourceId,
    SourceVersionId,
    TenantId,
    Fingerprint,
};
use KnowKit\Domain\Contract\{ContractValidator, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\Layers\ResolutionTier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('artifacts:pyramid')]
final class DeterministicOutlineTest extends TestCase
{
    private function validator(): ContractValidator
    {
        static $validator = null;
        if ($validator !== null) {
            return $validator;
        }
        $root = dirname(__DIR__, 3);
        $schemas = [];
        foreach (['common', 'document-ir'] as $name) {
            $doc = StrictJson::decode((string) file_get_contents($root . '/schemas/' . $name . '.schema.json'), 1048576);
            $schemas[(string) $doc->get('$id')] = $doc;
        }
        return $validator = new ContractValidator(new SchemaSet($schemas));
    }

    private function revisionRef(): DocumentRevisionRef
    {
        return new DocumentRevisionRef(
            new ExactSourceRef(
                new TenantId('01JSC420000000000000000000'),
                new SourceId('01JSSR00000000000000000001'),
                new SourceVersionId('01JSSV00000000000000000001'),
                new SourceDigest(str_repeat('a', 64)),
            ),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(str_repeat('b', 64)),
            new ParserSignature('pdf-parser', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );
    }

    public function testBuildsDeterministicHierarchyWithinTwoPercentBudget(): void
    {
        $h1 = 'Chapter 1. Classical Mechanics';
        $p1 = str_repeat('Momentum conservation in an isolated inertial system governs elastic and inelastic collisions. ', 350);
        $f1 = '$$ \sum_{i=1}^{n} m_i \vec{v}_i = \sum_{i=1}^{n} m_i \vec{v}_i\' $$';
        $h2 = '1.1 Kinetic Energy and Work';
        $p2 = str_repeat('Kinetic energy transforms into thermal energy during inelastic deformation of colliding bodies. ', 350);
        $c1 = 'function kineticEnergy(m, v) { return 0.5 * m * v * v; }';
        $t1 = '| Mass | Velocity | Momentum |';

        $page1Text = $h1 . "\n" . $p1 . "\n" . $f1 . "\n";
        $page2Text = $h2 . "\n" . $p2 . "\n" . $c1 . "\n" . $t1 . "\n";

        $h1Start = 0;
        $h1End = strlen($h1);
        $p1Start = $h1End + 1;
        $p1End = $p1Start + strlen($p1);
        $f1Start = $p1End + 1;
        $f1End = $f1Start + strlen($f1);

        $h2Start = 0;
        $h2End = strlen($h2);
        $p2Start = $h2End + 1;
        $p2End = $p2Start + strlen($p2);
        $c1Start = $p2End + 1;
        $c1End = $c1Start + strlen($c1);
        $t1Start = $c1End + 1;
        $t1End = $t1Start + strlen($t1);

        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'pdf-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [
                [
                    'pageId' => 'page-1',
                    'physicalNo' => 1,
                    'printedLabel' => 'p.1',
                    'text' => $page1Text,
                    'textDigest' => hash('sha256', $page1Text),
                    'status' => 'ok',
                ],
                [
                    'pageId' => 'page-2',
                    'physicalNo' => 2,
                    'printedLabel' => 'p.2',
                    'text' => $page2Text,
                    'textDigest' => hash('sha256', $page2Text),
                    'status' => 'ok',
                ],
            ],
            'segments' => [],
            'blocks' => [
                [
                    'blockId' => 'b-h1',
                    'kind' => 'heading',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'page', 'pageId' => 'page-1', 'physicalNo' => 1, 'printedLabel' => 'p.1', 'blockId' => 'b-h1', 'byteStart' => $h1Start, 'byteEnd' => $h1End]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-p1',
                    'kind' => 'text',
                    'parentId' => 'b-h1',
                    'order' => 2,
                    'origin' => 'source',
                    'spans' => [['kind' => 'page', 'pageId' => 'page-1', 'physicalNo' => 1, 'printedLabel' => 'p.1', 'blockId' => 'b-p1', 'byteStart' => $p1Start, 'byteEnd' => $p1End]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-f1',
                    'kind' => 'formula',
                    'parentId' => 'b-h1',
                    'order' => 3,
                    'origin' => 'source',
                    'spans' => [['kind' => 'page', 'pageId' => 'page-1', 'physicalNo' => 1, 'printedLabel' => 'p.1', 'blockId' => 'b-f1', 'byteStart' => $f1Start, 'byteEnd' => $f1End]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-h2',
                    'kind' => 'heading',
                    'parentId' => 'b-h1',
                    'order' => 4,
                    'origin' => 'source',
                    'spans' => [['kind' => 'page', 'pageId' => 'page-2', 'physicalNo' => 2, 'printedLabel' => 'p.2', 'blockId' => 'b-h2', 'byteStart' => $h2Start, 'byteEnd' => $h2End]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-p2',
                    'kind' => 'text',
                    'parentId' => 'b-h2',
                    'order' => 5,
                    'origin' => 'source',
                    'spans' => [['kind' => 'page', 'pageId' => 'page-2', 'physicalNo' => 2, 'printedLabel' => 'p.2', 'blockId' => 'b-p2', 'byteStart' => $p2Start, 'byteEnd' => $p2End]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-c1',
                    'kind' => 'code',
                    'parentId' => 'b-h2',
                    'order' => 6,
                    'origin' => 'source',
                    'spans' => [['kind' => 'page', 'pageId' => 'page-2', 'physicalNo' => 2, 'printedLabel' => 'p.2', 'blockId' => 'b-c1', 'byteStart' => $c1Start, 'byteEnd' => $c1End]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-t1',
                    'kind' => 'table',
                    'parentId' => 'b-h2',
                    'order' => 7,
                    'origin' => 'source',
                    'spans' => [['kind' => 'page', 'pageId' => 'page-2', 'physicalNo' => 2, 'printedLabel' => 'p.2', 'blockId' => 'b-t1', 'byteStart' => $t1Start, 'byteEnd' => $t1End]],
                    'provenance' => ['kind' => 'table_representation', 'supportingRefs' => []],
                    'table' => [
                        'rows' => 1,
                        'columns' => 3,
                        'cells' => [
                            ['cellId' => 'c1', 'row' => 0, 'column' => 0, 'rowSpan' => 1, 'colSpan' => 1, 'header' => true, 'headerRefs' => [], 'unit' => null, 'noteRefs' => [], 'spans' => []],
                        ],
                    ],
                ],
            ],
            'assets' => [],
            'coverage' => [
                'state' => 'complete',
                'counts' => ['expected' => 2, 'processed' => 2, 'empty' => 0, 'failed' => 0, 'skipped' => 0],
                'missingFeatures' => [],
                'diagnostics' => [],
            ],
        ], JSON_THROW_ON_ERROR));
        \assert($irWire instanceof JsonObject);
        $ir = new IrDocument($irWire, $this->validator());

        $builder = new DeterministicOutlineBuilder();
        $rev = $this->revisionRef();
        $outlineA = $builder->build($ir, $rev);
        $outlineB = $builder->build($ir, $rev);

        self::assertSame(ResolutionTier::R0Outline, $outlineA->tier);
        self::assertTrue($outlineA->digest()->equals($outlineB->digest()));
        self::assertCount(2, $outlineA->nodes);

        $n1 = $outlineA->nodes[0];
        $n2 = $outlineA->nodes[1];
        self::assertSame('b-h1', $n1->nodeId->value);
        self::assertNull($n1->parentId);
        self::assertSame(0, $n1->depth);
        self::assertSame(1, $n1->formulaCount);
        self::assertSame(0, $n1->codeCount);
        self::assertSame(0, $n1->tableCount);
        self::assertSame(['p.1'], $n1->unitRange);
        self::assertLessThanOrEqual(8, count($n1->terms));
        self::assertContains('momentum', $n1->terms);

        self::assertSame('b-h2', $n2->nodeId->value);
        self::assertSame('b-h1', $n2->parentId?->value);
        self::assertSame(1, $n2->depth);
        self::assertSame(0, $n2->formulaCount);
        self::assertSame(1, $n2->codeCount);
        self::assertSame(1, $n2->tableCount);
        self::assertSame(['p.2'], $n2->unitRange);

        self::assertGreaterThan(0, $outlineA->sourceByteLength);
        self::assertLessThanOrEqual((int) floor($outlineA->sourceByteLength * 0.02), $outlineA->byteLength());
    }

    public function testBuildsFallbackNodeWhenNoHeadingsExist(): void
    {
        $segText = 'Standalone paragraph with key concepts about thermodynamics and entropy.';
        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'txt-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [
                ['segmentId' => 'seg-1', 'text' => $segText, 'textDigest' => hash('sha256', $segText), 'status' => 'ok'],
            ],
            'blocks' => [
                [
                    'blockId' => 'b-1',
                    'kind' => 'text',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-1', 'byteStart' => 0, 'byteEnd' => strlen($segText)]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
            ],
            'assets' => [],
            'coverage' => [
                'state' => 'complete',
                'counts' => ['expected' => 1, 'processed' => 1, 'empty' => 0, 'failed' => 0, 'skipped' => 0],
                'missingFeatures' => [],
                'diagnostics' => [],
            ],
        ], JSON_THROW_ON_ERROR));
        \assert($irWire instanceof JsonObject);
        $ir = new IrDocument($irWire, $this->validator());

        $outline = (new DeterministicOutlineBuilder())->build($ir, $this->revisionRef());
        self::assertCount(1, $outline->nodes);
        self::assertSame('doc-root', $outline->nodes[0]->nodeId->value);
        self::assertSame(['seg-1'], $outline->nodes[0]->unitRange);
        self::assertContains('thermodynamics', $outline->nodes[0]->terms);
    }
}
