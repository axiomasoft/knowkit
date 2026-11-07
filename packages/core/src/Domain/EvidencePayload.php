<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Frozen v1 wire payload used by the immutable runtime EvidenceSet. */
final readonly class EvidencePayload extends SchemaValue
{
    protected const SCHEMA = 'evidence-set';
}
