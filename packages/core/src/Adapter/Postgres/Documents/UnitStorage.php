<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Documents;

use KnowKit\Domain\{ResourceId, Ulid};

/** Preallocated persisted unit ID and accepted canonical text resource. */
final readonly class UnitStorage
{
    public function __construct(public Ulid $id, public ResourceId $textResourceId) {}
}
