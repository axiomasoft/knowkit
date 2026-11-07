<?php
declare(strict_types=1);
namespace KnowKit\Port;
use KnowKit\Domain\{Decimal,DomainEvent,Sha256};

interface EventInbox
{
    /** Atomically persist invalidation and inbox; returns applied|duplicate|gap|blocked. */
    public function apply(DomainEvent $event): string;
    public function quarantine(Sha256 $envelopeDigest): void;
    /** Resolve a version gap only after a tenant-bound authoritative read. Never grants usability. */
    public function reconcile(DomainEvent $event, Decimal $authoritativeVersion): void;
}
