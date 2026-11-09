<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Readiness;

use KnowKit\Domain as D;
use KnowKit\Domain\Reading\ReadTarget;
use KnowKit\Tests\Worker\E2e\Harness;
use KnowKit\Tests\Worker\E2e\Reader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';
require_once dirname(__DIR__, 2) . '/worker/e2e/bootstrap.php';

#[Group('delivery:readiness')]
final class WorkerTest extends TestCase
{
    public function testFullWorkerExtractsPublishesReadsAndRevokesRealDocument(): void
    {
        Harness::run(function (Harness $host): void {
            $attempt = $host->attempt();
            $accepted = $host->extract($attempt);
            self::assertSame('complete', $accepted->output->ir->toWire()->get('coverage')->get('state'));
            self::assertSame(Fixture::manifest()['expected']['workerIrDigest'], $accepted->revision->irDigest->value);
            $set = $host->pipeline->persist(lease: $attempt->lease, revision: $accepted->revision, output: $accepted->output);
            $request = $host->pipeline->request(lease: $attempt->lease, set: $set);
            $host->pipeline->seal($request);
            $receipt = $host->pipeline->publish($request);
            $reader = new Reader(pipeline: $host->pipeline, set: $set, ir: $accepted->output->ir, request: $request, receipt: $receipt);
            $selected = $reader->select();
            $evidence = $reader->disclose($selected);
            self::assertSame(D\Outcome::Success, $evidence->result->outcome);
            $wire = $evidence->result->data->toWire()->get('fragments')[0]->get('citation');
            self::assertSame($attempt->source->ref->sourceDigest->value, $wire->get('sourceDigest'));
            self::assertSame($accepted->revision->irDigest->value, $wire->get('irDigest'));
            $citation = new D\Ulid($wire->get('citationId'));
            self::assertSame(D\Outcome::Success, $reader->read(citation: $citation, target: ReadTarget::quote())->outcome);
            $host->pipeline->lifecycle()->revoke(
                scope: $attempt->lease->scope,
                command: new D\Revocation(sourceId: $attempt->source->ref->sourceId, expectedLifecycleEpoch: new D\Decimal('0')),
                key: new D\IdempotencyKey('delivery-worker-revoke'),
            );
            self::assertSame(D\Outcome::Denied, $reader->read(citation: $citation, target: ReadTarget::quote())->outcome);
        });
    }
}
