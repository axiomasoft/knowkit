<?php
declare(strict_types=1);

namespace KnowKit\Tests\Layers\Mounts;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Adapter\Postgres\Layers\PdoCatalogMountStore;
use KnowKit\Domain\{
    Decision,
    DomainFailure,
    Fingerprint,
    KbId,
    LockMode,
    Operation,
    Outcome,
    ResourceRef,
    Scope,
    SnapshotId,
    SnapshotRef,
    TenantId,
    Ulid,
};
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Layers\{CatalogMountRef, DisclosureMode};
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('layers:mounts')]
final class CatalogMountTest extends TestCase
{
    private function seedSharedCatalogTenant(
        CatalogFixture $fx,
        int $catalogTenantNum,
        int $catalogKbNum,
        int $snapshotNum,
        bool $sealed,
        string $isolationKind = 'shared_catalog_readonly',
    ): SnapshotRef {
        $catalogTenantId = new TenantId(CatalogFixture::id($catalogTenantNum));
        $catalogKbId = new KbId(CatalogFixture::id($catalogKbNum));
        $snapshotId = new SnapshotId(CatalogFixture::id($snapshotNum));
        $manifestDigest = new Fingerprint(str_repeat('f', 64));

        $fx->tx->run(function () use ($fx, $catalogTenantId, $catalogKbId, $snapshotId, $manifestDigest, $sealed, $isolationKind): void {
            $fx->tx->lockTenant($catalogTenantId, LockMode::Update);
            $fx->db->execute(
                'INSERT INTO ' . $fx->db->table('tenants') . " (id, name, serving_state, isolation_kind) VALUES (?, ?, 'ready', ?)",
                [$catalogTenantId->value, 'catalog-' . $catalogTenantId->value, $isolationKind],
            );
            $fx->db->execute(
                'INSERT INTO ' . $fx->db->table('knowledge_bases') .
                " (tenant_id, id, name, policy_ref, coverage_policy, default_profile) VALUES (?, ?, 'Shared Catalog', 'owner:catalog', '{}'::jsonb, 'postgres-fts-simple-v1')",
                [$catalogTenantId->value, $catalogKbId->value],
            );
            $fx->db->execute(
                'INSERT INTO ' . $fx->db->table('publication_snapshots') .
                ' (tenant_id, id, kb_id, manifest_digest, created_at, sealed) VALUES (?, ?, ?, ?, ?, ?)',
                [$catalogTenantId->value, $snapshotId->value, $catalogKbId->value, $manifestDigest->value, $fx->clock->now()->value, $sealed],
            );
        });

        return new SnapshotRef($catalogTenantId, $catalogKbId, $snapshotId, $manifestDigest);
    }

    private function buildMountRef(
        int $mountNum,
        TenantId $tenantId,
        SnapshotRef $snapshotRef,
        DisclosureMode $mode = DisclosureMode::DerivedOnly,
        bool $sealed = true,
        bool $privateTenant = false,
    ): CatalogMountRef {
        $mountId = new Ulid(CatalogFixture::id($mountNum));
        return new CatalogMountRef(
            mountId: $mountId,
            tenantId: $tenantId,
            catalogTenantId: $snapshotRef->tenantId,
            snapshotRef: $snapshotRef,
            maxDisclosureMode: $mode,
            sealed: $sealed,
            mountGrantDigest: CatalogMountRef::grantDigest($mountId, $tenantId, $snapshotRef->tenantId, $snapshotRef, $mode),
            privateTenant: $privateTenant,
        );
    }

    public function testMountsSharedSealedCatalogSnapshotAndListsActiveMounts(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->kb(1);
            $scope = $fx->scope(1);
            $snapshotRef = $this->seedSharedCatalogTenant($fx, 2, 102, 202, true);

            $mount = $this->buildMountRef(801, $scope->tenantId, $snapshotRef, DisclosureMode::DerivedOnly);
            $receipt = $fx->owner->decide($scope, new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), Operation::CatalogMount);

            $store = new PdoCatalogMountStore($fx->db, $fx->tx, $fx->clock, $fx->ids);
            $store->mount($scope, $mount, $receipt);

