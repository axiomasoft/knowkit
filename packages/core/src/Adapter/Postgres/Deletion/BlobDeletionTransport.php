<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Deletion;

use KnowKit\Domain as D;
use KnowKit\Port\{BlobStore, DeletionTransport};

/** Host supplies a qualified BlobStore and its explicit exact-version capability. */
final readonly class BlobDeletionTransport implements DeletionTransport
{
    public function __construct(private BlobStore $store, private string $storageId, private bool $exactVersionProof) {}
    public function provesExactVersion(D\DeletionTarget $target): bool
    { return $this->exactVersionProof && $target->storageId === $this->storageId && $target->storageKey !== null
        && ($target->objectVersion !== null || $target->stagingId !== null); }
    public function delete(D\DeletionTarget $target, D\AttemptId $attempt): D\DeletionReceipt
    {
        if ($target->storageId !== $this->storageId) { throw new D\DomainFailure(D\Outcome::Incompatible, 'blob_store_mismatch'); }
        return $this->store->delete($target->resource(), $attempt);
    }
}
