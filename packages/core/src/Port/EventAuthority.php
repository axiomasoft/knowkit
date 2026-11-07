<?php
declare(strict_types=1);
namespace KnowKit\Port;
use KnowKit\Domain\{Decimal,DomainEvent};

interface EventAuthority
{
    /** Current committed metadata version; null means unknown and keeps the projection stale. */
    public function version(DomainEvent $event): ?Decimal;
}
