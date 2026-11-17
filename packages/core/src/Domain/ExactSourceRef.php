<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

readonly class ExactSourceRef
{
    public function __construct(public TenantId $tenantId, public SourceId $sourceId,
        public SourceVersionId $sourceVersionId, public SourceDigest $sourceDigest) {}

    public function toWire(): JsonObject
    {
        return new JsonObject(['tenantId' => $this->tenantId->value, 'sourceId' => $this->sourceId->value,
            'sourceVersionId' => $this->sourceVersionId->value, 'sourceDigest' => $this->sourceDigest->value]);
    }

    public function assertBytes(string $bytes): void
    {
        if (!hash_equals($this->sourceDigest->value, hash('sha256', $bytes))) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }

    public function equals(self $other): bool
    {
        return $this->tenantId->equals($other->tenantId)
            && $this->sourceId->equals($other->sourceId)
            && $this->sourceVersionId->equals($other->sourceVersionId)
            && $this->sourceDigest->equals($other->sourceDigest);
    }

    public function assertSame(self $other): void
    {
        if (!$this->tenantId->equals($other->tenantId)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        if (!$this->equals($other)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
