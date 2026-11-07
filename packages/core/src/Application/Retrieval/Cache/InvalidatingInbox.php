<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Cache;

use KnowKit\Domain as D;
use KnowKit\Port\EventInbox;
use KnowKit\Port\ReferenceCache;

/** Decorate the durable inbox used by Events\Consumer; duplicates/gaps also evict safely. */
final readonly class InvalidatingInbox implements EventInbox
{
    public function __construct(
        private D\TenantId $tenant,
        private EventInbox $inbox,
        private ReferenceCache $cache,
    ) {}

    public function apply(D\DomainEvent $event): string
    {
        $this->invalidate($event);

        return $this->inbox->apply($event);
    }

    public function quarantine(D\Sha256 $envelopeDigest): void
    {
        $this->inbox->quarantine($envelopeDigest);
    }

    public function reconcile(D\DomainEvent $event, D\Decimal $authoritativeVersion): void
    {
        $this->invalidate($event);
        $this->inbox->reconcile($event, $authoritativeVersion);
    }

    private function invalidate(D\DomainEvent $event): void
    {
        if ($event->tenantId() != $this->tenant) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        // Before durable acknowledgement: a failed eviction remains retryable.
        $this->cache->invalidate($this->tenant);
    }
}
