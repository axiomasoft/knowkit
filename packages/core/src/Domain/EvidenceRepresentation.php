<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** A search-only whitespace representation with explicit ranges into immutable citation spans. */
final readonly class EvidenceRepresentation
{
    private FrozenJson $value;

    public function __construct(JsonObject $fragment)
    {
        $text = $fragment->get('text');
        if (preg_match('//u', $text) !== 1) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_utf8');
        }
        preg_match_all('/\s+|[^\s]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $normalized = '';
        $mapping = [];
        foreach ($matches[0] as [$token, $start]) {
            $whitespace = preg_match('/^\s+$/u', $token) === 1;
            if ($whitespace && ($normalized === '' || $start + strlen($token) === strlen($text))) {
                continue;
            }
            $value = $whitespace ? ' ' : $token;
            $mapping[] = new JsonObject([
                'normalizedByteStart' => strlen($normalized),
                'normalizedByteEnd' => strlen($normalized) + strlen($value),
                'sourceRanges' => $this->ranges($fragment, $start, $start + strlen($token)),
            ]);
            $normalized .= $value;
        }
        $this->value = new FrozenJson(new JsonObject([
            'revision' => 'canonical-whitespace:1',
            'citationId' => $fragment->get('citation')->get('citationId'),
            'fragmentDigest' => $fragment->get('fragmentDigest'),
            'normalizedText' => $normalized,
            'mapping' => $mapping,
        ]));
    }

    public function toWire(): JsonObject
    {
        return $this->value->toWire();
    }

    /**
     * Joiner bytes have no source range; source ranges never bridge separate page/segment spans.
     *
     * @return list<JsonObject>
     */
    private function ranges(JsonObject $fragment, int $start, int $end): array
    {
        $ranges = [];
        $offset = 0;
        foreach ($fragment->get('citation')->get('spans') as $index => $span) {
            $length = $span->get('byteEnd') - $span->get('byteStart');
            $left = max($start, $offset);
            $right = min($end, $offset + $length);
            if ($left < $right) {
                $ranges[] = new JsonObject(['spanIndex' => $index,
                    'byteStart' => $span->get('byteStart') + $left - $offset,
                    'byteEnd' => $span->get('byteStart') + $right - $offset]);
            }
            $offset += $length + strlen($fragment->get('joiner') ?? '');
        }

        return $ranges;
    }
}
