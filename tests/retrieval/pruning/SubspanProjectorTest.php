<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Pruning;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Pruning\SubspanProjector;
use KnowKit\Application\Validation\SpanAssembler;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\Chunk;
use KnowKit\Domain\ChunkId;
use KnowKit\Domain\ChunkingResult;
use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\ChunkSet;
use KnowKit\Domain\ChunkSetId;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\ScopeBinding;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\DisclosureRequest;
use KnowKit\Domain\DocumentId;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\EffectiveProfile;
use KnowKit\Domain\ExactSourceRef;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\GenerationId;
use KnowKit\Domain\IdempotencyKey;
use KnowKit\Domain\IndexManifest;
use KnowKit\Domain\IrDigest;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\KbId;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\ManifestCandidate;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ParserSignature;
use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Pruning\PrunedSubspan;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Domain\Query;
use KnowKit\Domain\QueryBinding;
use KnowKit\Domain\QueryGrant;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Selection;
use KnowKit\Domain\SelectionInventory;
use KnowKit\Domain\SelectionLimits;
use KnowKit\Domain\SelectionRecord;
use KnowKit\Domain\SelectionUnit;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\SnapshotId;
use KnowKit\Domain\SnapshotRef;
use KnowKit\Domain\SourceDigest;
use KnowKit\Domain\SourceId;
use KnowKit\Domain\SourceVersionId;
use KnowKit\Domain\Span;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\TextLocator;
use KnowKit\Domain\Ulid;
use KnowKit\Domain\UtcInstant;
use KnowKit\Port\CanonicalDocuments;
use KnowKit\Port\ContextPruner;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\SelectionCatalog;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\AuthorizedSearchScope;
use KnowKit\Domain\DecisionReceipt;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('retrieval:pruning')]
final class SubspanProjectorTest extends TestCase
{
    private function realValidator(): ContractValidator
    {
        $schemas = [];
        $files = glob(dirname(__DIR__, 3) . '/schemas/*.schema.json') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $schema = StrictJson::decode((string) file_get_contents($file), 4194304);
            self::assertInstanceOf(JsonObject::class, $schema);
            $schemas['urn:knowkit:schema:' . basename($file, '.schema.json') . ':1.0'] = $schema;
        }

