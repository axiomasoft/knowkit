<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class VersionReceipt
{
    public function __construct(public SourceVersionRef $ref, public EventId $eventId) {}
}
