<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Observability;

use KnowKit\Application\Deletion\DeletionWorker;
use KnowKit\Application\Events\Dispatcher;
use KnowKit\Conformance\SchemaLoader;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Observability as O;
use KnowKit\Port as P;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('ops:observability')]
final class OperationsTest extends TestCase
{
    public function testUnknownPurgeReceiptIsObservedWithoutChangingOrDisclosingReceipt(): void
    {
        $f = new Fixture();
        $validator = SchemaLoader::load(Fixture::root() . '/schemas');
        $target = new D\DeletionTarget(
            tenantId: Fixture::scope()->tenantId, sourceId: new D\SourceId(Fixture::id(10)),
            taskId: new D\DeletionTaskId(Fixture::id(11)), resourceId: new D\ResourceId(Fixture::id(12)),
            storageId: 'fixture', storageKey: Fixture::cases()['contentCanary'], objectVersion: '1',
            stagingId: null, resourceClass: 'canonical',
        );
        $attempt = new D\AttemptId(Fixture::id(13));
        $wire = new JsonObject([
            'taskId' => $target->taskId->value, 'resourceId' => $target->resourceId->value,
            'attemptId' => $attempt->value, 'inFlight' => false, 'result' => 'unknown',
            'providerReceipt' => Fixture::cases()['secretCanary'], 'knownExpiry' => null, 'observedAt' => $f->now()->value,
        ]);
        $receipt = new D\DeletionReceipt(value: $wire, validator: $validator);
        $store = $this->createMock(P\DeletionStore::class);
        $store->method('beginAttempt')->willReturn(new D\DeletionStart($target));
        $store->expects(self::once())->method('finishAttempt')->with($target, $attempt, $receipt, null)->willReturn($receipt);
        $transport = $this->createMock(P\DeletionTransport::class);
        $transport->method('provesExactVersion')->willReturn(false);
        $transport->expects(self::once())->method('delete')->willReturn($receipt);
        $worker = new DeletionWorker(store: $store, transport: $transport, clock: $f, validator: $validator, telemetry: $f->telemetry());
        self::assertSame($receipt, $worker->attempt(
            tenant: $target->tenantId, task: $target->taskId, resource: $target->resourceId, attempt: $attempt,
        ));
        self::assertSame(Fixture::cases()['expected']['purgeReceipt'], $f->observations[0]->outcome->value);
        self::assertSame(O\Reason::PurgeUnknown, $f->observations[0]->reason);
        $logs = Jcs::canonicalize($f->observations[0]->toWire());
        self::assertStringNotContainsString(Fixture::cases()['secretCanary'], $logs);
        self::assertStringNotContainsString(Fixture::cases()['contentCanary'], $logs);
        self::assertSame(D\Outcome::Incompatible, (new D\DeletionReceipt(
            value: $wire->with('result', 'unsupported'), validator: $validator,
        ))->operationalOutcome());
    }

    public function testOutboxLagUsesTenantBoundStoreAndUnavailableStatusDoesNotUndoDispatch(): void
    {
        $f = new Fixture();
        $outbox = $this->createMock(P\OutboxDispatch::class);
        $outbox->expects(self::once())->method('leaseDelivery')->willReturn([]);
        $outbox->expects(self::once())->method('status')->willReturn(new JsonObject([
            'oldestOutstandingSeconds' => (string) Fixture::cases()['expected']['outboxLagSeconds'],
            'unsafeIgnoredField' => Fixture::cases()['secretCanary'],
        ]));
        $transport = $this->createMock(P\EventTransport::class);
        $transport->expects(self::never())->method('send');
        $result = (new Dispatcher(outbox: $outbox, transport: $transport, telemetry: $f->telemetry()))->dispatch();
        self::assertSame(0, $result->get('delivered'));
        self::assertSame(17, $f->observations[0]->measurements->outboxLagSeconds);
        $metrics = $f->observations[0]->metrics();
        self::assertSame('knowkit_outbox_lag_seconds', $metrics[2]->get('name'));
        self::assertSame(17, $metrics[2]->get('value'));
        self::assertStringNotContainsString(Fixture::cases()['secretCanary'], Jcs::canonicalize($f->observations[0]->toWire()));

        $broken = $this->createMock(P\OutboxDispatch::class);
        $broken->method('leaseDelivery')->willReturn([]);
        $broken->method('status')->willThrowException(new \RuntimeException(Fixture::cases()['secretCanary']));
        $again = (new Dispatcher(outbox: $broken, transport: $transport, telemetry: $f->telemetry()))->dispatch();
        self::assertSame(Jcs::canonicalize($result), Jcs::canonicalize($again));
        self::assertSame(D\Outcome::Unavailable, $f->observations[1]->outcome);
        self::assertNull($f->observations[1]->measurements->outboxLagSeconds);
    }
}
