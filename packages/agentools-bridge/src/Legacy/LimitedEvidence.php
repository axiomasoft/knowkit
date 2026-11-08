<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\ValueGuard;

/** Missing originals never create SourceVersionRef, DocumentRevisionRef or CitationRef. */
final readonly class LimitedEvidence
{
    public const LIMITATIONS = [
        'original_bytes_unavailable',
        'provenance_uncertain',
        'canonical_ir_not_validated',
    ];

    public function __construct(
        public ReleaseIdentity $release,
        public string $resolverRef,
    ) {
        ValueGuard::text(value: $resolverRef, max: 512);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'kind' => 'legacy',
            'tenantId' => $this->release->tenantId->value,
            'legacyHashType' => $this->release->legacyHashKind,
            'legacyReleaseHash' => $this->release->legacyHash,
            'provenance' => 'legacy_unverified',
            'freshlyValidated' => false,
            'resolver' => new JsonObject(['system' => 'agentools', 'ref' => $this->resolverRef]),
        ]);
    }
}
