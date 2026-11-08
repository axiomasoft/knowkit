<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Domain as D;
use KnowKit\Port\WorkerRunner;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Child.php';
require_once __DIR__ . '/LifecycleTest.php';

#[Group('worker:e2e')]
final class FailureTest extends TestCase
{
    private static string $extractionCache;

    public static function setUpBeforeClass(): void
    {
        self::$extractionCache = Protocol::temporary();
        self::prepareExtractionFixture();
    }

    private static function prepareExtractionFixture(): void
    {
        Harness::run(static function (Harness $h): void {
            $attempt = $h->attempt();
            $ref = $attempt->source->ref;
            // Qualification has no catalog job capability and cannot checkpoint or publish.
            $job = Protocol::job($attempt->source->bytes(), [
                'jobId' => Jobs::id(780), 'attemptId' => Jobs::id(781),
                'tenantId' => $ref->tenantId->value, 'kbId' => $attempt->lease->scope->kbId->value,
                'sourceId' => $ref->sourceId->value, 'sourceVersionId' => $ref->sourceVersionId->value,
                'fence' => '1', 'sourceEpoch' => $attempt->lease->sourceEpoch->value,
                'parserProfile' => $attempt->profile->id, 'parserSignature' => $attempt->profile->signature->toWire(),
                'profileFingerprint' => $attempt->profile->signature->profileDigest->value,
                'leaseExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
                'limits' => (new D\WorkerLimits(55, 45, 2147483648, 4294967296, 500, 67108864, 126))->toWire(),
            ]);
            $producer = new ExtractionFixtureCache(self::$extractionCache, Harness::supervisor($h->root . '/stages'),
                $attempt->source, $attempt->identity, new D\DocumentRevisionId(Jobs::id(701)));
            $producer->run($job, $attempt->source->bytes());
            self::assertNull($h->jobs->catalog->db->row('SELECT id FROM jobs WHERE tenant_id=? AND id=?',
                [$ref->tenantId->value, $job->toWire()->get('jobId')]));
            self::assertSame(0, $h->jobs->catalog->count('job_checkpoints'));
            self::assertSame(0, $h->jobs->catalog->count('publication_snapshots'));
        });
    }

    public static function tearDownAfterClass(): void
    {
        WorkerFiles::remove(self::$extractionCache);
    }

    private function extractionRunner(Harness $h, Attempt $attempt): ExtractionFixtureCache
    {
        return new ExtractionFixtureCache(self::$extractionCache, Harness::supervisor($h->root . '/stages'),
            $attempt->source, $attempt->identity, new D\DocumentRevisionId(Jobs::id(701)));
    }

    public function testKillBeforeUploadRequiresFreshExtraction(): void
    {
        $this->killExtraction('before-upload');
    }

    public function testKillAfterUploadRequiresFreshExtraction(): void
    {
        $this->killExtraction('after-upload');
    }

    public function testKillBeforeCheckpointRequiresFreshExtraction(): void
    {
        $this->killExtraction('before-checkpoint');
    }

    public function testKillAfterCheckpointReplaysIdenticalIrAssetsAndChunks(): void
    {
        $this->killExtraction('after-checkpoint');
    }

    private function killExtraction(string $phase): void
    {
        Harness::run(function (Harness $h) use ($phase): void {
            $old = $h->attempt();
            $child = new Child($h, $old, $phase, extractionCache: self::$extractionCache);
            try {
                $child->barrier();
            } finally {
                $child->kill();
            }
            self::assertNull($h->pointer());
            self::assertSame(0, $h->jobs->catalog->count('publication_snapshots'));
            $new = $h->takeover($old);
            self::assertNotEquals($old->lease->fence, $new->fence);
            self::assertFalse($h->pipeline->queue->complete($old->lease, $h->jobs->result()));
            $checkpoint = $h->pipeline->queue->resume($new, $old->identity);
            if ($phase !== 'after-checkpoint') {
                self::assertNull($checkpoint, $phase);
                $retry = new Attempt($new, $old->source, $old->profile, $old->identity);
                $fresh = $h->extract($retry, $this->extractionRunner($h, $retry));
                self::assertSame(D\Decision::Allow, $fresh->quality->decision);
                if ($phase === 'before-upload') {
                    self::assertTrue($h->jobs->row($new->jobId)['uncertain_external_effect']);
                }
                return;
            }
            self::assertNotNull($checkpoint);
            $replayed = $h->pipeline->replay($new, $old->identity);
            $set = $h->pipeline->persist($new, new D\DocumentRevisionRef($old->source->ref,
                new D\DocumentRevisionId(Jobs::id(701)), new D\IrDigest($replayed->result->irDigest()), $old->profile->signature), $replayed);
            $request = $h->pipeline->request($new, $set);
            $h->pipeline->seal($request);
            $h->pipeline->publish($request);
            self::assertNotNull($h->pointer());
            // The fresh walkthrough independently proves the same frozen v1.1 oracles.
            self::assertSame(Harness::expected()['freshIrDigest'], hash('sha256', $replayed->ir->canonical()));
            self::assertSame(Harness::expected()['freshChunkManifestDigest'], hash('sha256', $set->canonicalManifest()));
        });
    }

    public function testStaleLeaseCannotCheckpointCompleteOrPublishAfterTakeover(): void
    {
        Harness::run(function (Harness $h): void {
            $old = $h->attempt();
            $accepted = $h->extract($old, $this->extractionRunner($h, $old));
            $set = $h->pipeline->persist($old->lease, $accepted->revision, $accepted->output);
            $request = $h->pipeline->request($old->lease, $set);
            $h->pipeline->seal($request);
            $lease = $h->takeover($old);
            self::assertFalse($h->pipeline->queue->checkpoint($old->lease, $accepted->checkpoint));
            self::assertFalse($h->pipeline->queue->complete($old->lease, $h->jobs->result()));
            LifecycleTest::failure(fn () => $h->pipeline->publish($request), D\Outcome::Incompatible, 'writer_fenced');
            self::assertNull($h->pointer());
            self::assertNotNull($h->pipeline->queue->resume($lease, $old->identity));
            $next = $h->pipeline->request($lease, $set);
            $h->pipeline->publish($next);
            self::assertNotNull($h->pointer());
        });
    }

