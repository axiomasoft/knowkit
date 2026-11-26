<?php

declare(strict_types=1);

namespace KnowKit\Application\SourceNavigation;

use KnowKit\Domain as D;
use KnowKit\Domain\Navigation as N;
use KnowKit\Port\NfdNormalizer;

/** @internal Deterministic candidates only. Never edits, reanchors or persists a CitationRef. */
final readonly class QuoteLocator
{
    public function __construct(
        private ?NfdNormalizer $normalizer = null,
    ) {}

    public function locate(CanonicalDocument $document, D\DocumentRevisionRef $revision,
        TextQuoteSelector $selector, N\MatchMode $mode, N\Limits $limits): N\QuoteLocation
    {
        $quote = $selector->exact;
        if ($quote === '' || strlen($quote) > $limits->maxTextBytes || preg_match('//u', $quote) !== 1) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_quote');
        }
        $fingerprint = $mode === N\MatchMode::UnicodeNfd ? $this->nfd()->fingerprint() : null;
        $needle = $mode === N\MatchMode::Exact ? $quote : $this->project($quote, $mode)->text;
        $locations = [];
        $count = 0;
        $blocksByUnit = $this->blocksByUnit($document);
        foreach ($document->units as $unit) {
            foreach ($this->matches($unit, $selector, $mode, $needle, $blocksByUnit) as $span) {
                ++$count;
                if (count($locations) < $limits->maxCandidates) {
                    $locations[] = $span;
                }
            }
        }
        $status = $count === 0 ? 'not_found' : ($count > 1 ? 'ambiguous'
            : ($mode === N\MatchMode::Exact ? 'unique_exact' : 'candidate_normalized'));
        $kind = match ($mode) {
            N\MatchMode::Exact => 'exact',
            N\MatchMode::Whitespace => 'normalized-candidate-v1',
            N\MatchMode::UnicodeNfd => 'unicode-nfd-intl-v1',
        };
        $candidates = array_map(static fn (D\TextLocator $span): N\QuoteCandidate =>
            new N\QuoteCandidate($span, $kind, $count), $locations);

        return new N\QuoteLocation($status, $revision, $candidates, $count > count($locations),
            normalizerFingerprint: $fingerprint, coverage: $document->coverage);
    }

    /**
     * @param array<string, list<N\CanonicalBlock>> $blocksByUnit
     * @return iterable<D\TextLocator>
     */
    private function matches(N\CanonicalUnit $unit,
        TextQuoteSelector $selector, N\MatchMode $mode, string $needle, array $blocksByUnit): iterable
    {
        $projection = null;
        $text = $unit->text();
        if ($mode !== N\MatchMode::Exact) {
            $projection = $this->project($text, $mode);
            $text = $projection->text;
        }
        $previous = null;
        $offset = 0;
        while (($found = strpos($text, $needle, $offset)) !== false) {
            $offset = $found + 1;
            $interval = $projection?->canonicalInterval($found, $found + strlen($needle));
            if ($projection !== null && $interval === null) {
                continue;
            }
            [$start, $end] = $interval ?? [$found, $found + strlen($needle)];
            $key = $start . ':' . $end;
            if ($key === $previous || !$selector->accepts($unit, $start, $end)) {
                continue;
            }
            if ($projection !== null && $this->project(substr($unit->text(), $start, $end - $start), $mode)->text !== $needle) {
                continue;
            }
            $span = $this->span($unit, $start, $end, $blocksByUnit);
            if ($span !== null) {
                $previous = $key;
                yield $span;
            }
        }
    }

    /** @return array{byteStart:int,byteEnd:int,text:string} UTF-8 safe, and never clips the chosen quote. */
    public function window(string $text, int $start, int $end, int $maxBytes = 8192): array
    {
        if (preg_match('//u', $text) !== 1) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_utf8');
        }
        D\Contract\LocatorConversion::resolveSpan(new D\Contract\JsonObject([
            'text' => $text, 'byteStart' => $start, 'byteEnd' => $end,
        ]));
        if ($maxBytes < 1 || $maxBytes > 8192 || $end - $start > $maxBytes) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        $low = max(0, $start - intdiv($maxBytes - ($end - $start), 2));
        $high = min(strlen($text), $low + $maxBytes);
        $low = max(0, $high - $maxBytes);
        while ($low < $start && (ord($text[$low]) & 0xC0) === 0x80) {
            ++$low;
        }
        while ($high > $end && $high < strlen($text) && (ord($text[$high]) & 0xC0) === 0x80) {
            --$high;
        }

        return ['byteStart' => $low, 'byteEnd' => $high, 'text' => substr($text, $low, $high - $low)];
    }

    private function project(string $text, N\MatchMode $mode): N\Projection
    {
        return $mode === N\MatchMode::Whitespace
            ? (new WhitespaceProjection())->project($text) : $this->nfd()->project($text);
    }

    private function nfd(): NfdNormalizer
    {
        if ($this->normalizer === null || $this->normalizer->readiness() !== 'ready') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_unavailable');
        }

        return $this->normalizer;
    }

    /** @return array<string, list<N\CanonicalBlock>> */
    private function blocksByUnit(CanonicalDocument $document): array
    {
        $blocks = [];
        foreach ($document->blocks as $block) {
            $seen = [];
            foreach ($block->spans as $span) {
                $wire = $span->locator->toWire();
                $kind = $wire->get('kind');
                $key = $kind . ':' . $wire->get($kind === 'page' ? 'pageId' : 'segmentId');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $blocks[$key][] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, list<N\CanonicalBlock>> $blocksByUnit
     */
    private function span(N\CanonicalUnit $unit, int $start, int $end, array $blocksByUnit): ?D\TextLocator
    {
        foreach ($blocksByUnit[$unit->ref->kind . ':' . $unit->ref->id->value] ?? [] as $block) {
            $span = $block->contains($unit, $start, $end);
            if ($span !== null) {
                $span->slice($unit->text());

                return $span;
            }
        }

        return null;
    }
}
