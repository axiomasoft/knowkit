<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Pruning;

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Domain\Bbox;
use KnowKit\Domain\Chunk;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Pruning\AtomicByteRange;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Span;
use KnowKit\Domain\TextLocator;
use KnowKit\Domain\Ulid;

final readonly class SubspanProjector
{
    /**
     * @param list<AtomicByteRange> $atomicRanges
     */
    public function project(
        Chunk $chunk,
        IrDocument $document,
        array $atomicRanges,
        PrunedSelection $selection,
        CanonicalMap $map,
        string $joiner = ' … ',
        bool $strictAtomRejection = false,
        ?int $maxKeptBytes = null,
        ?string $chunkJoiner = null,
        ?JsonObject $chunkMetadata = null,
        ?Ulid $citationId = null,
    ): JsonObject {
        if (!$selection->chunkRef->equals($chunk->ref)) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
        if (preg_match('//u', $joiner) !== 1 || mb_strlen($joiner, 'UTF-8') < 1 || mb_strlen($joiner, 'UTF-8') > 16) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }

        $chunkText = $chunk->text();
        $textLen = strlen($chunkText);

        foreach ($atomicRanges as $atom) {
            if ($atom->endByte > $textLen) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
            }
        }

        // 1. Validate raw subspans: bounds, strict ascending non-overlapping, UTF-8 boundaries.
        $ranges = [];
        $prevEnd = 0;
        foreach ($selection->subspans as $subspan) {
            $start = $subspan->startByte;
            $end = $subspan->endByte;
            if ($start < 0 || $start >= $end || $end > $textLen || $start < $prevEnd) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
            }
            self::assertUtf8Slice($chunkText, $start, $end, $textLen);
            $ranges[] = [$start, $end];
            $prevEnd = $end;
        }

        // 2. Enforce indivisible AtomicByteRange protection.
        $protected = [];
        foreach ($ranges as [$start, $end]) {
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($atomicRanges as $atom) {
                    if ($atom->overlaps($start, $end) && !$atom->isContainedIn($start, $end)) {
                        if ($strictAtomRejection) {
                            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
                        }
                        $start = min($start, $atom->startByte);
                        $end = max($end, $atom->endByte);
                        $changed = true;
                    }
                }
            }
            self::assertUtf8Slice($chunkText, $start, $end, $textLen);
            $protected[] = [$start, $end];
        }

        // Merge any overlapping or touching ranges produced by atom expansion.
        $merged = [];
        foreach ($protected as [$start, $end]) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && $start <= $merged[$lastIndex][1]) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        if ($maxKeptBytes !== null) {
            $keptBytes = 0;
            foreach ($merged as [$start, $end]) {
                $keptBytes += $end - $start;
            }
            if ($keptBytes > $maxKeptBytes) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
            }
        }

        // 3. Map chunk-local byte ranges back to canonical IrDocument Spans.
        $spanSegments = $this->resolveChunkSpanSegments($chunk, $chunkText, $map, $chunkJoiner);
        $mappedSpans = [];
        foreach ($merged as [$start, $end]) {
            $matchedAny = false;
            foreach ($spanSegments as $segment) {
                $ovStart = max($start, $segment['localStart']);
                $ovEnd = min($end, $segment['localEnd']);
                if ($ovStart >= $ovEnd) {
                    continue;
                }
                $matchedAny = true;
                $dStart = $ovStart - $segment['localStart'];
                $dEnd = $ovEnd - $segment['localStart'];
                $mappedSpans[] = $this->sliceSpan($segment['span'], $dStart, $dEnd);
            }
            if (!$matchedAny) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
            }
        }

        if ($mappedSpans === [] || count($mappedSpans) > 64) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }

        // 4. Verify via SpanAssembler and recompute fragmentDigest.
        $effectiveJoiner = count($mappedSpans) > 1 ? $joiner : null;
        $prunedText = $map->assembler->assemble($mappedSpans, $effectiveJoiner);
        if ($maxKeptBytes !== null && strlen($prunedText) > $maxKeptBytes) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
        $fragmentDigest = hash('sha256', $prunedText);

        $source = $chunk->ref->revisionRef->sourceRef;
        $origin = $chunkMetadata?->get('origin') ?? 'source';
        if (!is_string($origin) || !in_array($origin, ['source', 'ocr'], true)) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
        $supportingRefs = $chunkMetadata?->get('supportingRefs');

        $citation = new JsonObject([
            'citationId' => $citationId !== null ? $citationId->value : '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'tenantId' => $source->tenantId->value,
            'sourceId' => $source->sourceId->value,
            'sourceVersionId' => $source->sourceVersionId->value,
            'sourceDigest' => $source->sourceDigest->value,
            'documentRevisionId' => $chunk->ref->revisionRef->documentRevisionId->value,
            'documentId' => $chunk->documentId->value,
            'irDigest' => $chunk->ref->revisionRef->irDigest->value,
            'contentDigest' => $fragmentDigest,
            'origin' => $origin,
            'spans' => $map->wire($mappedSpans),
        ]);
        if (is_array($supportingRefs) && $supportingRefs !== []) {
            $citation = $citation->with('supportingRefs', $supportingRefs);
        }

        return new JsonObject([
            'citation' => $citation,
            'text' => $prunedText,
            'joiner' => $effectiveJoiner,
            'fragmentDigest' => $fragmentDigest,
            'provenanceKind' => 'pruned_verbatim_subspans',
        ]);
    }

    private static function assertUtf8Slice(string $text, int $start, int $end, int $textLen): void
    {
        if ((ord($text[$start]) & 0xC0) === 0x80) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
        if ($end < $textLen && (ord($text[$end]) & 0xC0) === 0x80) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
        $zwj = "\xE2\x80\x8D";
        foreach ([$start, $end] as $cut) {
            if (($cut >= 3 && substr($text, $cut - 3, 3) === $zwj) || substr($text, $cut, 3) === $zwj) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
            }
        }
        $slice = substr($text, $start, $end - $start);
        if ($slice === '' || preg_match('//u', $slice) !== 1) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
    }

    /**
     * @return list<array{span: Span, localStart: int, localEnd: int}>
     */
    private function resolveChunkSpanSegments(
        Chunk $chunk,
        string $chunkText,
        CanonicalMap $map,
        ?string $chunkJoiner,
    ): array {
        $spans = $chunk->spans;
        if (count($spans) === 1) {
            return [[
                'span' => $spans[0],
                'localStart' => 0,
                'localEnd' => strlen($chunkText),
            ]];
        }

        $parts = [];
        foreach ($spans as $span) {
            $parts[] = $map->assembler->assemble([$span], null);
        }

        if ($chunkJoiner === null) {
            $firstLen = strlen($parts[0]);
            $secondPos = strpos($chunkText, $parts[1], $firstLen);
            if ($secondPos === false) {
                throw new DomainFailure(Outcome::Incompatible, 'source_text_mismatch');
            }
            $chunkJoiner = substr($chunkText, $firstLen, $secondPos - $firstLen);
        }

        if (implode($chunkJoiner, $parts) !== $chunkText) {
            throw new DomainFailure(Outcome::Incompatible, 'source_text_mismatch');
        }

        $segments = [];
        $offset = 0;
        $joinerLen = strlen($chunkJoiner);
        foreach ($spans as $index => $span) {
            $partLen = strlen($parts[$index]);
            $segments[] = [
                'span' => $span,
                'localStart' => $offset,
                'localEnd' => $offset + $partLen,
            ];
            $offset += $partLen + $joinerLen;
        }

        return $segments;
    }

    private function sliceSpan(Span $span, int $deltaStart, int $deltaEnd): Span
    {
        $wire = $span->locator->toWire();
        $origStart = (int) $wire->get('byteStart');
        $newStart = $origStart + $deltaStart;
        $newEnd = $origStart + $deltaEnd;

        if ($wire->get('kind') === 'page') {
            $box = $wire->get('bbox');
            $bbox = $box instanceof JsonObject
                ? new Bbox($box->get('x0'), $box->get('y0'), $box->get('x1'), $box->get('y1'))
                : null;
            $locator = TextLocator::page(
                new LocalId((string) $wire->get('pageId')),
                (int) $wire->get('physicalNo'),
                $wire->get('printedLabel'),
                new LocalId((string) $wire->get('blockId')),
                $newStart,
                $newEnd,
                $bbox,
            );
        } else {
            $locator = TextLocator::segment(
                new LocalId((string) $wire->get('segmentId')),
                new LocalId((string) $wire->get('blockId')),
                $newStart,
                $newEnd,
            );
        }

        return new Span($locator, $span->textDigest);
    }
}
