<?php
declare(strict_types=1);

namespace KnowKit\Tests\Artifacts\Pyramid;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Application\Artifacts\AtomicSpanGuard;
use KnowKit\Domain\{
    DocumentRevisionId,
    DocumentRevisionRef,
    DomainFailure,
    ExactSourceRef,
    Fingerprint,
    IrDigest,
    IrDocument,
    LocalId,
    Outcome,
    ParserSignature,
    SourceDigest,
    SourceId,
    SourceVersionId,
    TenantId,
    TextLocator,
};
use KnowKit\Domain\Artifacts\{
    DigestArtifact,
    DigestBlock,
    PropositionItem,
    PropositionSetArtifact,
    ProtectedAtom,
};
use KnowKit\Domain\Contract\{ContractValidator, JsonObject, SchemaSet, StrictJson};
use KnowKit\Port\AtomVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('artifacts:pyramid')]
final class AtomicSpanGuardTest extends TestCase
{
    private function permissiveValidator(): ContractValidator
    {
        $schema = new JsonObject([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'urn:knowkit:schema:document-ir:1.0',
            'type' => 'object',
        ]);
        return new ContractValidator(new SchemaSet(['urn:knowkit:schema:document-ir:1.0' => $schema]));
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

    /** @return array{0: IrDocument, 1: ProtectedAtom, 2: ProtectedAtom, 3: ProtectedAtom} */
    private function sampleIrWithAtoms(): array
    {
        $formula = '$$ E = mc^2 $$';
        $code = 'echo "ok";';
        $atomicText = 'CLAUSE 4.1: STRICT LIABILITY';
        $segText = $formula . "\n" . $code . "\n" . $atomicText;

        $fStart = 0;
        $fEnd = strlen($formula);
        $cStart = $fEnd + 1;
        $cEnd = $cStart + strlen($code);
        $aStart = $cEnd + 1;
        $aEnd = $aStart + strlen($atomicText);

        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'pdf-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [
                ['segmentId' => 'seg-1', 'text' => $segText, 'textDigest' => hash('sha256', $segText), 'status' => 'ok'],
            ],
            'blocks' => [
                [
                    'blockId' => 'b-formula',
                    'kind' => 'formula',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-formula', 'byteStart' => $fStart, 'byteEnd' => $fEnd]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-code',
                    'kind' => 'code',
                    'parentId' => null,
                    'order' => 2,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-code', 'byteStart' => $cStart, 'byteEnd' => $cEnd]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-clause',
                    'kind' => 'text',
                    'parentId' => null,
                    'order' => 3,
                    'origin' => 'source',
                    'metadata' => ['atomic' => true],
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-clause', 'byteStart' => $aStart, 'byteEnd' => $aEnd]],
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
        $ir = new IrDocument($irWire, $this->permissiveValidator());

        return [
            $ir,
            ProtectedAtom::fromContent(new LocalId('b-formula'), 'formula', $formula, TextLocator::segment(new LocalId('seg-1'), new LocalId('b-formula'), $fStart, $fEnd)),
            ProtectedAtom::fromContent(new LocalId('b-code'), 'code', $code, TextLocator::segment(new LocalId('seg-1'), new LocalId('b-code'), $cStart, $cEnd)),
            ProtectedAtom::fromContent(new LocalId('b-clause'), 'atomic', $atomicText, TextLocator::segment(new LocalId('seg-1'), new LocalId('b-clause'), $aStart, $aEnd)),
        ];
    }

    public function testAcceptsExactProtectedAtomsInDigestAndPropositionArtifacts(): void
    {
        [$ir, $fAtom, $cAtom, $aAtom] = $this->sampleIrWithAtoms();
        $guard = new AtomicSpanGuard();
        $rev = $this->revisionRef();

        $extracted = $guard->extractRequiredAtoms($ir);
        self::assertCount(3, $extracted);

        $digest = new DigestArtifact(
            documentRevisionRef: $rev,
            blocks: [
                new DigestBlock(new LocalId('d-1'), 'Energy and code summary', [$fAtom, $cAtom, $aAtom]),
            ],
        );
        $guard->assertPreserved($ir, $digest);

        $props = new PropositionSetArtifact(
            documentRevisionRef: $rev,
            propositions: [
                new PropositionItem(new LocalId('p-1'), 'Formula proposition', [$fAtom]),
                new PropositionItem(new LocalId('p-2'), 'Code and clause', [$cAtom, $aAtom]),
            ],
        );
        $guard->assertPreserved($ir, $props);
        $this->addToAssertionCount(1);
    }

    public function testRejectsMissingOrDistortedAtomWithIncompatibleFailure(): void
    {
        [$ir, $fAtom, $cAtom] = $this->sampleIrWithAtoms();
        $guard = new AtomicSpanGuard();
        $rev = $this->revisionRef();

        $distorted = ProtectedAtom::fromContent(new LocalId('b-clause'), 'atomic', 'CLAUSE 4.1: MODIFIED');
        $digest = new DigestArtifact(
            documentRevisionRef: $rev,
            blocks: [
                new DigestBlock(new LocalId('d-1'), 'Incomplete digest', [$fAtom, $cAtom, $distorted]),
            ],
        );

        try {
            $guard->assertPreserved($ir, $digest);
            self::fail('Expected atom_integrity_failed');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('atom_integrity_failed', $failure->errorCode);
        }
    }

    public function testAcceptsEquivalentAtomViaVerifier(): void
    {
        [$ir, , $cAtom, $aAtom] = $this->sampleIrWithAtoms();
        $guard = new AtomicSpanGuard();
        $rev = $this->revisionRef();

        $normalizedFormula = ProtectedAtom::fromContent(new LocalId('b-formula'), 'formula', '$$E=mc^2$$');
        $digest = new DigestArtifact(
            documentRevisionRef: $rev,
            blocks: [
                new DigestBlock(new LocalId('d-1'), 'Normalized formula', [$normalizedFormula, $cAtom, $aAtom]),
            ],
        );

        $verifier = new class implements AtomVerifier {
            public function equivalent(ProtectedAtom $source, ProtectedAtom $derived): bool
            {
                return preg_replace('/\s+/', '', $source->content) === preg_replace('/\s+/', '', $derived->content);
            }
        };

        $guard->assertPreserved($ir, $digest, $verifier);
        $this->addToAssertionCount(1);
    }

    /** @return array{0: IrDocument, 1: TextLocator} */
    private function singleFormulaIr(string $formula = 'E = mc^2'): array
    {
        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'pdf-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [
                ['segmentId' => 'seg-1', 'text' => $formula, 'textDigest' => hash('sha256', $formula), 'status' => 'ok'],
            ],
            'blocks' => [
                [
                    'blockId' => 'b-formula',
                    'kind' => 'formula',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-formula', 'byteStart' => 0, 'byteEnd' => strlen($formula)]],
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

        return [
            new IrDocument($irWire, $this->permissiveValidator()),
            TextLocator::segment(new LocalId('seg-1'), new LocalId('b-formula'), 0, strlen($formula)),
        ];
    }

    public function testRejectsSubstringBypassWithEmptyVerbatimAtoms(): void
    {
        [$ir, $formulaLoc] = $this->singleFormulaIr('E = mc^2');
        $guard = new AtomicSpanGuard();
        $digest = new DigestArtifact(
            documentRevisionRef: $this->revisionRef(),
            blocks: [
                new DigestBlock(new LocalId('d-1'), 'Wrong: NOT E = mc^20 + 999', [], [$formulaLoc]),
            ],
        );

        try {
            $guard->assertPreserved($ir, $digest);
            self::fail('Expected atom_integrity_failed');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('atom_integrity_failed', $failure->errorCode);
        }
    }

    public function testRejectsCrossBlockDecouplingBypass(): void
    {
        [$ir, $formulaLoc] = $this->singleFormulaIr('E = mc^2');
        $guard = new AtomicSpanGuard();
        $digest = new DigestArtifact(
            documentRevisionRef: $this->revisionRef(),
            blocks: [
                new DigestBlock(new LocalId('d-1'), 'Unrelated prose mentioning E = mc^2', [], []),
                new DigestBlock(new LocalId('d-2'), 'Formula is E = mc^99', [], [$formulaLoc]),
            ],
        );

        try {
            $guard->assertPreserved($ir, $digest);
            self::fail('Expected atom_integrity_failed');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('atom_integrity_failed', $failure->errorCode);
        }
    }

    public function testRejectsStructuredTableCellTampering(): void
    {
        $rawTableText = 'Revenue | 100';
        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'pdf-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [
                ['segmentId' => 'seg-1', 'text' => $rawTableText, 'textDigest' => hash('sha256', $rawTableText), 'status' => 'ok'],
            ],
            'blocks' => [
                [
                    'blockId' => 'b-table',
                    'kind' => 'table',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-table', 'byteStart' => 0, 'byteEnd' => strlen($rawTableText)]],
                    'provenance' => ['kind' => 'table_representation', 'supportingRefs' => []],
                    'table' => [
                        'rows' => 1,
                        'columns' => 2,
                        'cells' => [
                            ['cellId' => 'c1', 'row' => 0, 'column' => 0, 'rowSpan' => 1, 'colSpan' => 1, 'header' => true, 'headerRefs' => [], 'unit' => null, 'noteRefs' => [], 'spans' => []],
                            ['cellId' => 'c2', 'row' => 0, 'column' => 1, 'rowSpan' => 1, 'colSpan' => 1, 'header' => false, 'headerRefs' => ['c1'], 'unit' => 'USD', 'noteRefs' => [], 'spans' => []],
                        ],
                    ],
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

        $ir = new IrDocument($irWire, $this->permissiveValidator());
        $guard = new AtomicSpanGuard();
        $extracted = $guard->extractRequiredAtoms($ir);
        self::assertCount(1, $extracted);

        $validDigest = new DigestArtifact(
            documentRevisionRef: $this->revisionRef(),
            blocks: [new DigestBlock(new LocalId('d-1'), 'Table summary', [$extracted[0]])],
        );
        $guard->assertPreserved($ir, $validDigest);

        $tamperedAtom = ProtectedAtom::fromContent(new LocalId('b-table'), 'table', $rawTableText);
        $tamperedDigest = new DigestArtifact(
            documentRevisionRef: $this->revisionRef(),
            blocks: [new DigestBlock(new LocalId('d-1'), 'Table summary', [$tamperedAtom])],
        );

        try {
            $guard->assertPreserved($ir, $tamperedDigest);
            self::fail('Expected atom_integrity_failed');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('atom_integrity_failed', $failure->errorCode);
        }
    }
}
