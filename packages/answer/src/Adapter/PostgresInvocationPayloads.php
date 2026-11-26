<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter;

use DateTimeImmutable;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Answer\Domain\DependencyManifest;
use KnowKit\Answer\Domain\InvocationPayloadRef;
use KnowKit\Answer\Port\InvocationPayloads;
use KnowKit\Application\Resources\Upload;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\BlobStore;
use KnowKit\Port\Clock;
use KnowKit\Port\IdGenerator;

/** Reuses core reservation, writer uncertainty, immutable PUT and verified reads.
 * A host-configured Source owns these resources; dependency metadata never grants access.
 * Pending/abandoned uploads remain ResourceLedger obligations for lifecycle reconciliation.
 */
final readonly class PostgresInvocationPayloads implements InvocationPayloads
{
    private const RESERVE_SQL = <<<'SQL'
        INSERT INTO %s
          (tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,
           digest,byte_length,state,ref_count,resource_class,retention_until)
        VALUES (?,?,?,'owned',?,?,?,?,?,'reserved',1,'provider_resource',?)
        SQL;

    private const REGISTER_SQL = <<<'SQL'
        INSERT INTO %s
          (tenant_id,resource_id,kb_id,digest,kind,dependencies_json,retention_deadline)
        VALUES (?,?,?,?,?,?::jsonb,?)
        SQL;

    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private PdoResourceLedger $resources,
        private BlobStore $blobs,
        private IdGenerator $ids,
        private Clock $clock,
        private D\TenantId $tenant,
        private D\SourceId $owner,
        private string $storageId,
        private ContractValidator $validator,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    public function put(
        D\Scope $scope,
        string $kind,
        string $bytes,
        DependencyManifest $dependencies,
        DateTimeImmutable $retention,
    ): InvocationPayloadRef {
        $this->outsideTransaction();
        $scope->assertTenant($this->tenant);
        $dependencies->requireScope($scope);
        if (strlen($bytes) > 1048576 || $retention <= new DateTimeImmutable($this->clock->now()->value)) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_unavailable');
        }
        $reservation = $this->reserve($scope, $bytes, $dependencies, $retention);
        $upload = new Upload($this->blobs, $this->resources->reserveWriter(...),
            $this->resources->requireAccepted(...), $this->resources->markUncertain(...));
        $blob = $upload->put($reservation, new PayloadBytes($bytes));
        $this->tx->run(function () use ($scope, $kind, $blob, $dependencies, $retention, $reservation): void {
            $this->guards($scope, $dependencies);
            $this->db->execute(sprintf(self::REGISTER_SQL, $this->db->table('knowkit_answer_payloads')), [
                $this->tenant->value, $reservation->resource->id->value, $scope->kbId->value,
                $blob->digest->value, $kind, Jcs::canonicalize($dependencies->toWire()), $retention->format('c'),
            ]);
        });

        return new InvocationPayloadRef($this->tenant, new D\ResourceId($reservation->resource->id->value), $blob->digest);
    }

    private function reserve(D\Scope $scope, string $bytes, DependencyManifest $dependencies, DateTimeImmutable $retention): D\BlobWriteReservation
    {
        $id = new D\ResourceId($this->ids->next()->value);
        $resource = new D\ResourceRef($this->tenant, 'resource', $id);
        $writer = new D\WriterId($this->ids->next()->value);
        $digest = new D\Sha256(hash('sha256', $bytes));
        $length = new D\Uint64((string) strlen($bytes));

        return $this->tx->run(function () use ($scope, $dependencies, $retention, $resource, $writer, $digest, $length): D\BlobWriteReservation {
            $this->guards($scope, $dependencies);
            $source = $this->db->row('SELECT lifecycle_epoch FROM ' . $this->db->table('sources') . ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $this->owner->value]);
            if ($source === null) {
                throw new D\DomainFailure(D\Outcome::Denied, 'payload_source_unavailable');
            }
            $r = new D\BlobWriteReservation($resource, $this->owner, $writer, new D\Decimal('0'),
                new D\Decimal((string) $source['lifecycle_epoch']), $digest, $length, null);
            // The acceptance reference exists before PUT, including the gap before writer creation.
            $this->db->execute(sprintf(self::RESERVE_SQL, $this->db->table('resource_ledger')), [
                $this->tenant->value, $resource->id->value, $this->owner->value, $this->storageId,
                $this->resources->stagingKey($r), $resource->id->value, $digest->value, $length->value, $retention->format('c'),
            ]);

            return $r;
        });
    }

    private function guards(D\Scope $scope, DependencyManifest $dependencies): void
    {
        $this->tx->lockTenant($this->tenant, D\LockMode::Update);
        $this->tx->lockKb($scope->kbId, D\LockMode::Share);
        $sources = [$this->owner->value => $this->owner];
        foreach ($dependencies->sourceIds() as $id) {
            $sources[$id->value] = $id;
        }
        ksort($sources, SORT_STRING);
        $this->tx->lockSources(array_values($sources), D\LockMode::Share);
        $this->requireSource($this->owner);
    }

    private function requireSource(D\SourceId $source): void
    {
        $row = $this->db->row('SELECT s.id FROM ' . $this->db->table('sources') . ' s JOIN '
            . $this->db->table('tenants') . " t ON t.id=s.tenant_id WHERE s.tenant_id=? AND s.id=?
            AND t.serving_state='ready' AND s.lifecycle_state='active' AND NOT EXISTS
            (SELECT 1 FROM " . $this->db->table('tombstones') . ' z WHERE z.tenant_id=s.tenant_id AND z.source_id=s.id)',
            [$this->tenant->value, $source->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Denied, 'payload_source_unavailable');
        }
    }

    public function dependencies(InvocationPayloadRef $ref): DependencyManifest
    {
        $row = $this->metadata($ref);

        return DependencyManifest::fromWire(StrictJson::decode($row['dependencies_json']), $this->validator);
    }

    public function read(D\Scope $scope, InvocationPayloadRef $ref): string
    {
        $this->outsideTransaction();
        $scope->assertTenant($ref->tenantId);
        $row = $this->metadata($ref);
        if ($row['kb_id'] !== $scope->kbId->value || $row['state'] !== 'accepted'
            || new DateTimeImmutable($row['retention_deadline']) <= new DateTimeImmutable($this->clock->now()->value)) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_unavailable');
        }
        $dependencies = DependencyManifest::fromWire(StrictJson::decode($row['dependencies_json']), $this->validator);
        $this->tx->run(fn () => $this->guards($scope, $dependencies));
        $blob = new D\BlobRef($this->tenant, new D\SourceId($row['source_id']), $ref->digest,
            new D\Uint64((string) $row['byte_length']), $row['storage_id'], $row['object_version'], $row['owner_kind']);
        $stream = $this->blobs->open($blob);
        $bytes = '';
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(max(1, min(8192, 1048576 + 1 - strlen($bytes))));
                if ($chunk === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_unavailable');
                }
                if (strlen($bytes) + strlen($chunk) > 1048576) {
                    throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_unavailable');
                }
                $bytes .= $chunk;
            }
        } finally {
            $stream->close();
        }
        $this->requireBytes($bytes, $blob);

        return $bytes;
    }

    private function requireBytes(string $bytes, D\BlobRef $blob): void
    {
        if ((string) strlen($bytes) !== $blob->byteLength->value
            || !hash_equals($blob->digest->value, hash('sha256', $bytes))) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_unavailable');
        }
    }

    private function metadata(InvocationPayloadRef $ref): array
    {
        if (!$ref->tenantId->equals($this->tenant)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        $row = $this->db->row('SELECT r.*,p.kb_id,p.kind,p.retention_deadline,p.dependencies_json FROM '
            . $this->db->table('knowkit_answer_payloads') . ' p JOIN ' . $this->db->table('resource_ledger')
            . ' r ON r.tenant_id=p.tenant_id AND r.id=p.resource_id AND r.digest=p.digest
            WHERE p.tenant_id=? AND p.resource_id=? AND p.digest=?', [$this->tenant->value, $ref->resourceId->value, $ref->digest->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_unavailable');
        }

        return $row;
    }

    private function outsideTransaction(): void
    {
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Payload I/O outside SQL required');
        }
    }
}
