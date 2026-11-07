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
        $rows = $db->execute('SELECT * FROM '.$db->table('resource_ledger').
            " WHERE tenant_id=? AND source_id=? AND owner_kind='owned' ORDER BY id",
            [$tenant->value, $source->value])->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(function (array $r) use ($db, $tenant): JsonObject {
            $class = $r['resource_class'];
            foreach (['source_versions'=>['blob_resource_id','original'], 'document_revisions'=>['ir_resource_id','ir'],
                'segments'=>['text_resource_id','canonical_slice'], 'pages'=>['text_resource_id','canonical_slice'],
                'chunks'=>['canonical_payload_ref','canonical_slice'], 'assets'=>['resource_id','asset']] as $table => [$column, $kind]) {
                if ($class === 'provider_resource' && $db->row('SELECT id FROM '.$db->table($table).' WHERE tenant_id=? AND '.$column.'=? LIMIT 1',
                    [$tenant->value, $r['id']]) !== null) { $class = $kind; break; }
            }
            if ($class !== 'backup_obligation' && ($r['state'] === 'reserved' || ($r['digest'] === null && $r['staging_id'] !== null))) { $class = 'upload_reservation'; }
            return new JsonObject(['resourceId'=>$r['id'], 'resourceClass'=>$class, 'ownerKind'=>'owned',
                'state'=>$r['state'] === 'unknown' ? 'unknown' : 'pending']);
        }, $rows);
    }
}