    public function testKillBeforePublicationCommitRollsBack(): void
    {
        $this->killPublication('before-publication-commit');
    }

    public function testKillAfterPublicationCommitReplaysReceipt(): void
    {
        $this->killPublication('after-publication');
    }

    private function killPublication(string $phase): void
    {
        Harness::run(function (Harness $h) use ($phase): void {
            $attempt = $h->attempt();
            $accepted = $h->extract($attempt, $this->extractionRunner($h, $attempt));
            $set = $h->pipeline->persist($attempt->lease, $accepted->revision, $accepted->output);
            $request = $h->pipeline->request($attempt->lease, $set);
            $h->pipeline->seal($request);
            $count = $h->jobs->catalog->count('outbox');
            $child = new Child($h, $attempt, $phase, 'publish', $request);
            try {
                $child->barrier();
            } finally {
                $child->kill();
            }
            $committed = $phase === 'after-publication';
            self::assertSame($committed ? 1 : 0, $h->jobs->catalog->count('publication_snapshots'));
            self::assertSame($count + (int) $committed, $h->jobs->catalog->count('outbox'));
            self::assertSame($committed, $h->pointer() !== null);
            $receipt = $h->pipeline->publish($request);
            $again = $h->pipeline->publish($request);
            self::assertSame($receipt->canonical(), $again->canonical());
            self::assertSame(1, $h->jobs->catalog->count('publication_snapshots'));
            self::assertSame($count + 1, $h->jobs->catalog->count('outbox'));
            self::assertSame($receipt->toWire()->get('snapshotId'), $h->pointer());
        });
    }

    public function testRevokeWhileRealOcrIsRunningStopsProcessAndRejectsOutput(): void
    {
        Harness::run(function (Harness $h): void {
            $attempt = $h->attempt();
            $observed = false;
            $runner = new ObservedRunner(Harness::supervisor($h->root . '/stages'), function () use ($h, $attempt, &$observed): void {
                if (!$observed && self::ocrIsRunning(getmypid())) {
                    $observed = true;
                    $h->pipeline->lifecycle()->revoke($attempt->lease->scope,
                        new D\Revocation($attempt->source->ref->sourceId, new D\Decimal('0')), new D\IdempotencyKey('revoke-during-ocr'));
                }
            });
            LifecycleTest::failure(fn () => $h->extract($attempt, $runner), D\Outcome::Denied);
            self::assertTrue($observed, 'Revocation must intersect an actual worker run, after negotiation');
            self::assertFalse(self::ocrIsRunning(getmypid()));
            self::assertSame([], glob($h->root . '/stages/*'));
            self::assertSame(0, $h->jobs->catalog->count('job_checkpoints'));
            self::assertNull($h->pointer());
        });
    }

    public function testCorruptManifestIsRejectedBeforeCheckpointOrPublication(): void
    {
        Harness::run(function (Harness $h): void {
            $attempt = $h->attempt('docling-pdf-text-v1', true);
            LifecycleTest::failure(fn () => $h->extract($attempt, new OracleRunner(true)), D\Outcome::Incompatible);
            self::assertSame(0, $h->jobs->catalog->count('job_checkpoints'));
            self::assertSame(0, $h->jobs->catalog->count('document_revisions'));
            self::assertNull($h->pointer());
        });
    }

    public function testCorruptCheckpointCannotResumeDespiteMatchingSourceBytes(): void
    {
        Harness::run(function (Harness $h): void {
            $attempt = $h->attempt('docling-pdf-text-v1', true);
            $accepted = $h->extract($attempt, new OracleRunner());
            $path = $h->jobs->directory . '/manifest-' . $accepted->checkpoint->digest->value;
            $wire = \KnowKit\Domain\Contract\StrictJson::decode(file_get_contents($path));
            file_put_contents($path, \KnowKit\Domain\Contract\Jcs::canonicalize($wire->with('sourceVersionId', Jobs::id(99))));
            $next = $h->takeover($attempt);
            LifecycleTest::failure(fn () => $h->pipeline->queue->resume($next, $attempt->identity),
                D\Outcome::Incompatible, 'checkpoint_digest_mismatch');
            self::assertNull($h->pointer());
            self::assertSame(0, $h->jobs->catalog->count('document_revisions'));
        });
    }

    private static function ocrIsRunning(int $pid): bool
    {
        $children = trim(@file_get_contents("/proc/{$pid}/task/{$pid}/children") ?: '');
        foreach ($children === '' ? [] : explode(' ', $children) as $child) {
            $command = str_replace("\0", ' ', @file_get_contents("/proc/{$child}/cmdline") ?: '');
            if ((str_contains($command, '/tesseract ') && str_contains($command, '.png')) || self::ocrIsRunning((int) $child)) {
                return true;
            }
        }

        return false;
    }
}

final readonly class ObservedRunner implements WorkerRunner
{
    public function __construct(
        private WorkerRunner $inner,
        private \Closure $during,
    ) {
    }

    public function run(D\JobEnvelope $job, string $source, ?\Closure $cancel = null): D\WorkerOutput
    {
        return $this->inner->run($job, $source, function () use ($cancel): bool {
            ($this->during)();

            return $cancel !== null && $cancel();
        });
    }
}
