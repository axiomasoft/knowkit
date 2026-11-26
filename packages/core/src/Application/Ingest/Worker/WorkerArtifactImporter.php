<?php

declare(strict_types=1);

namespace KnowKit\Application\Ingest\Worker;

use KnowKit\Application\Resources\Upload;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\BlobStore;
use KnowKit\Port\IdGenerator;

/** Durable ledger and checkpoint persistence are bound by the host, as for Upload/VerifiedCheckpoints. */
final readonly class WorkerArtifactImporter
{
    /**
     * @param \Closure(D\BlobWriteReservation, D\JobId): D\BlobWriteReservation $reserveWriter
     * @param \Closure(D\BlobRef): void $requireAccepted
     * @param \Closure(D\BlobWriteReservation): void $markUncertain
     * @param \Closure(D\JobCheckpointManifest): void $saveCheckpoint Durable immutable manifest storage.
     */
    public function __construct(
        private BlobStore $blobs,
        private IdGenerator $ids,
        private \Closure $reserveWriter,
        private \Closure $requireAccepted,
        private \Closure $markUncertain,
        private \Closure $saveCheckpoint,
    ) {
    }

    public function reserve(D\Lease $lease): D\BlobWriteReservation
    {
        $reservation = $this->reservation($lease);
        $stored = ($this->reserveWriter)($reservation, $lease->jobId);
        if (!$stored->equals($reservation)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'reservation_mismatch');
        }
        return $stored;
    }

    public function abandon(D\BlobWriteReservation $reservation): void
    {
        // Expiry or rejected bytes are not proof that a durable resource never existed.
        ($this->markUncertain)($reservation);
    }

    public function import(D\Lease $lease, D\BlobWriteReservation $reserved, D\WorkerOutput $output,
        D\WorkerJobContext $context): D\JobCheckpointManifest
    {
        // JobCheckpointManifest permits 128 independently verified resource receipts.
        if (count($output->assets) > 126) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'output_limit');
        }
        $resources = [$this->put($lease, $reserved, $output->ir->canonical())];
        $resources[] = $this->put($lease, $this->reservation($lease), $output->result->canonical());
        foreach ($output->assets as $bytes) {
            $resources[] = $this->put($lease, $this->reservation($lease), $bytes);
        }
        usort($resources, static fn (JsonObject $a, JsonObject $b): int => strcmp($a->get('resourceId'), $b->get('resourceId')));
        $manifest = new D\JobCheckpointManifest(new JsonObject([
            'schemaVersion' => 'job-checkpoint-1.0',
            'tenantId' => $lease->scope->tenantId->value,
            'kbId' => $lease->scope->kbId->value,
            'sourceId' => $lease->sourceRef->sourceId->value,
            'sourceVersionId' => $lease->sourceRef->sourceVersionId->value,
            'jobId' => $lease->jobId->value,
            'sourceEpoch' => $lease->sourceEpoch->value,
            'processingDigest' => $context->processingDigest->value,
            'stage' => $context->stage->value,
            'inputDigest' => $context->inputDigest->value,
            'outputDigest' => HashDomain::ResourceInventory->digest($resources),
            'uncertainExternalEffect' => false,
            'resources' => $resources,
        ]));
        ($this->saveCheckpoint)($manifest);
        return $manifest;
    }

    private function reservation(D\Lease $lease): D\BlobWriteReservation
    {
        return new D\BlobWriteReservation(
            new D\ResourceRef($lease->scope->tenantId, 'resource', new D\ResourceId($this->ids->next()->value)),
            $lease->sourceRef->sourceId,
            new D\WriterId($this->ids->next()->value),
            $lease->fence,
            $lease->sourceEpoch,
            null,
            null,
            $this->ids->next(),
        );
    }

    private function put(D\Lease $lease, D\BlobWriteReservation $reservation, string $bytes): JsonObject
    {
        $upload = new Upload(
            $this->blobs,
            fn (D\BlobWriteReservation $r): D\BlobWriteReservation => ($this->reserveWriter)($r, $lease->jobId),
            $this->requireAccepted,
            $this->markUncertain,
        );
        $ref = $upload->put($reservation, new WorkerBytes($bytes));
        if (!hash_equals(hash('sha256', $bytes), $ref->digest->value) || (string) strlen($bytes) !== $ref->byteLength->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }
        return new JsonObject([
            'resourceId' => $reservation->resource->id->value,
            'digest' => $ref->digest->value,
            'byteLength' => $ref->byteLength->value,
        ]);
    }
}
