<?php
declare(strict_types=1);

namespace KnowKit\Tests\Sources\Inventory;

use KnowKit\Adapter\Sources\BrokenConnector;
use KnowKit\Adapter\Sources\ConnectorQualifier;
use KnowKit\Adapter\Sources\RecordedInventoryStore;
use KnowKit\Application\Sources\InventoryReconciler;
use KnowKit\Domain as D;
use KnowKit\Domain\Sources\PreviousInventory;
use KnowKit\Domain\Sources\ReconciliationOutcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('sources:inventory')]
final class InventoryTest extends TestCase
{
    private function apply(RecordedInventoryStore $store, string $case, string $key, string $namespace = 'repo:a'): ReconciliationOutcome
    {
        return (new InventoryReconciler($store))->reconcile(
            Fixture::scope(), $store->previous(Fixture::scope(), new D\AdapterId('recorded'), $namespace),
            Fixture::snapshot(Fixture::data()['scenarios'][$case], $namespace), Fixture::context($key),
        );
    }

    private function rejects(callable $action, string $code): void
    {
        try {
            $action();
            self::fail('Expected fail-closed result');
        } catch (D\DomainFailure $failure) {
            self::assertSame($code, $failure->errorCode);
        }
    }

    public function testTruncatedTreeAndComparePreserveAbsentMembers(): void
    {
        $store = Fixture::store();
        $this->apply($store, 'initial', 'initial');
        foreach (['truncated-tree', 'truncated-compare', 'capped'] as $case) {
            $outcome = $this->apply($store, $case, $case);
            self::assertFalse($outcome->inventory->complete);
            self::assertSame([], $outcome->removed);
            self::assertSame(['b'], $outcome->preservedOriginKeys);
            self::assertContains(Fixture::data()['scenarios'][$case]['failures'][0], $outcome->reasons);
            self::assertCount(2, $store->readable(Fixture::scope()));
        }
    }

    public function testDownloadSizeDigestAndFullBodyLimitsRejectPrefixes(): void
    {
        foreach (['truncated-body', 'wrong-digest', 'oversized'] as $name) {
            $case = Fixture::data()['scenarios'][$name];
            $this->rejects(fn () => Fixture::snapshot($case), $case['expectedError']);
        }
        $item = Fixture::item(Fixture::data()['scenarios']['initial']['items'][0]);
        self::assertSame(64, strlen($item->contentDigest->value));
        self::assertSame('5', $item->byteLength->value);
    }

    public function testIncompletePaginationCannotDelete(): void
    {
        $store = Fixture::store();
        $this->apply($store, 'initial', 'initial');
        $outcome = $this->apply($store, 'incomplete-pages', 'pages');
        self::assertSame([], $outcome->removed);
        self::assertSame(['b'], $outcome->preservedOriginKeys);
        self::assertContains('pagination_incomplete', $outcome->reasons);
    }

    public function testInventoryAndAllReadsUseOneImmutableCommit(): void
    {
        $case = Fixture::data()['scenarios']['initial'];
        $connector = Fixture::connector($case);
        $snapshot = $connector->inventory(Fixture::scope(), new D\ConnectorRequest('repo:a', 'main'));
        self::assertSame($case['pin'], $snapshot->pinnedRevision);
        self::assertNotSame(Fixture::data()['branchHeadAfterInventory'], $snapshot->pinnedRevision);
        foreach ($snapshot->items as $item) {
            self::assertSame($snapshot->pinnedRevision, $item->immutableRevision);
        }
        $case['items'][0]['readRevision'] = Fixture::data()['branchHeadAfterInventory'];
        $this->rejects(fn () => Fixture::snapshot($case), 'download_revision_mismatch');
        self::assertSame(1, $connector->inventoryCalls);
    }

