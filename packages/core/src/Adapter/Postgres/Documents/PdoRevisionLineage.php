<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Documents;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{DocumentRevisionId, DocumentRevisionRef, DomainFailure, Outcome, Parents, ResourceRef, SourceVersionId, TenantId};
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};

/** Revision/source-version lineage only. The general lifecycle DependencyStore remains separate. */
final readonly class PdoRevisionLineage
{
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx)
    {
        if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared transaction required'); }
    }

    public function register(DocumentRevisionRef $revision, Parents $parents): void
    {
        $tenant = $revision->sourceRef->tenantId;
        $this->tx->requireTenant($tenant, true);
        if (!$parents->tenantId->equals($tenant)) { self::fail('scope_mismatch', Outcome::Denied); }
        $sourceParent = false;
        foreach ($parents->refs as $parent) {
            if ($parent->id->value === $revision->documentRevisionId->value) { self::fail('self_reference'); }
            if ($parent->kind === 'source_version' && $parent->id->equals($revision->sourceRef->sourceVersionId)) { $sourceParent = true; }
        }
        if (!$sourceParent) { self::fail('required_parent_missing'); }
        // The source-version root can be registered only from real accepted catalog metadata.
        $root = $this->sourceWire($tenant, $revision->sourceRef->sourceVersionId->value);
        if (Jcs::canonicalize($root) !== Jcs::canonicalize($revision->sourceRef->toWire())) { self::fail('immutable_conflict'); }
        $this->node($tenant, $revision->sourceRef->sourceVersionId->value, 'source_version', $root,
            $revision->sourceRef->sourceId->value, $revision->sourceRef->sourceVersionId->value, new Parents($tenant, []));
        $this->assertUsable($tenant, $parents);
        $this->node($tenant, $revision->documentRevisionId->value, 'document_revision', $revision->toWire(),
            $revision->sourceRef->sourceId->value, $revision->sourceRef->sourceVersionId->value, $parents);
        foreach ($parents->refs as $parent) {
            $this->db->execute('INSERT INTO ' . $this->db->table('dependency_edges') .
                '(tenant_id,child_id,parent_id,relation) VALUES (?,?,?,\'required\') ON CONFLICT DO NOTHING',
                [$tenant->value, $revision->documentRevisionId->value, $parent->id->value]);
        }
        $actual = $this->parents($tenant, $revision->documentRevisionId->value);
        if (!$actual->digest()->equals($parents->digest())) { self::fail('immutable_conflict'); }
    }

    private function node(TenantId $tenant, string $id, string $kind, JsonObject $identity, string $source, string $version, Parents $parents): void
    {
        $digest = HashDomain::DependencyNodeIdentity->digest($identity);
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('dependency_nodes') . ' WHERE tenant_id=? AND id=?', [$tenant->value, $id]);
        if ($row !== null) {
            if ($row['kind'] !== $kind || $row['source_id'] !== $source || $row['source_version_id'] !== $version
                || $row['identity_digest'] !== $digest || $row['parent_set_digest'] !== $parents->digest()->value) { self::fail('immutable_conflict'); }
            if ($row['invalidated_epoch'] !== null) { self::fail('ancestor_unusable', Outcome::Denied); }
            return;
        }
        $this->db->execute('INSERT INTO ' . $this->db->table('dependency_nodes') .
            '(tenant_id,id,kind,source_id,source_version_id,identity_digest,parent_set_digest) VALUES (?,?,?,?,?,?,?)',
            [$tenant->value, $id, $kind, $source, $version, $digest, $parents->digest()->value]);
    }

    public function parents(TenantId $tenant, string $id): Parents
    {
        $this->tx->requireTenant($tenant);
        $refs = [];
        foreach ($this->db->execute('SELECT n.kind,n.id,e.relation FROM ' . $this->db->table('dependency_edges') . ' e JOIN ' .
            $this->db->table('dependency_nodes') . ' n ON n.tenant_id=e.tenant_id AND n.id=e.parent_id WHERE e.tenant_id=? AND e.child_id=? ORDER BY n.id',
            [$tenant->value, $id])->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['relation'] !== 'required') { self::fail('immutable_conflict'); }
            $refs[] = $this->ref($tenant, $row['kind'], $row['id']);
        }
        return new Parents($tenant, $refs);
    }

    public function assertUsable(TenantId $tenant, Parents $parents): void
    {
        $this->tx->requireTenant($tenant);
        if (!$parents->tenantId->equals($tenant)) { self::fail('scope_mismatch', Outcome::Denied); }
        foreach ($parents->refs as $parent) {
            $this->ref($tenant, $parent->kind, $parent->id->value);
            $rows = $this->db->execute('WITH RECURSIVE ancestors(id) AS (SELECT id FROM ' . $this->db->table('dependency_nodes') .
                ' WHERE tenant_id=? AND id=? UNION SELECT e.parent_id FROM ' . $this->db->table('dependency_edges') .
                ' e JOIN ancestors a ON e.child_id=a.id WHERE e.tenant_id=?) SELECT n.* FROM ' .
                $this->db->table('dependency_nodes') . ' n JOIN ancestors a ON n.id=a.id WHERE n.tenant_id=?',
                [$tenant->value, $parent->id->value, $tenant->value, $tenant->value])->fetchAll(\PDO::FETCH_ASSOC);
            $found = false;
            foreach ($rows as $row) {
                if ($row['id'] === $parent->id->value) { $found = $row['kind'] === $parent->kind; }
                if ($row['invalidated_epoch'] !== null) { self::fail('ancestor_unusable', Outcome::Denied); }
                $wire = $row['kind'] === 'source_version' ? $this->sourceWire($tenant, $row['id'])
                    : ($row['kind'] === 'document_revision' ? $this->revisionWire($tenant, $row['id']) : null);
                if ($wire === null || $wire->get('sourceId') !== $row['source_id'] || $wire->get('sourceVersionId') !== $row['source_version_id']
                    || HashDomain::DependencyNodeIdentity->digest($wire) !== $row['identity_digest']
                    || $this->parents($tenant, $row['id'])->digest()->value !== $row['parent_set_digest']) { self::fail('immutable_conflict'); }
            }
            if (!$found) { self::fail('required_parent_missing'); }
        }
    }

    public function sourceWire(TenantId $tenant, string $id): JsonObject
    {
        $this->tx->requireTenant($tenant);
        $row = $this->db->row('SELECT v.source_id,v.source_digest,v.id,s.lifecycle_state,t.serving_state,vs.usability,
            EXISTS (SELECT 1 FROM ' . $this->db->table('tombstones') . ' z WHERE z.tenant_id=v.tenant_id AND z.source_id=v.source_id) AS tombstoned
            FROM ' . $this->db->table('source_versions') . ' v JOIN ' . $this->db->table('sources') . ' s ON s.tenant_id=v.tenant_id AND s.id=v.source_id
            JOIN ' . $this->db->table('tenants') . ' t ON t.id=v.tenant_id JOIN ' . $this->db->table('source_version_state') .
            ' vs ON vs.tenant_id=v.tenant_id AND vs.id=v.id WHERE v.tenant_id=? AND v.id=?', [$tenant->value, $id]);
        if ($row === null) { self::fail('required_parent_missing'); }
        if ($row['lifecycle_state'] !== 'active' || $row['serving_state'] !== 'ready' || $row['usability'] !== 'usable' || $row['tombstoned']) {
            self::fail('ancestor_unusable', Outcome::Denied);
        }
        return new JsonObject(['tenantId' => $tenant->value, 'sourceId' => $row['source_id'], 'sourceVersionId' => $id, 'sourceDigest' => $row['source_digest']]);
    }

    public function revisionWire(TenantId $tenant, string $id): JsonObject
    {
        $this->tx->requireTenant($tenant);
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('document_revisions') . ' WHERE tenant_id=? AND id=?', [$tenant->value, $id]);
        if ($row === null) { self::fail('required_parent_missing'); }
        return $this->sourceWire($tenant, $row['source_version_id'])->with('documentRevisionId', $id)->with('irDigest', $row['ir_digest'])
            ->with('parserSignature', new JsonObject(['name' => $row['parser_name'], 'version' => $row['parser_version'], 'profileDigest' => $row['profile_digest']]));
    }

    private function ref(TenantId $tenant, string $kind, string $id): ResourceRef
    {
        return new ResourceRef($tenant, $kind, match ($kind) {
            'source_version' => new SourceVersionId($id), 'document_revision' => new DocumentRevisionId($id),
            default => self::fail('unsupported_parent_kind'),
        });
    }

    private static function fail(string $code, Outcome $outcome = Outcome::Incompatible): never { throw new DomainFailure($outcome, $code); }
}
