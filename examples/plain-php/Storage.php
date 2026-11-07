<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\LocalBlob\LocalBlobStore;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Domain as D;

final readonly class Storage
{
    public LocalBlobStore $blobs;
    private PdoResourceLedger $ledger;

    public function __construct(
        private Host $host,
        PdoCatalogTransaction $tx,
        string $root,
    ) {
        $tenant = $host->scope()->tenantId;
        $this->ledger = new PdoResourceLedger($host->db, $tx, $tenant, 'local', $host);
        $callbacks = [];
        foreach (['inspectWriter', 'finalizeIdentity', 'closeWriter', 'markUncertain', 'requireAccepted',
            'listUnreferenced', 'quarantine', 'recoveryRef', 'reconcileWriter'] as $method) {
            $callbacks[$method] = $this->ledger->$method(...);
        }
        $this->blobs = new LocalBlobStore($tenant, $root, 'local', 8388608, $callbacks,
            function (D\BlobRef $ref) use ($host): void {
                $host->scope()->assertTenant($ref->tenantId);
                if ($host->decision !== D\Decision::Allow) {
                    throw new D\DomainFailure(D\Outcome::Denied, 'access_denied');
                }
            }, static function (): never {
                throw new D\DomainFailure(D\Outcome::Unavailable, 'deletion_binding_required');
            }, $host, $host, $host->validator);
    }

    public function put(D\SourceId $source, string $bytes, D\Decimal $epoch, string $identity): D\BlobRef
    {
        $tenant = $this->host->scope()->tenantId;
        $resource = new D\ResourceId(Host::id($tenant->value . $source->value . $identity));
        $reservation = new D\BlobWriteReservation(new D\ResourceRef($tenant, 'resource', $resource),
            $source, new D\WriterId(Host::id($resource->value . ':writer')), new D\Decimal('0'), $epoch,
            new D\Sha256(hash('sha256', $bytes)), new D\Uint64((string) strlen($bytes)), null);
        $this->ledger->reserveWriter($reservation);

        return $this->blobs->putImmutable($reservation, new Bytes($bytes));
    }

    public function openResource(D\TenantId $tenant, D\ResourceId $resource): \KnowKit\Port\ReadableStream
    {
        $this->host->scope()->assertTenant($tenant);
        $row = $this->host->db->row('SELECT * FROM resource_ledger WHERE tenant_id=? AND id=?',
            [$tenant->value, $resource->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'resource_missing');
        }

        return $this->blobs->open(new D\BlobRef($tenant, new D\SourceId($row['source_id']),
            new D\Sha256($row['digest']), new D\Uint64((string) $row['byte_length']),
            $row['storage_id'], $row['object_version'], $row['owner_kind']));
    }
}