        return new ContractValidator(new SchemaSet($schemas));
    }

    private function permissiveValidator(): ContractValidator
    {
        $ids = [
            'urn:knowkit:schema:document-ir:1.0',
            'urn:knowkit:schema:disclosure-request:1.0',
            'urn:knowkit:schema:selection:1.0',
            'urn:knowkit:schema:decision-receipt:1.0',
            'urn:knowkit:schema:query-grant:1.0',
            'urn:knowkit:schema:authorized-search-scope:1.0',
            'urn:knowkit:schema:index-manifest:1.0',
        ];
        $schemas = [];
        foreach ($ids as $id) {
            $schemas[$id] = new JsonObject([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                '$id' => $id,
                'type' => 'object',
            ]);
        }

        return new ContractValidator(new SchemaSet($schemas));
    }

    /**
     * @return array{0: IrDocument, 1: DocumentRevisionRef, 2: Chunk, 3: CanonicalMap}
     */
    private function buildMultiSpanFixture(?string $seg2Override = null): array
    {
        $seg1 = '## § 4.2. Закон сохранения импульса';
        $seg2 = $seg2Override ?? 'Исторически это восходит к Декарту 🚀. Суммарный импульс системы сохраняется строго.';

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
                    'kind' => 'heading',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-1', 'byteStart' => 0, 'byteEnd' => strlen($seg1)]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b-2',
                    'kind' => 'paragraph',
                    'parentId' => 'b-1',
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

        $span1 = new Span(
            TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, strlen($seg1)),
            new Sha256(hash('sha256', $seg1)),
        );
        $span2 = new Span(
            TextLocator::segment(new LocalId('seg-2'), new LocalId('b-2'), 0, strlen($seg2)),
            new Sha256(hash('sha256', $seg2)),
        );

        $chunkText = $seg1 . "\n" . $seg2;
        $chunkRef = new ChunkRef(
            $revision,
            new ChunkSetId('01JSCS00000000000000000001'),
            new ChunkId('01JSCK00000000000000000001'),
            new Sha256(hash('sha256', $chunkText)),
        );
        $chunk = new Chunk($chunkRef, $docId, [$span1, $span2], $chunkText);

        return [$document, $revision, $chunk, $map];
    }

    public function testRejectsMismatchedSingleSpan(): void
    {
        [$document, , $original, $map] = $this->buildMultiSpanFixture();
        $text = str_repeat('A', $original->spans[0]->locator->toWire()->get('byteEnd'));
        $ref = new ChunkRef(
            $original->ref->revisionRef,
            $original->ref->chunkSetId,
            $original->ref->chunkId,
            new Sha256(hash('sha256', $text)),
        );
        $chunk = new Chunk($ref, $original->documentId, [$original->spans[0]], $text);
        $selection = new PrunedSelection($ref, [new PrunedSubspan(0, 2)]);

        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('source_text_mismatch');
        (new SubspanProjector())->project($chunk, $document, [], $selection, $map);
    }

    public function testRejectsOutOfBoundsSubspan(): void
    {
        [$document, , $chunk, $map] = $this->buildMultiSpanFixture();
        $projector = new SubspanProjector();
        $selection = new PrunedSelection($chunk->ref, [new PrunedSubspan(0, strlen($chunk->text()) + 5)]);

        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('invalid_pruned_subspan');
        $projector->project($chunk, $document, [], $selection, $map);
    }

    public function testRejectsMidUtf8CyrillicAndEmojiBoundaries(): void
    {
        [$document, , $chunk, $map] = $this->buildMultiSpanFixture();
        $projector = new SubspanProjector();
        $text = $chunk->text();

        // Cut inside 2-byte Cyrillic letter 'З' in 'Закон'
        $cyrPos = strpos($text, 'Закон');
        self::assertIsInt($cyrPos);
        try {
            $projector->project($chunk, $document, [], new PrunedSelection($chunk->ref, [new PrunedSubspan($cyrPos + 1, $cyrPos + 10)]), $map);
            self::fail('Expected mid-Cyrillic UTF-8 boundary failure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Incompatible, $e->outcome);
            self::assertSame('invalid_pruned_subspan', $e->errorCode);
        }

        // Cut inside 4-byte emoji '🚀'
        $emojiPos = strpos($text, '🚀');
        self::assertIsInt($emojiPos);
        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('invalid_pruned_subspan');
        $projector->project($chunk, $document, [], new PrunedSelection($chunk->ref, [new PrunedSubspan(0, $emojiPos + 2)]), $map);
    }

    public function testRejectsZwjClusterSplitsAndNonVerbatimOrigin(): void
    {
        $family = "👨\u{200D}👩";
        [$document, , $chunk, $map] = $this->buildMultiSpanFixture('Семья ' . $family . ' вместе.');
        $projector = new SubspanProjector();
        $text = $chunk->text();
        $man = strpos($text, '👨');
        self::assertIsInt($man);
        $zwj = $man + 4;
        $cuts = [
            [0, $zwj],         // ends before ZWJ
            [0, $zwj + 3],     // ends right after ZWJ
            [$zwj, $zwj + 7],  // starts on ZWJ
            [$zwj + 3, $zwj + 7], // starts right after ZWJ
        ];
        foreach ($cuts as [$a, $b]) {
            try {
                $projector->project($chunk, $document, [], new PrunedSelection($chunk->ref, [new PrunedSubspan($a, $b)]), $map);
                self::fail('Expected ZWJ split failure');
            } catch (DomainFailure $e) {
                self::assertSame('invalid_pruned_subspan', $e->errorCode);
            }
        }
        $whole = new PrunedSelection($chunk->ref, [new PrunedSubspan($man, $zwj + 7)]);
        self::assertSame($family, $projector->project($chunk, $document, [], $whole, $map)->get('text'));

        $meta = new JsonObject(['origin' => 'extracted', 'supportingRefs' => []]);
        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('invalid_pruned_subspan');
        $projector->project($chunk, $document, [], $whole, $map, ' … ', false, null, null, $meta);
    }

    public function testRejectsOverlappingAndUnsortedSubspans(): void
    {
        [$document, , $chunk, $map] = $this->buildMultiSpanFixture();
        $projector = new SubspanProjector();

        // Overlapping
        try {
            $projector->project($chunk, $document, [], new PrunedSelection($chunk->ref, [
                new PrunedSubspan(0, 10),
                new PrunedSubspan(5, 20),
            ]), $map);
            self::fail('Expected overlapping subspan failure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Incompatible, $e->outcome);
            self::assertSame('invalid_pruned_subspan', $e->errorCode);
        }

        // Unsorted
        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('invalid_pruned_subspan');
        $projector->project($chunk, $document, [], new PrunedSelection($chunk->ref, [
            new PrunedSubspan(20, 30),
            new PrunedSubspan(0, 10),
        ]), $map);
    }

    public function testRoundtripsMultiSpanSubspansThroughSpanAssembler(): void
    {
        [$document, $revision, $chunk, $map] = $this->buildMultiSpanFixture();
        $projector = new SubspanProjector();
        $text = $chunk->text();

        $head = '## § 4.2. Закон сохранения импульса';
        $tail = 'Суммарный импульс системы сохраняется строго.';
        $headStart = 0;
        $headEnd = strlen($head);
        $tailStart = strpos($text, $tail);
        self::assertIsInt($tailStart);
        $tailEnd = $tailStart + strlen($tail);

        $fragment = $projector->project(
            $chunk,
            $document,
            [],
            new PrunedSelection($chunk->ref, [
                new PrunedSubspan($headStart, $headEnd, 1000),
                new PrunedSubspan($tailStart, $tailEnd, 900),
            ]),
            $map,
        );

        $expectedText = $head . ' … ' . $tail;
        self::assertSame($expectedText, $fragment->get('text'));
        self::assertSame(' … ', $fragment->get('joiner'));
        self::assertSame('pruned_verbatim_subspans', $fragment->get('provenanceKind'));
        self::assertSame(hash('sha256', $expectedText), $fragment->get('fragmentDigest'));

        $citation = $fragment->get('citation');
        self::assertInstanceOf(JsonObject::class, $citation);
        self::assertSame('source', $citation->get('origin'));
        self::assertSame([], $this->realValidator()->validateValue($citation, 'urn:knowkit:schema:citation-ref:1.0'));
        $spans = $map->spans($citation->get('spans'));
        $assembler = new SpanAssembler($document, $revision, $chunk->documentId);
        self::assertSame($expectedText, $assembler->assemble($spans, $fragment->get('joiner')));
    }

    public function testCanonicalBufferIntegratesOptionalContextPruner(): void
    {
        [$document, $revision, $chunk, $map] = $this->buildMultiSpanFixture();
        $validator = $this->permissiveValidator();
        $scope = new Scope(
            new TenantId('01JSC420000000000000000000'),
            new KbId('01JSKB00000000000000000001'),
            new ActorId('01JSAC00000000000000000001'),
            'test.prune',
            new Decimal('1'),
            new Sha256(str_repeat('d', 64)),
        );
        $snapshot = new SnapshotRef($scope->tenantId, $scope->kbId, new SnapshotId('01JSSN00000000000000000001'), new Fingerprint(str_repeat('e', 64)));
        $generationId = new GenerationId('01JSGN00000000000000000001');
        $limits = SelectionLimits::profile('compact');
        $profile = new EffectiveProfile('test', '1.0', []);
        $query = new QueryBinding(new Query('импульс'), new Query('импульс'), 'rev1', $profile, $limits);

        $chunkerSig = new ParserSignature('chunker', '1.0', new Fingerprint(str_repeat('f', 64)));
        $chunkSet = new ChunkSet($revision, $chunk->ref->chunkSetId, $chunkerSig, [$chunk]);
        $chunkManifest = new JsonObject([
            'chunkSetId' => $chunk->ref->chunkSetId->value,
            'revisionRef' => $revision->toWire(),
            'documentId' => $chunk->documentId->value,
            'chunkerSignature' => $chunkerSig->toWire(),
            'chunks' => [new JsonObject([
                'chunkId' => $chunk->ref->chunkId->value,
                'chunkDigest' => $chunk->ref->chunkDigest->value,
                'byteLength' => strlen($chunk->text()),
                'spans' => $map->wire($chunk->spans),
                'joiner' => "\n",
                'origin' => 'source',
                'kind' => 'verbatim',
                'supportingRefs' => [],
            ])],
        ]);
        $chunkingResult = new ChunkingResult($chunkSet, $chunkManifest);
        $indexWire = new JsonObject([
            'tenantId' => $scope->tenantId->value,
            'kbId' => $scope->kbId->value,
            'generationId' => $generationId->value,
            'profile' => new JsonObject([
                'parserSignature' => $revision->parserSignature->toWire(),
                'chunkerSignature' => $chunkerSig->toWire(),
            ]),
            'members' => [new JsonObject([
                'sourceId' => $revision->sourceRef->sourceId->value,
                'sourceVersionId' => $revision->sourceRef->sourceVersionId->value,
                'sourceDigest' => $revision->sourceRef->sourceDigest->value,
                'documentRevisionId' => $revision->documentRevisionId->value,
                'irDigest' => $revision->irDigest->value,
                'chunkSetId' => $chunk->ref->chunkSetId->value,
                'chunkSetDigest' => $chunkingResult->manifestDigest->value,
                'chunkCount' => 1,
            ])],
            'resources' => [],
            'counts' => new JsonObject(['members' => 1, 'resources' => 0, 'chunks' => 1]),
        ]);
        $indexManifest = new IndexManifest(
            $indexWire->with('manifestDigest', HashDomain::IndexManifest->digest($indexWire)),
            $validator,
        );
        $candidate = new ManifestCandidate($indexManifest, [$chunkingResult], []);
        $unit = new SelectionUnit($chunk, $chunk->text(), false);
        $inventory = new SelectionInventory($snapshot, $candidate, [$unit]);

        $grant = new QueryGrant(new JsonObject([
            'tenantId' => $scope->tenantId->value,
            'kbId' => $scope->kbId->value,
            'actorId' => $scope->actorId->value,
            'purpose' => $scope->purpose,
            'hostScopeHash' => $scope->hostScopeHash->value,
            'grantEpoch' => '1',
            'expiresAt' => '2026-11-27T11:00:00Z',
            'allowedSourceVersionIds' => [$revision->sourceRef->sourceVersionId->value],
            'policyBindingDigest' => HashDomain::PolicyBinding->digest(new JsonObject(['k' => 'v'])),
        ]), $validator);
        $authScope = AuthorizedSearchScope::materialize(
            $scope,
            $grant,
            $snapshot,
            $generationId,
            [$revision->sourceRef->sourceVersionId],
            new UtcInstant('2026-11-27T10:00:00Z'),
        );
        $selectionId = new Ulid('01JSAA00000000000000000001');
        $policyBinding = new JsonObject(['kbPolicyEpoch' => '1']);
        $selection = new Selection(new JsonObject([
            'tenantId' => $scope->tenantId->value,
            'kbId' => $scope->kbId->value,
            'actorId' => $scope->actorId->value,
            'purpose' => $scope->purpose,
            'policyEpoch' => $scope->policyEpoch->value,
            'hostScopeHash' => $scope->hostScopeHash->value,
            'policyBinding' => $policyBinding,
            'scopeBindingDigest' => ScopeBinding::scopeBindingDigest($scope->toWire(), ScopeBinding::policyBindingDigest($policyBinding)),
            'selectionId' => $selectionId->value,
            'generationId' => $generationId->value,
            'snapshotId' => $snapshot->snapshotId->value,
            'queryDigest' => $query->digest()->value,
            'limits' => $query->limits->toWire(),
            'expiresAt' => '2026-11-27T10:02:00Z',
            'candidateRefs' => [$chunk->ref->toWire()],
        ]), $validator);
        $policyContext = new PolicyContext($scope, 'test-host');
        $record = new SelectionRecord(
            $selection,
            $policyContext,
            $query,
            $snapshot,
            new UtcInstant('2026-11-27T10:00:00Z'),
        );

        $request = new DisclosureRequest(new JsonObject([
            'selectionId' => $selectionId->value,
            'chunkRefs' => [$chunk->ref->toWire()],
            'disclosureAttemptId' => '01JSDA00000000000000000001',
            'pruningPolicy' => new JsonObject([
                'thresholdPermille' => 200,
                'alwaysKeepContext' => true,
                'keepAdjacentForCoreference' => false,
                'minKeptBytes' => 10,
                'maxKeptBytes' => 512,
            ]),
        ]), $validator);

        $catalog = new class ($inventory) implements SelectionCatalog {
            public function __construct(private SelectionInventory $inventory) {}
            public function load(Scope $scope, SnapshotRef $snapshot, GenerationId $generation): SelectionInventory { return $this->inventory; }
        };
        $docs = new class ($document, $chunk) implements CanonicalDocuments {
            public function __construct(private IrDocument $doc, private Chunk $chunk) {}
            public function read(DocumentRevisionRef $revision, int $maxBytes): IrDocument { return $this->doc; }
            public function readChunk(ChunkRef $ref, int $maxBytes): string { return $this->chunk->text(); }
        };
        $ids = new class implements IdGenerator {
            public function next(): Ulid { return new Ulid('01JSAA00000000000000000002'); }
        };
        $pruner = new class implements ContextPruner {
            public function prune(QueryBinding $query, array $units, PruningPolicy $policy): array
            {
                return [new PrunedSelection($units[0]->chunkRef(), [new PrunedSubspan(0, strlen('## § 4.2. Закон сохранения импульса'), 1000)])];
            }
        };

        $buffer = new CanonicalBuffer($catalog, $docs, $ids, null, null, null, $pruner);
        $out = $buffer->preload($record, $request);

        self::assertSame(strlen('## § 4.2. Закон сохранения импульса'), $out->totalBytes);
    }
}
