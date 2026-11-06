<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

final readonly class MutationContext extends SchemaValue
{
    protected const SCHEMA = 'mutation-context';

    public function idempotencyKey(): IdempotencyKey { return new IdempotencyKey($this->toWire()->get('idempotencyKey')); }
    public function expectedRevision(): ?Decimal
    {
        $value = $this->toWire()->get('expectedRevision');
        return $value === null ? null : new Decimal($value);
    }
    public function expectedEpoch(): ?Decimal
    {
        $value = $this->toWire()->get('expectedEpoch');
        return $value === null ? null : new Decimal($value);
    }

    /** Under the caller's authoritative locks; replay must not bypass current access checks. */
    public function assertExpected(Decimal $revision, Decimal $epoch): void
    {
        if ($this->expectedRevision() !== null && $this->expectedRevision()->value !== $revision->value) {
            throw new DomainFailure(Outcome::Incompatible, 'stale_write');
        }
        if ($this->expectedEpoch() !== null && $this->expectedEpoch()->value !== $epoch->value) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
    }

    /** PHP canonical request payload, not a new wire format. Transport IDs/time never enter it. */
    public function requestDigest(Operation $operation, JsonObject $businessFields): Fingerprint
    {
        return new Fingerprint(HashDomain::IdempotencyRequest->digest(new JsonObject([
            'operation'=>$operation->value, 'businessFields'=>$businessFields,
            'expectedRevision'=>$this->expectedRevision()?->value, 'expectedEpoch'=>$this->expectedEpoch()?->value,
        ])));
    }
}
