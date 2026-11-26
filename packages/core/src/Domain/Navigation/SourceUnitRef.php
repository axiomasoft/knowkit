<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

final readonly class SourceUnitRef
{
    public function __construct(
        public string $kind,
        public D\LocalId $id,
        public D\Sha256 $textDigest,
    ) {
        if (!in_array($kind, ['page', 'segment'], true)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_unit');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['kind' => $this->kind, 'id' => $this->id->value, 'textDigest' => $this->textDigest->value]);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->id->equals($other->id)
            && $this->textDigest->equals($other->textDigest);
    }
}
