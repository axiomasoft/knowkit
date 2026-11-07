<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\IrDocument;
use KnowKit\Domain\ManifestCandidate;
use KnowKit\Domain\SelectionInventory;
use KnowKit\Domain\SnapshotRef;

/** Canonical verification injected at composition; adapters do not import application implementations. */
interface SelectionInventoryBuilder
{
    /** @param array<string, IrDocument> $documents */
    public function build(SnapshotRef $snapshot, ManifestCandidate $manifest, array $documents): SelectionInventory;
}
