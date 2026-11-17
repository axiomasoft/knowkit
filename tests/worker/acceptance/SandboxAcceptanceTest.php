<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Acceptance;

use KnowKit\Adapter\ProcessWorker\ProcessWorkerSupervisor;
use KnowKit\Adapter\ProcessWorker\SandboxRuntime;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Worker\Protocol\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

/** Real subprocess, bwrap, seccomp, cgroup and PG. Synthetic parser only. Never skipped. */
#[Group('worker:acceptance')]
final class SandboxAcceptanceTest extends TestCase
{
    private static string $root;
    private static string $cgroup;

    public static function setUpBeforeClass(): void
    {
        self::$root = Fixture::temporary();
        mkdir(self::$root . '/stages', 0700);
        $process = proc_open([Fixture::root() . '/python/knowkit-worker/.venv/bin/python',
            __DIR__ . '/build_runtime.py', self::$root . '/runtime'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::$cgroup = self::delegatedCgroup();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$root)) {
            WorkerFiles::remove(self::$root);
        }
    }

    public function testRealSupervisorNegotiatesAndClientAcceptsOnlyScopedPrivateOutput(): void
    {
        Harness::run(function (Harness $h): void {
            $accepted = $h->execute($this->supervisor());
            self::assertSame('least-privilege', $accepted->output->ir->segments()[0]->get('text'));
            self::assertSame('succeeded', $h->jobs->row()['state']);
            self::assertSame([], glob(self::$root . '/stages/*'));
            self::assertCount(2, $h->refs);
        }, 'least-privilege');
    }

    public function testMissingSandboxNeverStartsAnUnsandboxedFallback(): void
    {
        Harness::run(function (Harness $h): void {
            try {
                $h->execute($this->supervisor('/absent-bwrap'));
                self::fail('Missing sandbox accepted');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Unavailable, $failure->outcome);
                self::assertSame('sandbox_unavailable', $failure->errorCode);
                self::assertNull($h->jobs->row()['checkpoint_digest']);
            }
        });
    }

    public function testRuntimeCannotNegotiateAnUnsupportedOrUnqualifiedProfile(): void
    {
        // Reuse the versioned profile identity, without running or qualifying PDF extraction.
        // A stale signature is incompatible even when the matching runtime is unavailable.
        $signature = StrictJson::decode(file_get_contents(Fixture::root()
            . '/tests/fixtures/documents/pdf/v1/unicode-label.ir.json'))->get('parserSignature');
        $cases = [
            ['unsupported', $signature, D\Outcome::Incompatible],
            ['docling-pdf-text-v1', $signature->with('profileDigest', str_repeat('0', 64)), D\Outcome::Incompatible],
            ['docling-pdf-text-v1', $signature, D\Outcome::Unavailable],
        ];
        foreach ($cases as [$id, $requestedSignature, $outcome]) {
            $job = Fixture::job(changes: ['parserProfile' => $id, 'parserSignature' => $requestedSignature,
                'profileFingerprint' => $requestedSignature->get('profileDigest')]);
            try {
                $this->supervisor()->run($job, "KnowKit protocol fixture.\n");
                self::fail('Runtime silently changed profile');
            } catch (D\DomainFailure $failure) {
                self::assertSame($outcome, $failure->outcome);
                self::assertSame('worker_failed', $failure->errorCode);
                self::assertSame([], glob(self::$root . '/stages/*'));
            }
        }
    }

    private function supervisor(string $bubblewrap = '/usr/bin/bwrap'): ProcessWorkerSupervisor
    {
        return new ProcessWorkerSupervisor(new SandboxRuntime(self::$root . '/runtime', self::$root . '/stages', self::$cgroup,
            Fixture::root() . '/python/knowkit-worker/.venv/bin/python', Fixture::root() . '/deploy/worker/cgroup_exec.py', $bubblewrap),
            new WorkerIngressBundleValidator(Fixture::validator()));
    }

    private static function delegatedCgroup(): string
    {
        $configured = getenv('KNOWKIT_TEST_CGROUP_PARENT');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $path = '/sys/fs/cgroup' . trim(explode('::', file_get_contents('/proc/self/cgroup'))[1]);
        while (str_starts_with($path, '/sys/fs/cgroup/')) {
            $enabled = explode(' ', trim(file_get_contents($path . '/cgroup.subtree_control')));
            if (is_writable($path) && array_diff(['cpu', 'memory', 'pids'], $enabled) === []) {
                return $path;
            }
            $path = dirname($path);
        }
        throw new \RuntimeException('mandatory delegated cgroup unavailable');
    }
}
