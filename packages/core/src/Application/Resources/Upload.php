<?php
declare(strict_types=1);

namespace KnowKit\Application\Resources;

use KnowKit\Domain\{BlobRef, BlobWriteReservation, DomainFailure, Outcome};
use KnowKit\Port\{BlobStore, ReadableStream};

/** The external BlobStore seam has the same durable reservation and uncertainty obligations as LocalBlob. */
final readonly class Upload
{
    public function __construct(
        private BlobStore $store,
        private \Closure $reserveWriter,
        private \Closure $requireAccepted,
        private \Closure $markUncertain,
    ) {
    }

    public function put(BlobWriteReservation $reservation, ReadableStream $stream): BlobRef
    {
        $r = null;
        try {
            $r = ($this->reserveWriter)($reservation);
            if (!$r instanceof BlobWriteReservation) {
                throw new \LogicException('Durable reservation required');
            }
            $ref = $this->store->putImmutable($r, $stream);
            if (
                !$ref->tenantId->equals($r->resource->tenantId)
                || !$ref->sourceId->equals($r->sourceId)
                || ($r->digest !== null && ($ref->digest->value !== $r->digest->value || $ref->byteLength->value !== $r->byteLength?->value))
            ) {
                throw new DomainFailure(Outcome::Incompatible, 'blob_identity_mismatch');
            }
            ($this->requireAccepted)($ref);
            return $ref;
        } catch (\Throwable $e) {
            // An upload timeout never proves absence or quiescence, even if a lease has expired.
            if ($r instanceof BlobWriteReservation) {
                ($this->markUncertain)($r);
            }
            throw $e;
        } finally {
            $stream->close();
        }
    }
}
