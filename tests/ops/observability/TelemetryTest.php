<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Observability;

use KnowKit\Adapter\Observability\JsonLines;
use KnowKit\Application\Observability as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Observability as O;
use KnowKit\Port as P;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('ops:observability')]
final class TelemetryTest extends TestCase
{
    public function testProviderTimeoutPreservesUnknownAndOriginalExceptionWithoutRawInput(): void
    {
        $f = new Fixture();
        $timeout = new D\Embeddings\ProviderTimeout(Fixture::cases()['secretCanary']);
        $transport = $this->createMock(P\EmbeddingTransport::class);
        $transport->expects(self::once())->method('embed')->willThrowException($timeout);
        $observed = new A\EmbeddingTransport(transport: $transport, telemetry: $f->telemetry());
        try {
            $observed->embed(texts: [Fixture::cases()['contentCanary']], space: $this->space(), role: 'document');
            self::fail('Timeout swallowed');
        } catch (D\Embeddings\ProviderTimeout $actual) {
            self::assertSame($timeout, $actual);
        }
        self::assertCount(1, $f->observations);
        self::assertSame(Fixture::cases()['expected']['providerTimeout'], $f->observations[0]->outcome->value);
        self::assertSame(O\Reason::ProviderUncertain, $f->observations[0]->reason);
        $this->assertCanariesAbsent(Jcs::canonicalize($f->observations[0]->toWire()));
    }

    public function testStaleLeaseDoesNotRetryOrBecomeSuccessfulAndKeepsFence(): void
    {
        $f = new Fixture();
        $lease = $this->lease();
        $queue = $this->createMock(P\JobQueue::class);
        $queue->expects(self::once())->method('heartbeat')->with($lease)->willReturn(false);
        $queue->expects(self::once())->method('checkpoint')->willReturn(false);
        $queue->expects(self::once())->method('complete')->willReturn(false);
        $jobs = new A\Jobs(queue: $queue, telemetry: $f->telemetry(runRef: 'run-1'));
        self::assertFalse($jobs->heartbeat($lease));
        self::assertFalse($jobs->checkpoint(lease: $lease, checkpoint: new D\Checkpoint(
            digest: new D\Sha256(str_repeat('a', 64)), uncertainExternalEffect: true,
        )));
        self::assertFalse($jobs->complete(lease: $lease, result: new D\JobResult(
            outcome: D\Outcome::Unknown, resultDigest: new D\Sha256(str_repeat('b', 64)),
        )));
        self::assertCount(3, $f->observations);
        foreach ($f->observations as $observation) {
            self::assertSame(Fixture::cases()['expected']['staleLease'], $observation->outcome->value);
            self::assertSame(O\Reason::StaleLease, $observation->reason);
            self::assertSame($lease->jobId->value, $observation->toWire()->get('jobId'));
            self::assertSame('7', $observation->toWire()->get('fence'));
            self::assertStringNotContainsString('LEASE_TOKEN_CANARY', Jcs::canonicalize($observation->toWire()));
        }
    }

    public function testDeniedDisclosureNeverLoadsContentAndPreservesFailure(): void
    {
        $f = new Fixture();
        $request = Fixture::disclosureRequest();
        $denied = new D\DisclosureOutcome(D\Result::failure(new D\DomainFailure(
            outcome: D\Outcome::Denied, errorCode: Fixture::cases()['secretCanary'],
        )));
        $underlying = $this->createMock(\KnowKit\Application\Disclosure::class);
        $underlying->expects(self::once())->method('disclose')->with(Fixture::scope(), $request)->willReturn($denied);
        $observed = new A\Disclosure(disclosure: $underlying, telemetry: $f->telemetry());
        self::assertSame($denied, $observed->disclose(scope: Fixture::scope(), request: $request));
        self::assertSame(Fixture::cases()['expected']['deniedDisclosure'], $f->observations[0]->outcome->value);
        self::assertSame(O\Reason::AccessDenied, $f->observations[0]->reason);
        $this->assertCanariesAbsent(Jcs::canonicalize($f->observations[0]->toWire()));
    }

