<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\GenerationId;
use KnowKit\Domain\Scope;
use KnowKit\Domain\SelectionInventory;
use KnowKit\Domain\SnapshotRef;

interface SelectionCatalog
{
    /** Verify sealed exact snapshot, manifest digest and generation membership; never resolve latest. */
    public function load(Scope $scope, SnapshotRef $snapshot, GenerationId $generation): SelectionInventory;
}
