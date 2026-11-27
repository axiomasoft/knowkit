<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Layers;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{
    DecisionReceipt,
    DomainFailure,
    Fingerprint,
    KbId,
    LockMode,
    Operation,
    Outcome,
    ResourceRef,
    Scope,
    Sha256,
    SnapshotId,
    SnapshotRef,
    TenantId,
    Ulid,
};
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Domain\Layers\{CatalogMountRef, DisclosureMode};
use KnowKit\Port\{CatalogMountStore, Clock, IdGenerator};

/** PostgreSQL 17 adapter for unidirectional read-only shared catalog mounts (ARCH-CATALOG-MOUNT). */
final readonly class PdoCatalogMountStore implements CatalogMountStore
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    public function mount(Scope $scope, CatalogMountRef $mount, DecisionReceipt $receipt): void
    {
        $scope->assertTenant($mount->tenantId);

        // Receipt validation happens strictly outside the SQL transaction (ARCH-NO-IO-IN-TX).
        $resource = new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId);
        $binding = $receipt->toWire()->get('binding');
        if (!$binding instanceof JsonObject) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $bindingDigest = new Sha256(HashDomain::PolicyBinding->digest($binding));
        $now = $this->clock->now();
        $receipt->requireAllow($scope, $resource, Operation::CatalogMount, $now, $bindingDigest);

        if ($mount->tenantId->equals($mount->catalogTenantId) || $mount->privateTenant || !$mount->sealed) {
            throw new DomainFailure(Outcome::Denied, 'catalog_mount_forbidden');
        }
        $expectedGrant = CatalogMountRef::grantDigest(
            $mount->mountId,
            $mount->tenantId,
            $mount->catalogTenantId,
            $mount->snapshotRef,
            $mount->maxDisclosureMode,
        );
        if (!$mount->mountGrantDigest->equals($expectedGrant)) {
            throw new DomainFailure(Outcome::Denied, 'catalog_mount_forbidden');
        }

        $this->tx->run(function () use ($scope, $mount, $binding, $now): void {
            $this->tx->lockTenant($scope->tenantId, LockMode::Update);
            $this->tx->lockKb($scope->kbId, LockMode::Share);

            $tenantRow = $this->db->row(
                'SELECT tenant_policy_epoch, serving_state FROM ' . $this->db->table('tenants') . ' WHERE id = ?',
                [$scope->tenantId->value],
            );
            $kbRow = $this->db->row(
                'SELECT kb_policy_epoch, removed_at FROM ' . $this->db->table('knowledge_bases') . ' WHERE tenant_id = ? AND id = ?',
                [$scope->tenantId->value, $scope->kbId->value],
            );
            if ($tenantRow === null || $tenantRow['serving_state'] !== 'ready') {
                throw new DomainFailure(Outcome::Unavailable, 'tenant_blocked');
            }
            if ($kbRow === null || $kbRow['removed_at'] !== null) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }
            if ((string) $tenantRow['tenant_policy_epoch'] !== (string) $binding->get('tenantPolicyEpoch')
                || (string) $kbRow['kb_policy_epoch'] !== $scope->policyEpoch->value) {
                throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
            }

            $catalogTenant = $this->db->row(
                'SELECT isolation_kind FROM ' . $this->db->table('tenants') . ' WHERE id = ?',
                [$mount->catalogTenantId->value],
            );
            if ($catalogTenant === null || $catalogTenant['isolation_kind'] !== 'shared_catalog_readonly') {
                throw new DomainFailure(Outcome::Denied, 'catalog_mount_forbidden');
            }

            $snapshot = $this->db->row(
                'SELECT sealed, manifest_digest FROM ' . $this->db->table('publication_snapshots') .
                ' WHERE tenant_id = ? AND kb_id = ? AND id = ?',
                [
                    $mount->catalogTenantId->value,
                    $mount->snapshotRef->kbId->value,
                    $mount->snapshotRef->snapshotId->value,
                ],
            );
            if ($snapshot === null
                || $snapshot['sealed'] !== true
                || $snapshot['manifest_digest'] !== $mount->snapshotRef->manifestDigest->value) {
                throw new DomainFailure(Outcome::Denied, 'catalog_mount_forbidden');
            }

            $this->db->execute(
                'INSERT INTO ' . $this->db->table('catalog_mounts') .
                ' (tenant_id, id, catalog_tenant_id, catalog_kb_id, snapshot_id, manifest_digest, mount_grant_digest, max_disclosure_mode, state, mounted_at, revoked_at)' .
                " VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NULL)",
                [
                    $mount->tenantId->value,
                    $mount->mountId->value,
                    $mount->catalogTenantId->value,
                    $mount->snapshotRef->kbId->value,
                    $mount->snapshotRef->snapshotId->value,
                    $mount->snapshotRef->manifestDigest->value,
                    $mount->mountGrantDigest->value,
                    $mount->maxDisclosureMode->value,
                    $now->value,
                ],
            );
        });
    }

    public function revokeMount(Scope $scope, Ulid $mountId): void
    {
        $now = $this->clock->now();

        $this->tx->run(function () use ($scope, $mountId, $now): void {
            $this->tx->lockTenant($scope->tenantId, LockMode::Update);
            $this->tx->lockKb($scope->kbId, LockMode::Update);

            $row = $this->db->row(
                'SELECT catalog_tenant_id, catalog_kb_id, snapshot_id, state' .
                ' FROM ' . $this->db->table('catalog_mounts') .
                ' WHERE tenant_id = ? AND id = ? FOR UPDATE',
                [$scope->tenantId->value, $mountId->value],
            );
            if ($row === null) {
                throw new DomainFailure(Outcome::Incompatible, 'resource_missing');
            }
            if ($row['state'] === 'revoked') {
                return;
            }

            $updated = $this->db->execute(
                'UPDATE ' . $this->db->table('catalog_mounts') .
                " SET state = 'revoked', revoked_at = ? WHERE tenant_id = ? AND id = ? AND state = 'active'",
                [$now->value, $scope->tenantId->value, $mountId->value],
            );
            if ($updated->rowCount() !== 1) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }

            $tenantEpochRow = $this->db->row(
                'UPDATE ' . $this->db->table('tenants') .
                ' SET tenant_policy_epoch = tenant_policy_epoch + 1 WHERE id = ? RETURNING tenant_policy_epoch',
                [$scope->tenantId->value],
            );
            $kbEpochRow = $this->db->row(
                'UPDATE ' . $this->db->table('knowledge_bases') .
                ' SET kb_policy_epoch = kb_policy_epoch + 1 WHERE tenant_id = ? AND id = ? AND removed_at IS NULL RETURNING kb_policy_epoch',
                [$scope->tenantId->value, $scope->kbId->value],
            );
            if ($tenantEpochRow === null || $kbEpochRow === null) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }

            $nextTenantEpoch = (string) $tenantEpochRow['tenant_policy_epoch'];
            $nextKbEpoch = (string) $kbEpochRow['kb_policy_epoch'];
            $eventId = $this->ids->next()->value;
            $payload = new JsonObject([
                'mountId' => $mountId->value,
                'catalogTenantId' => (string) $row['catalog_tenant_id'],
                'catalogKbId' => (string) $row['catalog_kb_id'],
                'snapshotId' => (string) $row['snapshot_id'],
                'tenantPolicyEpoch' => $nextTenantEpoch,
                'kbPolicyEpoch' => $nextKbEpoch,
            ]);
            $payloadDigest = HashDomain::DomainEventPayload->digest($payload);
            $eventWire = new JsonObject([
                'eventId' => $eventId,
                'schemaVersion' => 'event-1.0',
                'tenantId' => $scope->tenantId->value,
                'aggregateType' => 'catalog_mount',
                'aggregateId' => $mountId->value,
                'aggregateVersion' => $nextTenantEpoch,
                'type' => 'catalog_mount.revoked',
                'occurredAt' => $now->value,
                'causationId' => null,
                'correlationId' => null,
                'payload' => $payload,
                'payloadDigest' => $payloadDigest,
            ]);

            $this->db->execute(
                'INSERT INTO ' . $this->db->table('outbox') .
                ' (tenant_id, id, event_json, payload_digest, aggregate_version) VALUES (?, ?, ?::jsonb, ?, ?)',
                [
                    $scope->tenantId->value,
                    $eventId,
                    Jcs::canonicalize($eventWire),
                    $payloadDigest,
                    $nextTenantEpoch,
                ],
            );
        });
    }

    /** @return list<CatalogMountRef> */
    public function listActiveMounts(Ulid $tenantId): array
    {
        $tenant = $tenantId instanceof TenantId ? $tenantId : new TenantId($tenantId->value);

        $read = function () use ($tenant): array {
            $rows = $this->db->execute(
                'SELECT m.id, m.tenant_id, m.catalog_tenant_id, m.catalog_kb_id, m.snapshot_id,' .
                ' m.manifest_digest, m.mount_grant_digest, m.max_disclosure_mode,' .
                ' s.sealed, t.isolation_kind' .
                ' FROM ' . $this->db->table('catalog_mounts') . ' m' .
                ' JOIN ' . $this->db->table('tenants') . ' t ON t.id = m.catalog_tenant_id' .
                ' JOIN ' . $this->db->table('publication_snapshots') . ' s' .
                ' ON s.tenant_id = m.catalog_tenant_id AND s.kb_id = m.catalog_kb_id AND s.id = m.snapshot_id' .
                " WHERE m.tenant_id = ? AND m.state = 'active'" .
                ' ORDER BY m.mounted_at ASC, m.id ASC',
                [$tenant->value],
            )->fetchAll(\PDO::FETCH_ASSOC);

            $mounts = [];
            foreach ($rows as $row) {
                $mounts[] = new CatalogMountRef(
                    mountId: new Ulid((string) $row['id']),
                    tenantId: new TenantId((string) $row['tenant_id']),
                    catalogTenantId: new TenantId((string) $row['catalog_tenant_id']),
                    snapshotRef: new SnapshotRef(
                        new TenantId((string) $row['catalog_tenant_id']),
                        new KbId((string) $row['catalog_kb_id']),
                        new SnapshotId((string) $row['snapshot_id']),
                        new Fingerprint((string) $row['manifest_digest']),
                    ),
                    maxDisclosureMode: DisclosureMode::from((string) $row['max_disclosure_mode']),
                    sealed: (bool) $row['sealed'],
                    mountGrantDigest: new Fingerprint((string) $row['mount_grant_digest']),
                    privateTenant: $row['isolation_kind'] !== 'shared_catalog_readonly',
                );
            }

            return $mounts;
        };

        if ($this->db->pdo->inTransaction()) {
            $this->tx->requireTenant($tenant);
            return $read();
        }

        /** @var list<CatalogMountRef> */
        return $this->tx->run(function () use ($tenant, $read): array {
            $this->tx->lockTenant($tenant, LockMode::Share);
            return $read();
        });
    }
}
