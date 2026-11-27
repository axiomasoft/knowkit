<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Pruning;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Application\Retrieval\Pruning\SubspanProjector;
use KnowKit\Domain\Chunk;
use KnowKit\Domain\ChunkId;
use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\ChunkSetId;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\DocumentId;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\ExactSourceRef;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\IrDigest;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ParserSignature;
use KnowKit\Domain\Pruning\AtomicByteRange;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Pruning\PrunedSubspan;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\SourceDigest;
use KnowKit\Domain\SourceId;
use KnowKit\Domain\SourceVersionId;
use KnowKit\Domain\Span;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\TextLocator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('retrieval:pruning')]
final class AtomicProtectionTest extends TestCase
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

    /**
     * @return array{0: IrDocument, 1: Chunk, 2: CanonicalMap, 3: list<AtomicByteRange>}
     */
    private function buildAtomicFixture(): array
    {
        $prefix = 'Закон импульса: ';
        $formula = '$$ \sum_{i=1}^{n} m_i \vec{v}_i = \sum_{i=1}^{n} m_i \vec{v}\'_i $$';
        $suffix = ' Конец.';
        $segText = $prefix . $formula . $suffix;

        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'md-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [
                ['segmentId' => 'seg-1', 'text' => $segText, 'textDigest' => hash('sha256', $segText), 'status' => 'ok'],
            ],
            'blocks' => [
                [
                    'blockId' => 'b-1',
                    'kind' => 'paragraph',
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

        $document = new IrDocument($irWire, $this->permissiveValidator());
        $revision = new DocumentRevisionRef(
            new ExactSourceRef(
                new TenantId('01JSC420000000000000000000'),
                new SourceId('01JSSR00000000000000000001'),
                new SourceVersionId('01JSSV00000000000000000001'),
                new SourceDigest(str_repeat('a', 64)),
            ),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(hash('sha256', $document->canonical())),
            new ParserSignature('md-parser', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );
        $docId = new DocumentId('01JSD000000000000000000001');
        $map = new CanonicalMap($document, $revision, $docId);
        $span = new Span(
            TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, strlen($segText)),
            new Sha256(hash('sha256', $segText)),
        );
        $chunkRef = new ChunkRef(
            $revision,
            new ChunkSetId('01JSCS00000000000000000001'),
            new ChunkId('01JSCK00000000000000000001'),
            new Sha256(hash('sha256', $segText)),
        );
        $chunk = new Chunk($chunkRef, $docId, [$span], $segText);

        $formulaStart = strlen($prefix);
        $formulaEnd = $formulaStart + strlen($formula);
        $atoms = [new AtomicByteRange($formulaStart, $formulaEnd, 'formula')];

        return [$document, $chunk, $map, $atoms];
    }

    public function testStrictModeRejectsPartialFormulaCut(): void
    {
        [$document, $chunk, $map, $atoms] = $this->buildAtomicFixture();
        $projector = new SubspanProjector();
        $atom = $atoms[0];

        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('invalid_pruned_subspan');
        $projector->project(
            $chunk,
            $document,
            $atoms,
            new PrunedSelection($chunk->ref, [new PrunedSubspan(0, $atom->startByte + 10)]),
            $map,
            ' … ',
            true,
        );
    }

    public function testNonStrictModeExpandsAtomWithinMaxKeptBytesAndRejectsWhenExceeded(): void
    {
        [$document, $chunk, $map, $atoms] = $this->buildAtomicFixture();
        $projector = new SubspanProjector();
        $atom = $atoms[0];

        // Expands [0, atom->startByte + 10) to [0, atom->endByte)
        $fragment = $projector->project(
            $chunk,
            $document,
            $atoms,
            new PrunedSelection($chunk->ref, [new PrunedSubspan(0, $atom->startByte + 10)]),
            $map,
            ' … ',
            false,
            256,
        );
        self::assertSame(substr($chunk->text(), 0, $atom->endByte), $fragment->get('text'));

        // Rejects when expanded atom exceeds maxKeptBytes
        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('invalid_pruned_subspan');
        $projector->project(
            $chunk,
            $document,
            $atoms,
            new PrunedSelection($chunk->ref, [new PrunedSubspan(0, $atom->startByte + 10)]),
            $map,
            ' … ',
            false,
            20,
        );
    }
}
