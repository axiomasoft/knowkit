<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Deletion;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** SQL-only enumeration under the caller's tenant/source guards; configuration is never the inventory. */
final class Inventory
{
    /** @return list<JsonObject> */
    public static function required(Connection $db, D\TenantId $tenant, D\SourceId $source): array
    {
        $rows = $db->execute(
            'SELECT r.id,r.state,r.digest,r.staging_id,r.resource_class,
                EXISTS (SELECT 1 FROM ' . $db->table('source_versions') . ' v WHERE v.tenant_id=r.tenant_id AND v.blob_resource_id=r.id) AS is_original,
                EXISTS (SELECT 1 FROM ' . $db->table('document_revisions') . ' d WHERE d.tenant_id=r.tenant_id AND d.ir_resource_id=r.id) AS is_ir,
                EXISTS (SELECT 1 FROM ' . $db->table('segments') . ' s WHERE s.tenant_id=r.tenant_id AND s.text_resource_id=r.id) AS is_segment,
                EXISTS (SELECT 1 FROM ' . $db->table('pages') . ' p WHERE p.tenant_id=r.tenant_id AND p.text_resource_id=r.id) AS is_page,
                EXISTS (SELECT 1 FROM ' . $db->table('chunks') . ' c WHERE c.tenant_id=r.tenant_id AND c.canonical_payload_ref=r.id) AS is_chunk,
                EXISTS (SELECT 1 FROM ' . $db->table('assets') . ' a WHERE a.tenant_id=r.tenant_id AND a.resource_id=r.id) AS is_asset
             FROM ' . $db->table('resource_ledger') . " r
             WHERE r.tenant_id=? AND r.source_id=? AND r.owner_kind='owned' ORDER BY r.id",
            [$tenant->value, $source->value],
        )->fetchAll(\PDO::FETCH_ASSOC);
        return array_values(array_map(function (array $r): JsonObject {
            $class = (string) $r['resource_class'];
            if ($class === 'provider_resource') {
                $class = match (true) {
                    $r['is_original'] === true => 'original',
                    $r['is_ir'] === true => 'ir',
                    $r['is_segment'] === true, $r['is_page'] === true, $r['is_chunk'] === true => 'canonical_slice',
                    $r['is_asset'] === true => 'asset',
                    default => $class,
                };
            }
            if ($class !== 'backup_obligation' && ($r['state'] === 'reserved' || ($r['digest'] === null && $r['staging_id'] !== null))) {
                $class = 'upload_reservation';
            }
            return new JsonObject([
                'resourceId' => $r['id'],
                'resourceClass' => $class,
                'ownerKind' => 'owned',
                'state' => $r['state'] === 'unknown' ? 'unknown' : 'pending',
            ]);
        }, $rows));
    }
}