    public function testTracePropagationLinksRunUsageAndLeaseWithoutTenantOrSecret(): void
    {
        $f = new Fixture();
        $telemetry = $f->telemetry(runRef: Fixture::cases()['contentCanary']);
        $telemetry->emit(event: O\Event::Stage, stage: O\Stage::Request, outcome: D\Outcome::Success);
        $telemetry->withLease($this->lease())->withUsage(new D\UsageId(Fixture::id(20)))->emit(
            event: O\Event::Disclosure, stage: O\Stage::Disclose, outcome: D\Outcome::Success,
        );
        [$request, $usage] = $f->observations;
        self::assertSame($request->trace->correlationId, $usage->trace->correlationId);
        self::assertSame($request->trace->tenantRef, $usage->trace->tenantRef);
        self::assertSame($request->trace->runRef, $usage->trace->runRef);
        self::assertSame(Fixture::id(20), $usage->trace->usageRef->value);
        self::assertNull($request->trace->usageRef);
        self::assertNull($request->trace->jobId);
        $other = $f->factory()->start(correlationId: $request->trace->correlationId, scope: Fixture::scope(tenant: 9), runRef: Fixture::cases()['contentCanary']);
        self::assertNotSame($request->trace->tenantRef->value, $other->trace->tenantRef->value);
        self::assertNotSame($request->trace->runRef->value, $other->trace->runRef->value);
        self::assertStringNotContainsString(Fixture::scope()->tenantId->value, Jcs::canonicalize($usage->toWire()));
        $this->assertCanariesAbsent(Jcs::canonicalize($usage->toWire()));
    }