    public function testRenamesRequireExplicitOldAndNewIdentityMapping(): void
    {
        $store = Fixture::store();
        $this->apply($store, 'initial', 'initial');
        $case = Fixture::data()['scenarios']['rename'];
        $withoutMapping = $case;
        $withoutMapping['renames'] = [];
        $previous = $store->previous(Fixture::scope(), new D\AdapterId('recorded'), 'repo:a');
        $this->rejects(fn () => (new InventoryReconciler($store))->reconcile(
            Fixture::scope(), $previous, Fixture::snapshot($withoutMapping), Fixture::context('bad-rename'),
        ), 'explicit_rename_required');
        $outcome = $this->apply($store, 'rename', 'rename');
        self::assertSame('docs/renamed.md', $outcome->members[0]->logicalPath);
        self::assertSame([], $outcome->removed);
        self::assertCount(2, $store->readable(Fixture::scope()));
        $changedIdentity = $this->apply($store, 'rename-identity', 'rename-identity');
        self::assertSame('a', $changedIdentity->removed[0]->originKey);
        self::assertSame(['b', 'c'], array_column($store->readable(Fixture::scope()), 'originKey'));
    }

    public function testSourceNamespacesWithTheSamePathNeverCollide(): void
    {
        $store = Fixture::store();
        $this->apply($store, 'initial', 'a', 'repo:a');
        $this->apply($store, 'initial', 'b', 'repo:b');
        self::assertCount(4, $store->readable(Fixture::scope()));
        $this->apply($store, 'empty', 'empty-a', 'repo:a');
        self::assertCount(2, $store->readable(Fixture::scope()));
        $outcome = $this->apply($store, 'unavailable', 'unavailable-b', 'repo:b');
        self::assertSame(['a', 'b'], $outcome->preservedOriginKeys);
        self::assertCount(2, $store->readable(Fixture::scope()));
    }

    public function testAvailableAAndUnavailableBKeepExactOldB(): void
    {
        $store = Fixture::store();
        $first = $this->apply($store, 'initial', 'initial');
        $partial = $this->apply($store, 'partial', 'partial');
        self::assertSame($first->members[1], $partial->members[1]);
        self::assertSame(['b'], $partial->preservedOriginKeys);
        self::assertSame([], $partial->removed);
        self::assertNotSame($partial->members[0]->immutableRevision, $partial->members[1]->immutableRevision);
    }

    public function testCompleteLastSourceRemovalPublishesAnEmptySnapshot(): void
    {
        $store = Fixture::store();
        $this->apply($store, 'initial', 'initial');
        $empty = $this->apply($store, 'empty', 'empty');
        self::assertTrue($empty->publishesEmptyPartition());
        self::assertCount(2, $empty->removed);
        self::assertSame([], $empty->members);
        self::assertSame([], $store->readable(Fixture::scope()));
        self::assertSame(2, $store->publications);
        self::assertSame(0, $store->activeMemberCount(Fixture::scope()));
        self::assertSame([], $store->previous(Fixture::scope(), new D\AdapterId('recorded'), 'repo:a')->members);
    }

    public function testUnavailableIsDistinctFromCompleteEmpty(): void
    {
        $store = Fixture::store();
        $this->apply($store, 'initial', 'initial');
        $outcome = $this->apply($store, 'unavailable', 'unavailable');
        self::assertFalse($outcome->publishesEmptyPartition());
        self::assertCount(2, $outcome->members);
        self::assertSame([], $outcome->removed);
        self::assertContains('provider_unavailable', $outcome->reasons);
    }

