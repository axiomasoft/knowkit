<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain\Contract\Digests;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\JobEnvelope;
use KnowKit\Domain\JobResult;
use KnowKit\Domain\Lease;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ParserSignature;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';

/** Real PostgreSQL acceptance, synthetic untrusted output. No PDF backend qualification. */
#[Group('worker:protocol')]
final class JobBoundaryTest extends TestCase
{
    public function testVerifiedArtifactCannotBypassConditionalFenceAfterTakeover(): void
    {
        Jobs::run(function (Jobs $jobs): void {
            $jobs->enqueue();
            $old = $jobs->lease();
            $result = $this->verifiedResult($old, $jobs->processing->parserSignature);
            $jobs->finishStages($old);
            $jobs->expire($old);
            $current = $jobs->lease();
            self::assertNotSame($old->fence->value, $current->fence->value);
            self::assertFalse($jobs->queue->complete($old, $result));
            self::assertSame('leased', $jobs->row()['state']);
            self::assertNotNull($jobs->queue->resume($current, $jobs->processing));
            $fresh = $this->verifiedResult($current, $jobs->processing->parserSignature);
            self::assertTrue($jobs->queue->complete($current, $fresh));
            self::assertSame('succeeded', $jobs->row()['state']);
            self::assertFalse($jobs->queue->complete($old, $result));
        });
    }

    public function testDatabaseExpiryAndCancellationRejectVerifiedArtifacts(): void
    {
        Jobs::run(function (Jobs $jobs): void {
            $jobs->enqueue();
            $lease = $jobs->lease();
            $result = $this->verifiedResult($lease, $jobs->processing->parserSignature);
            $jobs->finishStages($lease);
            $jobs->expire($lease);
            self::assertFalse($jobs->queue->complete($lease, $result));
            $current = $jobs->lease();
            $fresh = $this->verifiedResult($current, $jobs->processing->parserSignature);
            self::assertTrue($jobs->queue->cancel($jobs->scope, $current->jobId));
            self::assertFalse($jobs->queue->complete($current, $fresh));
            self::assertSame('cancelled', $jobs->row()['state']);
        });
    }

    public function testCurrentSourceEpochRejectsAnOtherwiseValidLeaseAndArtifact(): void
    {
        Jobs::run(function (Jobs $jobs): void {
            $jobs->enqueue();
            $lease = $jobs->lease();
            $result = $this->verifiedResult($lease, $jobs->processing->parserSignature);
            $jobs->finishStages($lease);
            $jobs->catalog->db->execute(
                'UPDATE sources SET lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?',
                [$lease->scope->tenantId->value, $lease->sourceRef->sourceId->value],
            );
            self::assertFalse($jobs->queue->complete($lease, $result));
            self::assertSame('leased', $jobs->row()['state']);
        });
    }

    private function verifiedResult(Lease $lease, ParserSignature $signature): JobResult
    {
        $wire = Fixture::wire('envelope')->with('jobId', $lease->jobId->value)
            ->with('attemptId', $lease->attemptId->value)->with('tenantId', $lease->scope->tenantId->value)
            ->with('kbId', $lease->scope->kbId->value)->with('sourceId', $lease->sourceRef->sourceId->value)
            ->with('sourceVersionId', $lease->sourceRef->sourceVersionId->value)
            ->with('fence', $lease->fence->value)->with('sourceEpoch', $lease->sourceEpoch->value)
            ->with('inputDigest', $lease->sourceRef->sourceDigest->value)
            ->with('parserSignature', $signature->toWire())->with('profileFingerprint', $signature->profileDigest->value)
            ->with('leaseExpiresAt', $lease->leaseUntil->value);
        $job = new JobEnvelope($wire, Fixture::validator());
        $directory = Fixture::temporary();
        try {
            $this->writeOutput($directory, $wire);
            $bundle = (new WorkerIngressBundleValidator(Fixture::validator()))->verify($job, $directory);
            return new JobResult(Outcome::Success, $bundle->result->resultDigest());
        } finally {
            WorkerFiles::remove($directory);
        }
    }

    private function writeOutput(string $directory, JsonObject $job): void
    {
        $ir = Fixture::wire('ir')->with('tenantId', $job->get('tenantId'))
            ->with('sourceVersionId', $job->get('sourceVersionId'))->with('parserSignature', $job->get('parserSignature'));
        $bytes = Jcs::canonicalize($ir);
        $result = Fixture::wire('result');
        foreach (['jobId', 'attemptId', 'tenantId', 'sourceId', 'sourceVersionId',
            'fence', 'sourceEpoch', 'profileFingerprint'] as $field) {
            $result = $result->with($field, $job->get($field));
        }
        $result = Fixture::sign($result->with('irDigest', Digests::ir($ir))->with('irByteLength', (string) strlen($bytes)));
        file_put_contents($directory . '/ir.json', $bytes);
        file_put_contents($directory . '/result.json', Jcs::canonicalize($result));
    }
}
