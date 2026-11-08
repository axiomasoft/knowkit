<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Domain as D;
use KnowKit\Port\WorkerRunner;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Worker\Acceptance\FixtureRunner;
use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/acceptance/Harness.php';

#[Group('worker:e2e')]
final class ExtractionFixtureCacheTest extends TestCase
{
    public function testWarmEntryRevalidatesBytesAndRebindsOnlyAttemptFields(): void
    {
        $root = Protocol::temporary();
        try {
            $job = Protocol::job();
            $producer = new FixtureRunner();
            $cache = $this->cache($root, $job, $producer);
            $first = $cache->run($job, $this->bytes());
            $next = new D\JobEnvelope($job->toWire()->with('fence', '8'), Protocol::validator());
            $reused = $cache->run($next, $this->bytes());
            self::assertSame(1, $producer->runs);
            self::assertSame($first->ir->canonical(), $reused->ir->canonical());
            self::assertSame($first->assets, $reused->assets);
            self::assertSame('8', $reused->result->toWire()->get('fence'));
            self::assertNotEquals($first->result->resultDigest(), $reused->result->resultDigest());
            $reused->result->assertOutput($next, $reused->ir);
            $path = $cache->directory($job) . '/bundle/ir.json';
            file_put_contents($path, file_get_contents($path) . ' ');
            $this->failure(fn () => $cache->run($next, $this->bytes()), 'digest_mismatch');
            self::assertSame(1, $producer->runs, 'Corrupt entry must fail, never silently regenerate');
        } finally {
            WorkerFiles::remove($root);
        }
    }

    public function testFullIdentityKeyAndCopiedReceiptRejectIncompatibleReuse(): void
    {
        $root = Protocol::temporary();
        try {
            $job = Protocol::job();
            $producer = new FixtureRunner();
            $base = $this->cache($root, $job, $producer);
            $base->run($job, $this->bytes());
            foreach (['parser', 'ocr', 'chunker', 'index', 'embedding'] as $axis) {
                $changed = $this->cache($root, $job, $producer, $axis);
                self::assertNotSame($base->directory($job), $changed->directory($job), $axis);
            }
            $changed = $this->cache($root, $job, $producer, 'chunker');
            $this->copyEntry($base->directory($job), $changed->directory($job));
            $this->failure(fn () => $changed->run($job, $this->bytes()), 'cache_identity_mismatch');
            $foreignSource = new D\JobEnvelope($job->toWire()->with('sourceId', PortFixture::id(999)), Protocol::validator());
            $this->failure(fn () => $base->run($foreignSource, $this->bytes()), 'immutable_conflict');
            $foreignTenant = new D\JobEnvelope($job->toWire()->with('tenantId', PortFixture::id(999)), Protocol::validator());
            try {
                $base->run($foreignTenant, $this->bytes());
                self::fail('Foreign tenant reused a cached extraction');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Denied, $failure->outcome);
                self::assertSame('scope_mismatch', $failure->errorCode);
            }
            self::assertSame(1, $producer->runs);
        } finally {
            WorkerFiles::remove($root);
        }
    }

    public function testColdCachePropagatesMandatoryProducerUnavailability(): void
    {
        $root = Protocol::temporary();
        try {
            $job = Protocol::job();
            $producer = new class implements WorkerRunner {
                public function run(D\JobEnvelope $job, string $source, ?\Closure $cancel = null): D\WorkerOutput
                {
                    throw new D\DomainFailure(D\Outcome::Unavailable, 'sandbox_unavailable');
                }
            };
            $cache = $this->cache($root, $job, $producer);
            try {
                $cache->run($job, $this->bytes());
                self::fail('Cold fixture cache hid an unavailable mandatory producer');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Unavailable, $failure->outcome);
                self::assertSame('sandbox_unavailable', $failure->errorCode);
            }
            self::assertDirectoryDoesNotExist($cache->directory($job));
        } finally {
            WorkerFiles::remove($root);
        }
    }

    private function cache(string $root, D\JobEnvelope $job, WorkerRunner $producer, string $axis = ''): ExtractionFixtureCache
    {
        $wire = $job->toWire();
        $ref = new D\SourceVersionRef(new D\TenantId($wire->get('tenantId')), new D\SourceId($wire->get('sourceId')),
            new D\SourceVersionId($wire->get('sourceVersionId')), new D\SourceDigest(hash('sha256', $this->bytes())));
        $parser = PortFixture::signature($wire->get('parserSignature'));
        $other = new D\ParserSignature('changed', '2', new D\Fingerprint(hash('sha256', 'changed')));
        $processing = new D\ProcessingIdentity($ref->sourceVersionId, $ref->sourceDigest,
            $axis === 'parser' ? $other : $parser, $axis === 'ocr' ? $other : $parser,
            $axis === 'chunker' ? $other : $parser, 'ir-1.0',
            new D\Fingerprint(hash('sha256', $axis === 'index' ? 'changed' : 'index')),
            $axis === 'embedding' ? new D\Fingerprint(hash('sha256', 'embedding')) : null);

        return new ExtractionFixtureCache($root, $producer, new D\SourceBytes($ref, $this->bytes(), 'text/plain'),
            $processing, new D\DocumentRevisionId(PortFixture::id(701)));
    }

    private function bytes(): string
    {
        return file_get_contents(dirname(__DIR__) . '/protocol/fixtures/v1/source.txt');
    }

    private function failure(\Closure $run, string $code): void
    {
        try {
            $run();
            self::fail('Invalid cached artifact accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            self::assertSame($code, $failure->errorCode);
        }
    }

    private function copyEntry(string $source, string $target): void
    {
        mkdir($target, 0700);
        mkdir($target . '/bundle', 0700);
        foreach (['job.json', 'entry.bin', 'capabilities.json', 'bundle/ir.json', 'bundle/result.json'] as $file) {
            copy($source . '/' . $file, $target . '/' . $file);
        }
    }
}