    public function testConfirmedRemovalDeniesBeforeRebuildAndSurvivesItsFailure(): void
    {
        $controls = new Controls();
        $store = null;
        $store = Fixture::store(beforePublish: static function () use ($controls, &$store): void {
            if ($controls->fail) {
                self::assertSame([], $store->readable(Fixture::scope()));
                throw new \RuntimeException('projection_failed');
            }
        });
        $this->apply($store, 'initial', 'initial');
        $controls->fail = true;
        try {
            $this->apply($store, 'empty', 'empty');
            self::fail('Projection must fail');
        } catch (\RuntimeException $failure) {
            self::assertSame('projection_failed', $failure->getMessage());
        }
        self::assertSame(1, $store->publications);
        self::assertSame([], $store->readable(Fixture::scope()));
        self::assertSame(2, $store->activeMemberCount(Fixture::scope()));
        $controls->fail = false;
        $this->apply($store, 'empty', 'empty');
        self::assertSame(2, $store->publications);
    }

    public function testNoHiddenModelProbeRetryOrProductionQualification(): void
    {
        $connector = Fixture::connector(Fixture::data()['scenarios']['initial']);
        $snapshot = (new ConnectorQualifier())->inventory($connector, Fixture::scope(), new D\ConnectorRequest('repo:a', 'main'));
        self::assertSame(1, $connector->inventoryCalls);
        self::assertSame(1, $connector->describeCalls);
        self::assertNotNull($snapshot->receipt);
        $broken = new BrokenConnector(new D\CrawlSnapshot(
            $snapshot->connectorId, $snapshot->sourceNamespace, $snapshot->pinnedRevision, true,
            [], [], 0, $snapshot->inventoryDigest, $snapshot->observedAt,
        ));
        $this->rejects(fn () => (new ConnectorQualifier())->inventory(
            $broken, Fixture::scope(), new D\ConnectorRequest('repo:a', 'main'),
        ), 'unsupported_completeness_proof');
        $case = Fixture::data()['scenarios']['initial'];
        $case['fullTree'] = false;
        $this->rejects(fn () => Fixture::snapshot($case), 'unsupported_completeness_proof');
    }

    public function testReceiptTamperingAndFullScopeChangesFailClosed(): void
    {
        $scope = Fixture::scope();
        $snapshot = Fixture::snapshot(Fixture::data()['scenarios']['initial']);
        $previous = new PreviousInventory($scope, $snapshot->connectorId, 'repo:a', new D\Decimal('0'), []);
        $tampered = new D\CrawlSnapshot(
            $snapshot->connectorId, 'repo:b', $snapshot->pinnedRevision, true, $snapshot->items,
            [], 0, $snapshot->inventoryDigest, $snapshot->observedAt, $snapshot->receipt,
        );
        $this->rejects(fn () => $snapshot->receipt->assertMatches($scope, $tampered), 'inventory_receipt_mismatch');
        foreach (['tenantId', 'kbId', 'actorId', 'purpose', 'policyEpoch', 'hostScopeHash'] as $field) {
            $values = get_object_vars($scope);
            $values[$field] = match ($field) {
                'tenantId' => new D\TenantId('01J00000000000000000000999'),
                'kbId' => new D\KbId('01J00000000000000000000999'),
                'actorId' => new D\ActorId('01J00000000000000000000999'),
                'purpose' => 'other.read',
                'policyEpoch' => new D\Decimal('99'),
                'hostScopeHash' => new D\Sha256(str_repeat('b', 64)),
            };
            $changed = new D\Scope(...$values);
            $this->rejects(fn () => $snapshot->receipt->assertMatches($changed, $snapshot), 'inventory_receipt_mismatch');
            $this->rejects(fn () => (new InventoryReconciler(Fixture::store()))->reconcile(
                $changed, $previous, $snapshot, Fixture::context('scope'),
            ), 'scope_mismatch');
        }
    }

