<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\Jcs;

/** Per-transaction identity consistency helper for repository adapters; not a catalog or authority. */
final class ExactIdentityGuard
{
    /** @var array<string, string> */
    private array $known = [];

    public function __construct(private readonly TenantId $tenantId) {}

    public function remember(ExactSourceRef|DocumentRevisionRef|ChunkRef $ref): void
    {
        if ($ref instanceof ChunkRef) {
            $this->remember($ref->revisionRef);
            $wire = $ref->toWire();
            $id = 'chunk:' . $wire->get('tenantId') . ':' . $ref->chunkId->value;
        } elseif ($ref instanceof DocumentRevisionRef) {
            $this->remember($ref->sourceRef);
            $wire = $ref->toWire();
            $id = 'revision:' . $wire->get('tenantId') . ':' . $ref->documentRevisionId->value;
        } else {
            $wire = $ref->toWire();
            $id = 'source-version:' . $ref->tenantId->value . ':' . $ref->sourceVersionId->value;
        }
        if ($wire->get('tenantId') !== $this->tenantId->value) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $identity = Jcs::canonicalize($wire);
        if (isset($this->known[$id]) && !hash_equals($this->known[$id], $identity)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $this->known[$id] = $identity;
    }
}