    public function testJsonLogsAndMetricsRejectContentByConstructionAndKeepDistinctOutcomes(): void
    {
        $f = new Fixture();
        $stream = fopen('php://temp', 'w+');
        try {
            $telemetry = $f->telemetry(sink: new JsonLines($stream), runRef: Fixture::cases()['secretCanary']);
            foreach (D\Outcome::cases() as $outcome) {
                $result = in_array($outcome, [D\Outcome::Success, D\Outcome::Empty], true)
                    ? ($outcome === D\Outcome::Success ? D\Result::success(new D\FrozenJson(new JsonObject(['content' => Fixture::cases()['contentCanary']]))) : D\Result::empty())
                    : D\Result::failure(new D\DomainFailure(outcome: $outcome, errorCode: Fixture::cases()['secretCanary']));
                self::assertSame($result, $telemetry->measure(stage: O\Stage::Request, operation: static fn (): D\Result => $result));
            }
            $telemetry->emit(
                event: O\Event::Quota, stage: O\Stage::Request, outcome: D\Outcome::Denied, reason: O\Reason::QuotaExceeded,
                measurements: new O\Measurements(quotaUsed: 16, quotaLimit: 16, orphanCount: 2),
            );
            rewind($stream);
            $bytes = stream_get_contents($stream);
            $this->assertCanariesAbsent($bytes);
            $lines = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($bytes)));
            self::assertCount(7, $lines);
            self::assertSame(array_column(D\Outcome::cases(), 'value'), array_column(array_slice($lines, 0, 6), 'outcome'));
            foreach ($lines as $line) {
                self::assertSame('observation:1', $line['schemaVersion']);
                self::assertSame(Fixture::id(90), $line['correlationId']);
                self::assertIsInt($line['durationMs']);
                foreach ($line['metrics'] as $metric) {
                    self::assertSame(['event', 'outcome', 'reason', 'stage'], array_keys($metric['labels']));
                    self::assertArrayNotHasKey('tenantRef', $metric['labels']);
                }
            }
            self::assertContains('knowkit_quota_limit', array_column($lines[6]['metrics'], 'name'));
            self::assertContains('knowkit_object_orphans', array_column($lines[6]['metrics'], 'name'));
        } finally {
            fclose($stream);
        }
    }

    public function testTelemetrySinkFailureCannotChangeCommittedResultOrExposeException(): void
    {
        $f = new Fixture();
        $sink = $this->createMock(P\ObservationSink::class);
        $sink->method('emit')->willThrowException(new \RuntimeException(Fixture::cases()['secretCanary']));
        $result = D\Result::success(new D\FrozenJson(new JsonObject()));
        self::assertSame($result, $f->telemetry($sink)->measure(stage: O\Stage::Complete, operation: static fn (): D\Result => $result));
        $failure = new \RuntimeException(Fixture::cases()['contentCanary']);
        try {
            $f->telemetry()->measure(stage: O\Stage::Request, operation: static function () use ($failure): never {
                throw $failure;
            });
            self::fail('Original failure swallowed');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(O\Reason::InternalFailure, $f->observations[0]->reason);
        $this->assertCanariesAbsent(Jcs::canonicalize($f->observations[0]->toWire()));
    }

    public function testCrossTenantTraceFailsBeforeQueueWrite(): void
    {
        $f = new Fixture();
        $queue = $this->createMock(P\JobQueue::class);
        $queue->expects(self::never())->method('cancel');
        $this->expectException(D\DomainFailure::class);
        (new A\Jobs(queue: $queue, telemetry: $f->telemetry()))->cancel(
            scope: Fixture::scope(tenant: 9), jobId: new D\JobId(Fixture::id(10)),
        );
    }

    public function testSuccessfulDisclosureLinksCommittedUsageWithoutMaterializingReplayText(): void
    {
        $f = new Fixture();
        $replay = new D\DisclosureReplay(
            usageRef: new D\UsageId(Fixture::id(30)), disclosureAttemptId: new D\Ulid(Fixture::id(21)),
            checkedAt: $f->now(), evidenceDigest: new D\Sha256(str_repeat('f', 64)),
        );
        $outcome = new D\DisclosureOutcome(D\Result::success($replay));
        $disclosure = $this->createMock(\KnowKit\Application\Disclosure::class);
        $disclosure->expects(self::once())->method('disclose')->willReturn($outcome);
        $telemetry = $f->telemetry(runRef: 'host-run');
        $observed = new A\Disclosure(disclosure: $disclosure, telemetry: $telemetry);
        self::assertSame($outcome, $observed->disclose(scope: Fixture::scope(), request: Fixture::disclosureRequest()));
        self::assertSame(Fixture::id(30), $f->observations[0]->trace->usageRef->value);
        self::assertSame($telemetry->trace->runRef, $f->observations[0]->trace->runRef);
        self::assertSame($telemetry->trace->correlationId, $f->observations[0]->trace->correlationId);
    }

    private function space(): D\SpaceRef
    {
        return new D\SpaceRef(
            provider: 'fixture', model: 'fixture', revision: '1', dimensions: 2,
            normalization: 'none', indexVersion: '1', documentRole: 'document', queryRole: 'query',
        );
    }

    private function lease(): D\Lease
    {
        return new D\Lease(
            scope: Fixture::scope(), jobId: new D\JobId(Fixture::id(10)), attemptId: new D\AttemptId(Fixture::id(11)),
            sourceRef: new D\SourceVersionRef(
                tenantId: Fixture::scope()->tenantId, sourceId: new D\SourceId(Fixture::id(12)),
                sourceVersionId: new D\SourceVersionId(Fixture::id(13)), sourceDigest: new D\SourceDigest(str_repeat('a', 64)),
            ),
            generationId: null, fence: new D\Decimal('7'), token: str_repeat('LEASE_TOKEN_CANARY', 3),
            leaseUntil: new D\UtcInstant('2026-11-08T00:00:00Z'),
            attemptDeadline: new D\UtcInstant('2026-11-09T00:00:00Z'), sourceEpoch: new D\Decimal('0'),
        );
    }

    private function assertCanariesAbsent(string $bytes): void
    {
        self::assertStringNotContainsString(Fixture::cases()['secretCanary'], $bytes);
        self::assertStringNotContainsString(Fixture::cases()['contentCanary'], $bytes);
    }
}
