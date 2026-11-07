<?php
declare(strict_types=1);
namespace KnowKit\Port;
use KnowKit\Domain\DomainEvent;

interface EventTransport
{
    /** Bounded send, after lease commit. Timeout may mean the consumer already applied the event. */
    public function send(DomainEvent $event): void;
}
