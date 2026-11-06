<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class LifecycleEvent
{
    public function __construct(public DomainEvent $event)
    {
        if (!in_array($event->toWire()->get('type'), ['source_version.corrected','source.revoked',
            'source.deletion_requested','retention_hold.changed'], true)) {
            throw new \InvalidArgumentException('Lifecycle event required');
        }
    }
}
