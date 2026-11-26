<?php
declare(strict_types=1);

namespace KnowKit\Application\Chunking;

use KnowKit\Domain\{Chunk, ChunkId, ChunkRef, ChunkSet, ChunkSetId, ChunkerProfile, DocumentId, DocumentRevisionRef, IrDocument, Sha256, Span};
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Port\{ChunkSetBuilder, Tokenizer};

/**
 * Revision-bound implementation of the existing port. build() additionally returns
 * the immutable metadata sidecar needed by persistence and bounded read followups.
 * Reuses SpanAssembler (P2.5 adaptation of AgenTools GenericValidator); no donor runtime.
 *
 * @phpstan-type UnitShape array{
 *     block: JsonObject,
 *     spans: list<Span>,
 *     context: list<JsonObject>,
 *     whole: bool,
 *     requiresRead?: bool,
 *     table?: array{rowRange: array{int, int}, cells: list<JsonObject>}
 * }
 */
final readonly class StructuralChunker implements ChunkSetBuilder
{
    public function __construct(
        public DocumentRevisionRef $revision,
        public DocumentId $documentId,
        private ?Tokenizer $tokenizer = null,
    ) {
    }

    public function revision(): DocumentRevisionRef
    {
        return $this->revision;
    }

    public function documentId(): DocumentId
    {
        return $this->documentId;
    }

    public function chunk(IrDocument $document, ChunkerProfile $profile): ChunkSet
    {
        return $this->build($document, $profile)->chunkSet;
    }

    public function build(IrDocument $document, ChunkerProfile $profile): ChunkingResult
    {
        $map = new CanonicalMap($document, $this->revision, $this->documentId);
        $budget = new ChunkBudget($profile, $this->tokenizer);
        $identity = new JsonObject([
            'representationVersion' => 'structural-chunkset:1',
            'revisionRef' => $this->revision->toWire(),
            'documentId' => $this->documentId->value,
            'chunkerSignature' => $profile->signature->toWire(),
        ]);
        $setId = new ChunkSetId(self::id($identity->with('identityKind', 'chunkset')));
        $chunks = [];
        $entries = [];
        $followups = [];
        /** @var list<UnitShape> $pending */
        $pending = [];
        /** @param non-empty-list<UnitShape> $units */
        $emit = function (array $units) use (&$chunks, &$entries, $map, $setId): void {
            /** @var list<Span> $spans */
            $spans = array_values(array_merge(...array_column($units, 'spans')));
            $text = $map->text($spans);
            $first = $units[0];
            $block = $first['block'];
            $path = $map->path($block);
            foreach (array_slice($units, 1) as $unit) {
                $other = $map->path($unit['block']);
                while ($path !== [] && array_slice($other, 0, count($path)) !== $path) {
                    array_pop($path);
                }
            }
            $metadata = new JsonObject([
                'origin' => $block->get('origin'),
                'kind' => $block->get('kind') === 'table' ? 'table_representation' : $block->get('provenance')->get('kind'),
                'supportingRefs' => $block->get('provenance')->get('supportingRefs'),
                'joiner' => count($spans) > 1 ? "\n" : null,
                'structurePath' => $path,
                'blockIds' => array_values(array_unique(array_map(static fn(array $u): string => $u['block']->get('blockId'), $units))),
                'context' => $first['context'],
                'table' => $first['table'] ?? null,
            ]);
            $digest = new Sha256(hash('sha256', $text));
            $entry = $metadata
                ->with('spans', $map->wire($spans))
                ->with('chunkDigest', $digest->value)
                ->with('byteLength', strlen($text))
                ->with('ordinal', count($chunks));
            $chunkId = new ChunkId(self::id(new JsonObject([
                'identityKind' => 'chunk',
                'chunkSetId' => $setId->value,
                'entry' => $entry,
            ])));
            $chunk = new Chunk(new ChunkRef($this->revision, $setId, $chunkId, $digest), $this->documentId, $spans, $text);
            $map->assembler->assertChunk($chunk, $metadata->get('joiner'));
            $chunks[] = $chunk;
            $entries[] = $entry->with('chunkId', $chunkId->value);
        };
        /** @param UnitShape $unit */
        $follow = function (array $unit, string $reason) use (&$followups, $map): void {
            $path = $map->path($unit['block']);
            $requirement = new JsonObject([
                'kind' => 'read_requirement',
                'reason' => $reason,
                'isQuote' => false,
                'revisionRef' => $this->revision->toWire(),
                'documentId' => $this->documentId->value,
                'blockId' => $unit['block']->get('blockId'),
                'structurePath' => count($path) <= 64 ? $path : null,
                'origin' => $unit['block']->get('origin'),
                'provenance' => $unit['block']->get('provenance'),
                'spans' => count($unit['spans']) <= 64 ? $map->wire($unit['spans']) : null,
                'rowRange' => $unit['table']['rowRange'] ?? null,
            ]);
            if (strlen(Jcs::canonicalize($requirement)) > 4096) {
                $requirement = $requirement->with('spans', null)->with('structurePath', null)->with('provenance', null);
            }
            // Null details mean resolve the whole exact IR block/row selector, not a truncated span list.
            $followups[] = $requirement;
        };
        foreach ($map->blocks as $block) {
            $units = $block->get('kind') === 'table' ? $this->tableUnits($map, $block) : [$this->unit($map, $block)];
            foreach ($units as $unit) {
                if ($unit['spans'] === [] || ($unit['requiresRead'] ?? false)) {
                    if ($pending !== []) {
                        $emit($pending);
                        $pending = [];
                    }
                    $follow($unit, 'canonical_text_required');
                    continue;
                }
                if (!$this->fits($map, $budget, [$unit])) {
                    if ($pending !== []) {
                        $emit($pending);
                        $pending = [];
                    }
                    if ($block->get('kind') !== 'text') {
                        $follow($unit, 'structural_unit_exceeds_budget');
                        continue;
                    }
                    $pieces = $this->split($map, $budget, $unit);
                    if ($pieces === []) {
                        $follow($unit, 'budget_exhausted');
                    } else {
                        foreach ($pieces as $piece) {
                            $emit([$piece]);
                        }
                    }
                    continue;
                }
                $packable = $block->get('kind') === 'text';
                if ($pending !== [] && (!$packable || !$this->compatible($pending[0], $unit))) {
                    $emit($pending);
                    $pending = [];
                }
                if (!$packable) {
                    $emit([$unit]);
                    continue;
                }
                if ($pending !== [] && !$this->fits($map, $budget, [...$pending, $unit])) {
                    $emit($pending);
                    $last = $pending[array_key_last($pending)];
                    $pending = [];
                    if ($budget->overlapBlocks === 1 && $last['whole'] && $this->fits($map, $budget, [$last, $unit])) {
                        $pending[] = $last;
                    }
                }
                $pending[] = $unit;
            }
        }
        if ($pending !== []) {
            $emit($pending);
        }
        $manifest = $identity
            ->with('chunkSetId', $setId->value)
            ->with('chunks', $entries)
            ->with('followups', $followups)
            ->with('coverage', $document->toWire()->get('coverage'));
        return new ChunkingResult(new ChunkSet($this->revision, $setId, $profile->signature, $chunks), $manifest);
    }

    /** @return UnitShape */
    private function unit(CanonicalMap $map, JsonObject $block): array
    {
        return [
            'block' => $block,
            'spans' => $map->spans($block->get('spans')),
            'context' => $map->headings($block),
            'whole' => true,
        ];
    }

    /**
     * @param UnitShape $a
     * @param UnitShape $b
     */
    private function compatible(array $a, array $b): bool
    {
        foreach (['parentId', 'origin', 'provenance'] as $key) {
            if (Jcs::canonicalize($a['block']->get($key)) !== Jcs::canonicalize($b['block']->get($key))) {
                return false;
            }
        }
        return Jcs::canonicalize($a['context']) === Jcs::canonicalize($b['context']);
    }

    /** @param non-empty-list<UnitShape> $units */
    private function fits(CanonicalMap $map, ChunkBudget $budget, array $units): bool
    {
        /** @var list<Span> $spans */
        $spans = array_merge(...array_column($units, 'spans'));
        if (count($spans) > 64) {
            return false;
        }
        $parts = array_map(static fn(JsonObject $c): string => $c->get('text'), $units[0]['context']);
        $parts[] = $map->text($spans);
        return $budget->fits(implode("\n", $parts));
    }

    /**
     * Sentence boundaries first; then byte boundaries on UTF-8 codepoints. Never trim canonical bytes.
     *
     * @param UnitShape $unit
     * @return list<UnitShape>
     */
    private function split(CanonicalMap $map, ChunkBudget $budget, array $unit): array
    {
        $pieces = [];
        foreach ($unit['spans'] as $span) {
            $text = $map->text([$span]);
            $locator = $span->locator->toWire();
            preg_match_all('/[^.!?。！？]*[.!?。！？]+\s*|[^.!?。！？]+$/u', $text, $matches);
            $sentences = $matches[0];
            if (implode('', $sentences) !== $text) {
                $sentences = [$text];
            }
            $start = $locator->get('byteStart');
            $buffer = '';
            foreach ($sentences as $sentence) {
                if ($buffer !== '' && !$this->fits($map, $budget, [$this->sliceUnit($map, $unit, $locator, $start, strlen($buffer . $sentence))])) {
                    $pieces[] = $this->sliceUnit($map, $unit, $locator, $start, strlen($buffer));
                    $start += strlen($buffer);
                    $buffer = '';
                }
                while ($sentence !== '' && !$this->fits($map, $budget, [$this->sliceUnit($map, $unit, $locator, $start, strlen($sentence))])) {
                    $cut = mb_strcut($sentence, 0, $budget->maxBytes, 'UTF-8');
                    while ($cut !== '' && !$this->fits($map, $budget, [$this->sliceUnit($map, $unit, $locator, $start, strlen($cut))])) {
                        $cut = mb_substr($cut, 0, mb_strlen($cut, 'UTF-8') - 1, 'UTF-8');
                    }
                    if ($cut === '') {
                        return [];
                    }
                    $pieces[] = $this->sliceUnit($map, $unit, $locator, $start, strlen($cut));
                    $start += strlen($cut);
                    $sentence = substr($sentence, strlen($cut));
                }
                $buffer .= $sentence;
            }
            if ($buffer !== '') {
                $pieces[] = $this->sliceUnit($map, $unit, $locator, $start, strlen($buffer));
            }
        }
        return $pieces;
    }

    /**
     * @param UnitShape $unit
     * @return UnitShape
     */
    private function sliceUnit(CanonicalMap $map, array $unit, JsonObject $locator, int $start, int $length): array
    {
        $unit['spans'] = [$map->span($locator->with('byteStart', $start)->with('byteEnd', $start + $length))];
        $unit['whole'] = false;
        return $unit;
    }

    /**
     * Rowspan closure creates indivisible row groups. Referenced headers/notes are separate search context.
     *
     * @return list<UnitShape>
     */
    private function tableUnits(CanonicalMap $map, JsonObject $block): array
    {
        $table = $block->get('table');
        $cells = [];
        $byId = [];
        // IR headerRefs are local to this table, not a document-wide cell namespace.
        foreach ($table->get('cells') as $cell) {
            $cells[] = $cell;
            $byId[$cell->get('cellId')] = $cell;
        }
        usort(
            $cells,
            static fn(JsonObject $a, JsonObject $b): int => [$a->get('row'), $a->get('column'), $a->get('cellId')] <=> [$b->get('row'), $b->get('column'), $b->get('cellId')],
        );
        $units = [];
        $row = 0;
        while ($row < $table->get('rows')) {
            $end = $row + 1;
            $group = [];
            foreach ($cells as $cell) {
                if ($cell->get('row') < $row || $cell->get('row') >= $end) {
                    continue;
                }
                $group[] = $cell;
                $end = max($end, $cell->get('row') + $cell->get('rowSpan'));
            }
            if ($group === []) {
                $row = $end;
                continue;
            }
            $locators = [];
            $headers = [];
            $notes = [];
            $unitsByCell = [];
            foreach ($group as $cell) {
                array_push($locators, ...$cell->get('spans'));
                foreach ($cell->get('headerRefs') as $ref) {
                    $headers[$ref] = $byId[$ref];
                }
                foreach ($cell->get('noteRefs') as $ref) {
                    $notes[$ref] = $map->blocks[$ref];
                }
                $unitsByCell[] = new JsonObject([
                    'cellId' => $cell->get('cellId'),
                    'row' => $cell->get('row'),
                    'column' => $cell->get('column'),
                    'rowSpan' => $cell->get('rowSpan'),
                    'colSpan' => $cell->get('colSpan'),
                    'header' => $cell->get('header'),
                    'headerRefs' => $cell->get('headerRefs'),
                    'unit' => $cell->get('unit'),
                    'noteRefs' => $cell->get('noteRefs'),
                ]);
            }
            $context = $map->headings($block);
            $requiresRead = false;
            foreach (['repeated_table_header' => $headers, 'table_note' => $notes] as $role => $refs) {
                foreach ($refs as $ref) {
                    if ($ref->get('spans') !== []) {
                        $context[] = $map->context($role, $map->spans($ref->get('spans')));
                    } else {
                        $requiresRead = true;
                    }
                }
            }
            foreach ($group as $cell) {
                if ($cell->get('unit') !== null) {
                    $context[] = new JsonObject([
                        'role' => 'table_unit',
                        'origin' => 'structural_metadata',
                        'isQuote' => false,
                        'cellId' => $cell->get('cellId'),
                        'spans' => [],
                        'joiner' => null,
                        'text' => $cell->get('unit'),
                    ]);
                }
            }
            $unit = $this->unit($map, $block);
            $unit['spans'] = $map->spans($locators);
            $unit['context'] = $context;
            $unit['requiresRead'] = $requiresRead;
            $unit['table'] = ['rowRange' => [$row, $end], 'cells' => $unitsByCell];
            $units[] = $unit;
            $row = $end;
        }
        return $units === [] ? [$this->unit($map, $block)] : $units;
    }

    /** Content-addressed 128 bits, encoded in canonical ULID alphabet; no clock/randomness. */
    private static function id(JsonObject $payload): string
    {
        $hex = substr(HashDomain::ChunkSetManifest->digest($payload), 0, 32);
        $bits = '00';
        foreach (str_split($hex) as $digit) {
            $bits .= str_pad(decbin((int) hexdec($digit)), 4, '0', STR_PAD_LEFT);
        }
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $id = '';
        foreach (str_split($bits, 5) as $part) {
            $id .= $alphabet[(int) bindec($part)];
        }
        return $id;
    }
}
