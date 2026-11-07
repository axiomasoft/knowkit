<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain\{IndexManifest, PersistedIndexRead};

/** Reload persisted state and query deterministic stored data; never call an embedding provider.
 * Return the complete exact inventory, including duplicates, so the caller can reject it.
 * An unreadable/unsupported backend throws unavailable/incompatible, never an empty-success substitute.
 */
interface GenerationReadBackend
{
    public function reload(IndexManifest $manifest): PersistedIndexRead;
}
