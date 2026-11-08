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
        $spans = $citation->toWire()->get('spans');
        $length = array_sum(array_map(static fn ($span): int => $span->get('byteEnd') - $span->get('byteStart'), $spans));
        $length += (count($spans) - 1) * strlen($joiner ?? '');
        if ($citation->contentDigest()->value !== hash('sha256', $text) || $length !== strlen($text)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_evidence_invalid');
        }
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
