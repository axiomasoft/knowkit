<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{BlobRef, BlobWriteReservation, Cursor, UtcInstant, ResourcePage, ResourceRef, AttemptId, DeletionReceipt};

interface BlobStore
{
    /** Reservation exists durably before any PUT; issued BlobRef must be accepted and exact. */
    public function putImmutable(BlobWriteReservation $reservation, ReadableStream $stream): BlobRef;
    public function open(BlobRef $ref): ReadableStream;
    public function exists(BlobRef $ref): bool;
    /** Listing is reconciliation only; Cursor carries tenant binding. */
    public function listUnreferenced(UtcInstant $before, Cursor $cursor): ResourcePage;
    public function delete(ResourceRef $resource, AttemptId $attemptId): DeletionReceipt;
}
