<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain as D;

/** Canonical disclosure bytes and their immutable, already accepted source spans. */
final readonly class GroundingFragment
{
    public function __construct(
        public D\CitationRef $citation,
        public string $text,
        public ?string $joiner,
        public string $provenanceKind,
    ) {
        Guard::utf8($text, 16384, false);
        // Length sums alone accept a substituted joiner or a span textDigest that is not the slice.
        $spans = $citation->toWire()->get('spans');
        if (!is_array($spans) || !array_is_list($spans) || $spans === []
            || ($joiner !== null) !== (count($spans) > 1)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_evidence_invalid');
        }
        $offset = 0;
        $limit = strlen($text);
        foreach ($spans as $index => $span) {
            $start = $span instanceof D\Contract\JsonObject ? $span->get('byteStart') : null;
            $end = $span instanceof D\Contract\JsonObject ? $span->get('byteEnd') : null;
            $digest = $span instanceof D\Contract\JsonObject ? $span->get('textDigest') : null;
            if (!is_int($start) || !is_int($end) || $end <= $start || !is_string($digest) || strlen($digest) !== 64) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_evidence_invalid');
            }
            $length = $end - $start;
            if ($offset > $limit - $length || self::splitsCodepoint($text, $offset) || self::splitsCodepoint($text, $offset + $length)
                || !hash_equals($digest, hash('sha256', substr($text, $offset, $length)))) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_evidence_invalid');
            }
            $offset += $length;
            if ($index === count($spans) - 1) {
                continue;
            }
            $join = strlen($joiner ?? '');
            if ($join < 1 || $offset > $limit - $join || substr($text, $offset, $join) !== $joiner) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_evidence_invalid');
            }
            $offset += $join;
        }
        $content = $citation->contentDigest()->value;
        if ($offset !== $limit || strlen($content) !== 64 || !hash_equals($content, hash('sha256', $text))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_evidence_invalid');
        }
    }

    /** Half-open offsets must land on UTF-8 codepoint boundaries of the joined text. */
    private static function splitsCodepoint(string $text, int $offset): bool
    {
        return $offset < strlen($text) && (ord($text[$offset]) & 0xC0) === 0x80;
    }

    public function source(): D\SourceVersionRef
    {
        $wire = $this->citation->toWire();

        return new D\SourceVersionRef(
            new D\TenantId($wire->get('tenantId')),
            new D\SourceId($wire->get('sourceId')),
            new D\SourceVersionId($wire->get('sourceVersionId')),
            new D\SourceDigest($wire->get('sourceDigest')),
        );
    }

    public function __debugInfo(): array
    {
        return ['citationId' => $this->citation->id(), 'bytes' => strlen($this->text)];
    }
}