    public function testReplayCasAndCurrentAccessAreAuthoritative(): void
    {
        $controls = new Controls();
        $store = Fixture::store(static function () use ($controls): void {
            if (!$controls->allowed) {
                throw new D\DomainFailure(D\Outcome::Denied, 'access_unknown');
            }
        });
        $scope = Fixture::scope();
        $previous = $store->previous($scope, new D\AdapterId('recorded'), 'repo:a');
        $snapshot = Fixture::snapshot(Fixture::data()['scenarios']['initial']);
        $reconciler = new InventoryReconciler($store);
        $context = Fixture::context('idempotent', '0');
        $first = $reconciler->reconcile($scope, $previous, $snapshot, $context);
        self::assertSame($first, $reconciler->reconcile($scope, $previous, $snapshot, $context));
        self::assertSame(1, $store->publications);
        $this->rejects(fn () => $reconciler->reconcile($scope, $previous, $snapshot, Fixture::context('stale')), 'stale_write');
        $this->rejects(fn () => $reconciler->reconcile(
            $scope, $previous, Fixture::snapshot(Fixture::data()['scenarios']['empty']), $context,
        ), 'idempotency_conflict');
        $controls->allowed = false;
        $this->rejects(fn () => $reconciler->reconcile($scope, $previous, $snapshot, $context), 'access_unknown');
        $this->rejects(fn () => $store->readable($scope), 'access_unknown');
    }

    public function testCollisionFalseCompletenessAndMissingProofFailClosed(): void
    {
        $case = Fixture::data()['scenarios']['initial'];
        $duplicate = $case;
        $duplicate['items'][1]['logicalPath'] = $duplicate['items'][0]['logicalPath'];
        $store = Fixture::store();
        $previous = $store->previous(Fixture::scope(), new D\AdapterId('recorded'), 'repo:a');
        $this->rejects(fn () => (new InventoryReconciler($store))->reconcile(
            Fixture::scope(), $previous, Fixture::snapshot($duplicate), Fixture::context('duplicate-path'),
        ), 'inventory_member_collision');
        foreach (['fullTree', 'allPages', 'capped', 'omittedCount'] as $field) {
            $invalid = $case;
            $invalid[$field] = match ($field) {
                'capped' => true,
                'omittedCount' => null,
                default => false,
            };
            $this->rejects(fn () => Fixture::snapshot($invalid), 'unsupported_completeness_proof');
        }
        $snapshot = Fixture::snapshot($case);
        $noProof = new D\CrawlSnapshot(
            $snapshot->connectorId, $snapshot->sourceNamespace, $snapshot->pinnedRevision,
            true, [], [], 0, $snapshot->inventoryDigest, $snapshot->observedAt,
        );
        $this->rejects(fn () => (new InventoryReconciler($store))->reconcile(
            Fixture::scope(), $previous, $noProof, Fixture::context('missing-proof'),
        ), 'unsupported_completeness_proof');
        self::assertSame(0, $store->publications);
    }

    public function testPublicationRechecksCurrentAccessAfterRemoval(): void
    {
        $controls = new Controls();
        $store = Fixture::store(
            static function (D\Scope $scope, D\Operation $operation) use ($controls): void {
                if (!$controls->allowed && $operation === D\Operation::Publish) {
                    throw new D\DomainFailure(D\Outcome::Denied, 'POLICY_CHANGED');
                }
            },
            static function () use ($controls): void {
                if ($controls->changeAccess) {
                    $controls->allowed = false;
                }
            },
        );
        $this->apply($store, 'initial', 'initial');
        $controls->changeAccess = true;
        $this->rejects(fn () => $this->apply($store, 'empty', 'empty'), 'POLICY_CHANGED');
        self::assertSame(1, $store->publications);
        self::assertSame([], $store->readable(Fixture::scope()));
    }

    public function testExampleFactoryUsesTheQualifiedRecordedPort(): void
    {
        $factory = require Fixture::root() . '/examples/custom-adapter/recorded-source-connector.php';
        $snapshot = $factory(Fixture::scope(), str_repeat('a', 40));
        self::assertTrue($snapshot->complete);
        self::assertSame('example:repository', $snapshot->sourceNamespace);
        self::assertSame(str_repeat('a', 40), $snapshot->items[0]->immutableRevision);
        $snapshot->receipt->assertMatches(Fixture::scope(), $snapshot);
    }
}
