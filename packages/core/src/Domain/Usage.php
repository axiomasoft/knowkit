<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Usage extends SchemaValue
{
    protected const SCHEMA = 'usage';

    public function id(): UsageId
    {
        return new UsageId($this->toWire()->get('usageId'));
    }

    public function checkedAt(): UtcInstant
    {
        return new UtcInstant($this->toWire()->get('checkedAt'));
    }

    public function tenantId(): TenantId
    {
        return new TenantId($this->toWire()->get('tenantId'));
    }

    public function attemptId(): Ulid
    {
        return new Ulid($this->toWire()->get('disclosureAttemptId'));
    }

    public function selectionId(): SelectionId
    {
        return new SelectionId($this->toWire()->get('selectionId'));
    }

    public function actorId(): ActorId
    {
        return new ActorId($this->toWire()->get('actorId'));
    }

    public function purpose(): string
    {
        return $this->toWire()->get('purpose');
    }

    public function hostScopeHash(): Sha256
    {
        return new Sha256($this->toWire()->get('hostScopeHash'));
    }

    public function runRef(): ?string
    {
        return $this->toWire()->get('runRef');
    }

    public function policyBindingDigest(): Sha256
    {
        return new Sha256($this->toWire()->get('policyBindingDigest'));
    }
}
