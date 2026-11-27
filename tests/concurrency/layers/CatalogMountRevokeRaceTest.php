<?php

declare(strict_types=1);

namespace KnowKit\Tests\Concurrency\Layers;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Adapter\Postgres\Layers\PdoCatalogMountStore;
use KnowKit\Domain\Decision;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Layers\CatalogMountRef;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\LockMode;
use KnowKit\Domain\Operation;
use KnowKit\Domain\ResourceRef;
use KnowKit\Domain\SnapshotId;
use KnowKit\Domain\SnapshotRef;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\Ulid;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('concurrency:layers')]
final class CatalogMountRevokeRaceTest extends TestCase
{
    public function testConcurrentRevokeMountStopsAllSubsequentReadsWithZeroSqlErrors(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->kb(1);
            $scope = $fx->scope(1);

            $catalogTenantId = new TenantId(CatalogFixture::id(2));
            $catalogKbId = new KbId(CatalogFixture::id(102));
            $snapshotId = new SnapshotId(CatalogFixture::id(202));
            $manifestDigest = new Fingerprint(str_repeat('f', 64));

            $fx->tx->run(function () use ($fx, $catalogTenantId, $catalogKbId, $snapshotId, $manifestDigest): void {
                $fx->tx->lockTenant($catalogTenantId, LockMode::Update);
                $fx->db->execute(
                    'INSERT INTO ' . $fx->db->table('tenants') . " (id, name, serving_state, isolation_kind) VALUES (?, ?, 'ready', 'shared_catalog_readonly')",
                    [$catalogTenantId->value, 'catalog-' . $catalogTenantId->value],
                );
                $fx->db->execute(
                    'INSERT INTO ' . $fx->db->table('knowledge_bases') .
                    " (tenant_id, id, name, policy_ref, coverage_policy, default_profile) VALUES (?, ?, 'Shared Catalog', 'owner:catalog', '{}'::jsonb, 'postgres-fts-simple-v1')",
                    [$catalogTenantId->value, $catalogKbId->value],
                );
                $fx->db->execute(
                    'INSERT INTO ' . $fx->db->table('publication_snapshots') .
                    ' (tenant_id, id, kb_id, manifest_digest, created_at, sealed) VALUES (?, ?, ?, ?, ?, true)',
                    [$catalogTenantId->value, $snapshotId->value, $catalogKbId->value, $manifestDigest->value, $fx->clock->now()->value],
                );
            });

            $snapshotRef = new SnapshotRef($catalogTenantId, $catalogKbId, $snapshotId, $manifestDigest);
            $mountId = new Ulid(CatalogFixture::id(801));
            $mount = new CatalogMountRef(
                mountId: $mountId,
                tenantId: $scope->tenantId,
                catalogTenantId: $catalogTenantId,
                snapshotRef: $snapshotRef,
                maxDisclosureMode: DisclosureMode::DerivedOnly,
                sealed: true,
                mountGrantDigest: CatalogMountRef::grantDigest($mountId, $scope->tenantId, $catalogTenantId, $snapshotRef, DisclosureMode::DerivedOnly),
                privateTenant: false,
            );
            $receipt = $fx->owner->decide($scope, new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), Operation::CatalogMount);
            $store = new PdoCatalogMountStore($fx->db, $fx->tx, $fx->clock, $fx->ids);
            $store->mount($scope, $mount, $receipt);

            $root = dirname(__DIR__, 3);
            $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test;user=knowkit_test';
            $schema = $fx->db->schema;
            $role = (string) $fx->db->pdo->query('SELECT current_user')->fetchColumn();
            $scopeB64 = base64_encode(serialize($scope));

            // Process B: revokes the mount after receiving GO on stdin.
            $revokerCode = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/storage/catalog/bootstrap.php';
$pdo = new PDO($argv[2]);
$pdo->exec('SET ROLE ' . $argv[6]);
$pdo->exec('SET search_path TO ' . $argv[3]);
$db = new \KnowKit\Adapter\Postgres\Catalog\Connection($pdo, $argv[3]);
$tx = new \KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction($db);
$clock = new class implements \KnowKit\Port\Clock {
    public function now(): \KnowKit\Domain\UtcInstant { return new \KnowKit\Domain\UtcInstant('2026-11-27T12:00:00Z'); }
};
$ids = new \KnowKit\Tests\Storage\Catalog\TestIds();
$store = new \KnowKit\Adapter\Postgres\Layers\PdoCatalogMountStore($db, $tx, $clock, $ids);
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);
$scope = unserialize(base64_decode($argv[4], true));
$mountId = new \KnowKit\Domain\Ulid($argv[5]);
$store->revokeMount($scope, $mountId);
echo "REVOKED\n";
fflush(STDOUT);
PHP;

            $proc = proc_open(
                [PHP_BINARY, '-r', $revokerCode, $root, $dsn, $schema, $scopeB64, $mountId->value, $role],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($proc);
            $r = [$pipes[1]];
            $w = $e = null;
            if (stream_select($r, $w, $e, 5) !== 1) {
                self::fail('Timed out waiting for READY: ' . stream_get_contents($pipes[2]));
            }
            $ready = trim((string) fgets($pipes[1]));
            if ($ready !== 'READY') {
                self::fail('Expected READY, got "' . $ready . '": ' . stream_get_contents($pipes[2]));
            }

            // Process A reads active mounts before revoke, triggers concurrent revoke, then reads again.
            $before = $store->listActiveMounts($scope->tenantId);
            self::assertCount(1, $before);

            fwrite($pipes[0], "GO\n");
            fflush($pipes[0]);

            $revokedObserved = false;
            $postRevokeReads = 0;
            for ($i = 0; $i < 40; $i++) {
                $active = $store->listActiveMounts($scope->tenantId);
                if ($active === []) {
                    $revokedObserved = true;
                    $postRevokeReads++;
                } elseif ($revokedObserved) {
                    self::fail('Mount re-appeared after revokeMount committed');
                }
                if ($i === 0) {
                    $r2 = [$pipes[1]];
                    $w2 = $e2 = null;
                    if (stream_select($r2, $w2, $e2, 5) !== 1) {
                        self::fail('Timed out waiting for REVOKED: ' . stream_get_contents($pipes[2]));
                    }
                    $revokedLine = trim((string) fgets($pipes[1]));
                    if ($revokedLine !== 'REVOKED') {
                        self::fail('Expected REVOKED, got "' . $revokedLine . '": ' . stream_get_contents($pipes[2]));
                    }
                }
            }

            foreach ($pipes as $p) {
                fclose($p);
            }
            $exit = proc_close($proc);
            self::assertSame(0, $exit);
            self::assertTrue($revokedObserved);
            self::assertGreaterThanOrEqual(1, $postRevokeReads);
            self::assertSame([], $store->listActiveMounts($scope->tenantId));
        });
    }
}
