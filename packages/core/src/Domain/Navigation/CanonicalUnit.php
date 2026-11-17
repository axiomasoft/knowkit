<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Typed view of an immutable IR page/segment. It never invents a page for a segment. */
final readonly class CanonicalUnit
{
    public function __construct(
        public SourceUnitRef $ref,
        public ?int $physicalNo,
        public ?string $printedLabel,
        public string $status,
        private string $text,
    ) {
        if (($ref->kind === 'page' && ($physicalNo === null || $physicalNo < 1 || $physicalNo > 4294967295))
            || ($ref->kind !== 'page' && ($physicalNo !== null || $printedLabel !== null))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_unit');
        }
        if (preg_match('//u', $text) !== 1 || hash('sha256', $text) !== $ref->textDigest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }
    }

    public static function fromWire(JsonObject $wire, string $kind): self
    {
        return new self(
            new SourceUnitRef($kind, new D\LocalId($wire->get($kind === 'page' ? 'pageId' : 'segmentId')),
                new D\Sha256($wire->get('textDigest'))),
            $wire->get('physicalNo'),
            $wire->get('printedLabel'),
            $wire->get('status'),
            $wire->get('text'),
        );
    }

    public function text(): string
    {
        if (!in_array($this->status, ['ok', 'empty'], true)) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'unit_unavailable');
        }

        return $this->text;
    }

    public function __debugInfo(): array
    {
        return ['ref' => $this->ref, 'status' => $this->status];
    }
}
