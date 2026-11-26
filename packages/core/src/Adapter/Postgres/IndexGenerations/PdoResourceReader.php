<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\IndexGenerations;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\{BlobStore, ReadableStream};

/** Resolve only accepted immutable object versions. No latest/key-only storage lookup. */
final readonly class PdoResourceReader
{
    public function __construct(private Connection $db, private BlobStore $blobs) {}

    public function open(D\TenantId $tenant, JsonObject $resource): ReadableStream
    {
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Resource reads outside SQL required');
        }
        $r = $this->db->row(
            'SELECT * FROM ' . $this->db->table('resource_ledger') . ' WHERE tenant_id=? AND id=?',
            [$tenant->value, $resource->get('resourceId')],
        );
        if (
            $r === null
            || $r['state'] !== 'accepted'
            || $r['digest'] !== $resource->get('digest')
            || (string) $r['byte_length'] !== $resource->get('byteLength')
            || $r['object_version'] === null
        ) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'resource_unavailable');
        }
        return $this->blobs->open(new D\BlobRef(
            $tenant,
            new D\SourceId((string) $r['source_id']),
            new D\Sha256((string) $r['digest']),
            new D\Uint64((string) $r['byte_length']),
            (string) $r['storage_id'],
            (string) $r['object_version'],
            (string) $r['owner_kind'],
        ));
    }
}
