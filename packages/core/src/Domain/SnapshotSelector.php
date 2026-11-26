<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Explicit active pointer lookup or an immutable historical ref. Neither permits profile fallback. */
final readonly class SnapshotSelector
{
    private function __construct(public ?SnapshotRef $ref) {}
    public static function active(): self { return new self(null); }
    public static function exact(SnapshotRef $ref): self { return new self($ref); }

    public function assertScope(TenantId $tenantId, KbId $kbId): void
    {
        if ($this->ref !== null && (!$this->ref->tenantId->equals($tenantId) || !$this->ref->kbId->equals($kbId))) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    public function assertResolved(PublicationSnapshot $snapshot): void
    {
        $this->assertScope($snapshot->ref->tenantId, $snapshot->ref->kbId);
        if ($this->ref !== null && !$this->ref->equals($snapshot->ref)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