            $active = $store->listActiveMounts($scope->tenantId);
            self::assertCount(1, $active);
            self::assertSame($mount->mountId->value, $active[0]->mountId->value);
            self::assertSame($snapshotRef->tenantId->value, $active[0]->catalogTenantId->value);
            self::assertSame(DisclosureMode::DerivedOnly, $active[0]->maxDisclosureMode);
            self::assertTrue($active[0]->sealed);
            self::assertFalse($active[0]->privateTenant);
        });
    }

    public function testRejectsPrivateTenantAndUnsealedSnapshotMount(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->kb(1);
            $scope = $fx->scope(1);
            $store = new PdoCatalogMountStore($fx->db, $fx->tx, $fx->clock, $fx->ids);

            // NEG-3a: private_tenant cannot be mounted even if CatalogMountRef claims privateTenant: false
            $privateSnap = $this->seedSharedCatalogTenant($fx, 2, 102, 202, true, 'private_tenant');
            $privateMount = $this->buildMountRef(802, $scope->tenantId, $privateSnap, DisclosureMode::DerivedOnly, sealed: true, privateTenant: false);
            $receipt = $fx->owner->decide($scope, new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), Operation::CatalogMount);

            try {
                $store->mount($scope, $privateMount, $receipt);
                self::fail('Expected private_tenant mount to be denied');
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Denied, $e->outcome);
                self::assertSame('catalog_mount_forbidden', $e->errorCode);
            }

            // NEG-3b: unsealed snapshot cannot be mounted even if CatalogMountRef claims sealed: true
            $unsealedSnap = $this->seedSharedCatalogTenant($fx, 3, 103, 203, false, 'shared_catalog_readonly');
            $unsealedMount = $this->buildMountRef(803, $scope->tenantId, $unsealedSnap, DisclosureMode::DerivedOnly, sealed: true, privateTenant: false);
            $receipt2 = $fx->owner->decide($scope, new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), Operation::CatalogMount);

            try {
                $store->mount($scope, $unsealedMount, $receipt2);
                self::fail('Expected unsealed snapshot mount to be denied');
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Denied, $e->outcome);
                self::assertSame('catalog_mount_forbidden', $e->errorCode);
            }

            // SQL trigger kk_catalog_mount_guard() also blocks direct SQL insert of private_tenant
            CatalogFixture::sqlReject(
                $fx->sandbox->connection,
                'INSERT INTO ' . $fx->db->table('catalog_mounts') .
                " (tenant_id, id, catalog_tenant_id, catalog_kb_id, snapshot_id, manifest_digest, mount_grant_digest, max_disclosure_mode, state, mounted_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'derived_only', 'active', ?, NULL)",
                [
                    $scope->tenantId->value,
                    CatalogFixture::id(804),
                    $privateSnap->tenantId->value,
                    $privateSnap->kbId->value,
                    $privateSnap->snapshotId->value,
                    $privateSnap->manifestDigest->value,
                    $privateMount->mountGrantDigest->value,
                    $fx->clock->now()->value,
                ],
                ['23514'],
            );
        });
    }

    public function testRejectsDeniedOrInTransactionMount(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->kb(1);
            $scope = $fx->scope(1);
            $snapshotRef = $this->seedSharedCatalogTenant($fx, 2, 102, 202, true);
            $mount = $this->buildMountRef(805, $scope->tenantId, $snapshotRef);
            $store = new PdoCatalogMountStore($fx->db, $fx->tx, $fx->clock, $fx->ids);

            $fx->owner->decision = Decision::Deny;
            $deniedReceipt = $fx->owner->decide($scope, new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), Operation::CatalogMount);
            $fx->owner->decision = Decision::Allow;

            try {
                $store->mount($scope, $mount, $deniedReceipt);
                self::fail('Expected denied receipt to fail');
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Denied, $e->outcome);
                self::assertSame('policy_denied', $e->errorCode);
            }

            $allowReceipt = $fx->owner->decide($scope, new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), Operation::CatalogMount);
            $this->expectException(\LogicException::class);
            $fx->tx->run(function () use ($fx, $scope, $store, $mount, $allowReceipt): void {
                $fx->tx->lockTenant($scope->tenantId, LockMode::Update);
                $store->mount($scope, $mount, $allowReceipt);
            });
        });
    }

    public function testRevokeMountTransitionsStateIncrementsEpochsAndWritesOutbox(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->kb(1);
            $scope = $fx->scope(1);
            $snapshotRef = $this->seedSharedCatalogTenant($fx, 2, 102, 202, true);
            $mount = $this->buildMountRef(806, $scope->tenantId, $snapshotRef);

            $store = new PdoCatalogMountStore($fx->db, $fx->tx, $fx->clock, $fx->ids);
            $receipt = $fx->owner->decide($scope, new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), Operation::CatalogMount);
            $store->mount($scope, $mount, $receipt);

            $beforeTenantEpoch = (int) $fx->db->row('SELECT tenant_policy_epoch FROM ' . $fx->db->table('tenants') . ' WHERE id = ?', [$scope->tenantId->value])['tenant_policy_epoch'];
            $beforeKbEpoch = (int) $fx->db->row('SELECT kb_policy_epoch FROM ' . $fx->db->table('knowledge_bases') . ' WHERE tenant_id = ? AND id = ?', [$scope->tenantId->value, $scope->kbId->value])['kb_policy_epoch'];

            $store->revokeMount($scope, $mount->mountId);
            $store->revokeMount($scope, $mount->mountId); // idempotent

            self::assertSame([], $store->listActiveMounts($scope->tenantId));

            $mountRow = $fx->db->row(
                'SELECT state, revoked_at FROM ' . $fx->db->table('catalog_mounts') . ' WHERE tenant_id = ? AND id = ?',
                [$scope->tenantId->value, $mount->mountId->value],
            );
            self::assertNotNull($mountRow);
            self::assertSame('revoked', $mountRow['state']);
            self::assertNotNull($mountRow['revoked_at']);

            $afterTenantEpoch = (int) $fx->db->row('SELECT tenant_policy_epoch FROM ' . $fx->db->table('tenants') . ' WHERE id = ?', [$scope->tenantId->value])['tenant_policy_epoch'];
            $afterKbEpoch = (int) $fx->db->row('SELECT kb_policy_epoch FROM ' . $fx->db->table('knowledge_bases') . ' WHERE tenant_id = ? AND id = ?', [$scope->tenantId->value, $scope->kbId->value])['kb_policy_epoch'];
            self::assertSame($beforeTenantEpoch + 1, $afterTenantEpoch);
            self::assertSame($beforeKbEpoch + 1, $afterKbEpoch);

            $outboxRows = $fx->db->execute(
                'SELECT event_json FROM ' . $fx->db->table('outbox') . ' WHERE tenant_id = ?',
                [$scope->tenantId->value],
            )->fetchAll(\PDO::FETCH_COLUMN);
            $revokedEvents = array_values(array_filter(
                $outboxRows,
                static fn(string $json): bool => StrictJson::decode($json)->get('type') === 'catalog_mount.revoked',
            ));
            self::assertCount(1, $revokedEvents);
            $event = StrictJson::decode($revokedEvents[0]);
            self::assertSame($mount->mountId->value, $event->get('aggregateId'));
            self::assertSame($mount->mountId->value, $event->get('payload')->get('mountId'));
        });
    }
}
