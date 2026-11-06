<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{DomainEvent};

interface Outbox
{
    /** Same caller transaction as the catalog/lifecycle/usage write; immutable envelope conflicts reject. */
    public function append(DomainEvent $event): void;
}
