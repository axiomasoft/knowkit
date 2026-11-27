<?php

declare(strict_types=1);

namespace KnowKit\Tests\Property;

require_once dirname(__DIR__) . '/storage/catalog/bootstrap.php';

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Application\Retrieval\Pruning\SubspanProjector;
use KnowKit\Application\Validation\SpanAssembler;
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
use Random\Engine\Mt19937;
use Random\Randomizer;

#[Group('property:invariants')]
final class SubspanProjectorPropertyTest extends TestCase
{
    private function seed(): int
    {
        $env = getenv('KNOWKIT_FUZZ_SEED');

        return ($env !== false && ctype_digit($env)) ? (int) $env : 20261127;
    }

    public function testSubspanProjectorRoundTripsMultibyteAndRejectsUtf8OrAtomSplits(): void
    {
        $rng = new Randomizer(new Mt19937($this->seed()));
        $projector = new SubspanProjector();
        $validator = $this->permissiveValidator();

        $pieces = [
            'Импульс системы ',
            '能量守恒定律 ',
            "👨\u{200D}👩\u{200D}👧 ",
            '🚀 ',
            '$$E=mc^2$$ ',
            '`fn(x)` ',
            '| a | b | ',
            'plain ascii ',
        ];

        for ($iter = 0; $iter < 500; $iter++) {
            $seg1 = '';
            $count1 = $rng->getInt(2, 5);
            for ($k = 0; $k < $count1; $k++) {
                $seg1 .= $pieces[$rng->getInt(0, count($pieces) - 1)];
            }
            $atom = '$$x^2+y^2=r^2$$';
            $seg2 = $pieces[$rng->getInt(0, 3)] . $atom . ' ' . $pieces[$rng->getInt(0, 3)];

            [$document, $revision, $chunk, $map] = $this->buildFixture($seg1, $seg2, $validator);
            $assembler = new SpanAssembler($document, $revision, $chunk->documentId);
            $text = $chunk->text();
            $seg1Len = strlen($seg1);

            // 1) Valid subspan covering seg1 must match IrDocument byte-for-byte via SpanAssembler.
            $projected = $projector->project(
                $chunk,
                $document,
                [],
                new PrunedSelection($chunk->ref, [new PrunedSubspan(0, $seg1Len)]),
                $map,
            );
            self::assertSame($seg1, $projected->get('text'));
            $assembled = $assembler->assemble(
                [new Span(TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, $seg1Len), new Sha256(hash('sha256', $seg1)))],
                null,
            );
            self::assertSame($seg1, $assembled);

            // 2) Splitting inside a multi-byte UTF-8 character or inside an atom must fail with invalid_pruned_subspan.
            $len = strlen($text);
            $midUtf8 = null;
            for ($b = 1; $b < $len; $b++) {
                $ord = ord($text[$b]);
                if (($ord & 0xC0) === 0x80) {
                    $midUtf8 = $b;
                    break;
                }
            }
            if ($midUtf8 !== null) {
                $badStart = ($rng->getInt(0, 1) === 0);
                $s = $badStart ? $midUtf8 : 0;
                $e = $badStart ? $len : $midUtf8;
                try {
                    $projector->project($chunk, $document, [], new PrunedSelection($chunk->ref, [new PrunedSubspan($s, $e)]), $map);
                    self::fail('Expected invalid_pruned_subspan for UTF-8 split');
                } catch (DomainFailure $ex) {
                    self::assertSame(Outcome::Incompatible, $ex->outcome);
                    self::assertSame('invalid_pruned_subspan', $ex->errorCode);
                }
            }

            $atomPos = strpos($text, $atom);
            self::assertIsInt($atomPos);
            $atomCut = $atomPos + $rng->getInt(1, strlen($atom) - 1);
            $atoms = [new AtomicByteRange($atomPos, $atomPos + strlen($atom), 'formula')];
            try {
                $projector->project($chunk, $document, $atoms, new PrunedSelection($chunk->ref, [new PrunedSubspan(0, $atomCut)]), $map, strictAtomRejection: true);
                self::fail('Expected invalid_pruned_subspan for atom split');
            } catch (DomainFailure $ex) {
                self::assertSame(Outcome::Incompatible, $ex->outcome);
                self::assertSame('invalid_pruned_subspan', $ex->errorCode);
            }
        }
    }

    /**
     * @return array{0: IrDocument, 1: DocumentRevisionRef, 2: Chunk, 3: CanonicalMap}
     */
    private function buildFixture(string $seg1, string $seg2, ContractValidator $validator): array
    {
        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'md-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [
                ['segmentId' => 'seg-1', 'text' => $seg1, 'textDigest' => hash('sha256', $seg1), 'status' => 'ok'],
                ['segmentId' => 'seg-2', 'text' => $seg2, 'textDigest' => hash('sha256', $seg2), 'status' => 'ok'],
            ],
            'blocks' => [
                [
                    'blockId' => 'b-1',
                    'kind' => 'paragraph',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-1', 'byteStart' => 0, 'byteEnd' => strlen($seg1)]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-2',
                    'kind' => 'paragraph',
                    'parentId' => null,
                    'order' => 2,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-2', 'blockId' => 'b-2', 'byteStart' => 0, 'byteEnd' => strlen($seg2)]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
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

        $document = new IrDocument($irWire, $validator);
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
        $span1 = new Span(TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, strlen($seg1)), new Sha256(hash('sha256', $seg1)));
        $span2 = new Span(TextLocator::segment(new LocalId('seg-2'), new LocalId('b-2'), 0, strlen($seg2)), new Sha256(hash('sha256', $seg2)));
        $chunkText = $seg1 . "\n" . $seg2;
        $chunkRef = new ChunkRef($revision, new ChunkSetId('01JSCS00000000000000000001'), new ChunkId('01JSCK00000000000000000001'), new Sha256(hash('sha256', $chunkText)));

        return [$document, $revision, new Chunk($chunkRef, $docId, [$span1, $span2], $chunkText), $map];
    }

    private function permissiveValidator(): ContractValidator
    {
        $schemas = [];
        foreach (['urn:knowkit:schema:document-ir:1.0'] as $id) {
            $schemas[$id] = new JsonObject([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                '$id' => $id,
                'type' => 'object',
            ]);
        }

        return new ContractValidator(new SchemaSet($schemas));
    }
}
