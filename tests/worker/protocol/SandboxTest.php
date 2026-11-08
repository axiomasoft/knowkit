<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

use KnowKit\Adapter\ProcessWorker\ProcessWorkerSupervisor;
use KnowKit\Adapter\ProcessWorker\SandboxRuntime;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerCgroup;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Adapter\ProcessWorker\WorkerProcess;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:protocol')]
final class SandboxTest extends TestCase
{
    private static string $base;
    private static string $cgroup;

    public static function setUpBeforeClass(): void
    {
        self::$base = Fixture::temporary();
        mkdir(self::$base . '/stages', 0700);
        $argv = [Fixture::root() . '/python/knowkit-worker/.venv/bin/python',
            __DIR__ . '/build_fixture.py', self::$base . '/runtime'];
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::$cgroup = self::delegatedCgroup();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$base)) {
            WorkerFiles::remove(self::$base);
        }
    }

    protected function tearDown(): void
    {
        self::assertSame([], glob(self::$base . '/stages/*'), 'staging cleanup must follow quiescence');
    }

    public function testRealSandboxGoldenTerminalResult(): void
    {
        $bundle = $this->supervisor()->run(Fixture::job(), "KnowKit protocol fixture.\n");
        self::assertSame(Fixture::wire('result')->get('irDigest'), $bundle->result->irDigest());
    }

    public function testNestedSandboxPathsRemainPrivateAndValid(): void
    {
        $job = Fixture::job(changes: ['inputPath' => '/input/staged/source.bin', 'outDir' => '/output/private']);
        $bundle = $this->supervisor()->run($job, "KnowKit protocol fixture.\n");
        self::assertSame(Fixture::wire('result')->get('irDigest'), $bundle->result->irDigest());
    }

    public function testQuietInferenceAndDescendantStdoutHaveBoundedJoin(): void
    {
        $bundle = $this->supervisor()->run(Fixture::job('quiet'), 'quiet');
        self::assertSame('quiet', $bundle->ir->segments()[0]->get('text'));
        $start = microtime(true);
        try {
            $bundle = $this->supervisor()->run(Fixture::job('descendant'), 'descendant');
            self::assertSame('descendant', $bundle->ir->segments()[0]->get('text'));
        } catch (DomainFailure $failure) {
            self::assertSame('worker_timeout', $failure->errorCode);
        }
        self::assertLessThan(6, microtime(true) - $start);
    }

    public function testTimeoutKillsAndReapsWholeCgroup(): void
    {
        $this->failure('timeout', 'worker_timeout', ['wallSeconds' => 1]);
    }

    public function testOomKillsAndReapsWholeCgroup(): void
    {
        $this->failure('oom', 'worker_oom', ['memoryBytes' => '67108864']);
    }

    public function testCpuBudgetIsEnforced(): void
    {
        $this->failure('cpu', 'worker_timeout', ['cpuSeconds' => 1, 'wallSeconds' => 4]);
    }

    public function testCancelWhileBlockedKillsAndJoins(): void
    {
        $started = microtime(true);
        $this->expectExceptionMessage('cancelled');
        try {
            $this->supervisor()->run(Fixture::job('cancel'), 'cancel',
                static fn (): bool => glob(self::$base . '/stages/*/output/blocked') !== []);
        } finally {
            self::assertLessThan(5, microtime(true) - $started);
        }
    }

    public function testCancelBeforeLauncherJoinsCannotBlockReap(): void
    {
        // Lifecycle test for the trusted pre-exec window; real sandbox qualification is separate.
        $limits = Fixture::job()->limits();
        $group = WorkerCgroup::create(self::$cgroup, $limits);
        $start = microtime(true);
        try {
            WorkerProcess::run([Fixture::root() . '/python/knowkit-worker/.venv/bin/python',
                '-I', '-c', 'import time; time.sleep(30)'], self::$base . '/runtime/seccomp.bpf',
                $group, $limits, self::$base . '/stages', 3, static fn (): bool => true);
            self::fail('early cancellation ignored');
        } catch (DomainFailure $failure) {
            self::assertSame('cancelled', $failure->errorCode);
            self::assertLessThan(2, microtime(true) - $start);
        } finally {
            $group->close();
        }
    }

    public function testForbiddenNetworkAndLeastPrivilege(): void
    {
        $metadata = tmpfile();
        if ($metadata === false) {
            throw new \RuntimeException('Cannot create private metadata canary');
        }
        fwrite($metadata, 'CREDENTIAL_SECRET_CANARY');
        rewind($metadata);
        putenv('DATABASE_URL=CREDENTIAL_SECRET_CANARY');
        putenv('AWS_SECRET_ACCESS_KEY=CREDENTIAL_SECRET_CANARY');
        try {
            foreach (['network', 'least-privilege'] as $mode) {
                $bundle = $this->supervisor()->run(Fixture::job($mode), $mode);
                self::assertSame($mode, $bundle->ir->segments()[0]->get('text'));
                self::assertStringNotContainsString('CREDENTIAL_SECRET_CANARY', $bundle->result->canonical());
            }
        } finally {
            fclose($metadata);
            putenv('DATABASE_URL');
            putenv('AWS_SECRET_ACCESS_KEY');
        }
    }

    public function testFloodsCannotLeakRawOutputOrBlock(): void
    {
        foreach (['flood', 'stderr-flood'] as $mode) {
            $this->failure($mode, 'output_limit');
        }
    }

    public function testMalformedOversizeAndEofAreNotSuccess(): void
    {
        foreach (['eof', 'malformed', 'oversized'] as $mode) {
            try {
                $this->supervisor()->run(Fixture::job($mode), $mode);
                self::fail('missing/invalid terminal result accepted');
            } catch (DomainFailure $failure) {
                self::assertSame('unsafe_output', $failure->errorCode);
            } catch (\KnowKit\Domain\Contract\ContractViolation $failure) {
                self::assertSame('invalid_json', $failure->errorCode);
            }
        }
    }

    public function testTempAndOutputBudgets(): void
    {
        $bundle = $this->supervisor()->run(Fixture::job('temp'), 'temp');
        self::assertSame('temp', $bundle->ir->segments()[0]->get('text'));
        try {
            $this->supervisor()->run(Fixture::job('output-flood'), 'output-flood');
            self::fail('file budget ignored');
        } catch (DomainFailure $failure) {
            self::assertContains($failure->errorCode, ['worker_failed', 'output_limit']);
        }
    }

    public function testAssetInventoryIsIndependentlyVerified(): void
    {
        $bundle = $this->supervisor()->run(Fixture::job('asset'), 'asset');
        self::assertSame(['assets/image.png' => hex2bin('89504e470d0a1a0a')], $bundle->assets);
        self::assertSame(hash('sha256', $bundle->assets['assets/image.png']),
            $bundle->result->assets()[0]->digest);
    }

    public function testMissingSandboxOrCgroupNeverFallsBack(): void
    {
        mkdir(self::$base . '/ordinary-directory', 0700);
        foreach (['bubblewrap' => '/absent-bwrap', 'cgroup' => self::$base . '/ordinary-directory'] as $key => $path) {
            $runtime = $this->runtime($key === 'bubblewrap' ? $path : '/usr/bin/bwrap',
                $key === 'cgroup' ? $path : self::$cgroup);
            try {
                (new ProcessWorkerSupervisor($runtime, new WorkerIngressBundleValidator(Fixture::validator())))
                    ->run(Fixture::job(), "KnowKit protocol fixture.\n");
                self::fail('unsandboxed fallback');
            } catch (DomainFailure $failure) {
                self::assertSame(Outcome::Unavailable, $failure->outcome);
                self::assertSame('sandbox_unavailable', $failure->errorCode);
            }
        }
    }

    public function testPidsAreBoundedAndRuntimeTamperingIsUnavailable(): void
    {
        $bundle = $this->supervisor()->run(Fixture::job('pids'), 'pids');
        self::assertSame('pids', $bundle->ir->segments()[0]->get('text'));
        $unlisted = self::$base . '/runtime/runtime/lib/python3.12/site-packages/unlisted.py';
        file_put_contents($unlisted, '# not in BOM');
        try {
            $this->runtime()->assertAvailable();
            self::fail('unlisted runtime module accepted');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Unavailable, $failure->outcome);
            self::assertSame('sandbox_unavailable', $failure->errorCode);
        } finally {
            unlink($unlisted);
        }
    }

    private function failure(string $mode, string $code, array $limits = []): void
    {
        $configured = Fixture::wire('envelope')->get('limits');
        foreach ($limits as $key => $value) {
            $configured = $configured->with($key, $value);
        }
        $start = microtime(true);
        try {
            $this->supervisor()->run(Fixture::job($mode, ['limits' => $configured]), $mode);
            self::fail('worker did not refuse');
        } catch (DomainFailure $failure) {
            self::assertSame($code, $failure->errorCode);
            self::assertStringNotContainsString('SECRET_CANARY', $failure->getMessage());
        }
        self::assertLessThan(6, microtime(true) - $start);
    }

    private function supervisor(): ProcessWorkerSupervisor
    {
        return new ProcessWorkerSupervisor($this->runtime(), new WorkerIngressBundleValidator(Fixture::validator()));
    }

    private function runtime(string $bubblewrap = '/usr/bin/bwrap', ?string $cgroup = null): SandboxRuntime
    {
        return new SandboxRuntime(self::$base . '/runtime', self::$base . '/stages', $cgroup ?? self::$cgroup,
            Fixture::root() . '/python/knowkit-worker/.venv/bin/python',
            Fixture::root() . '/deploy/worker/cgroup_exec.py', $bubblewrap);
    }

    private static function delegatedCgroup(): string
    {
        $override = getenv('KNOWKIT_TEST_CGROUP_PARENT');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $path = '/sys/fs/cgroup' . trim(explode('::', file_get_contents('/proc/self/cgroup'))[1]);
        while (str_starts_with($path, '/sys/fs/cgroup/')) {
            $enabled = explode(' ', trim(file_get_contents($path . '/cgroup.subtree_control')));
            if (is_writable($path) && array_diff(['cpu', 'memory', 'pids'], $enabled) === []) {
                return $path;
            }
            $path = dirname($path);
        }
        throw new \RuntimeException('mandatory delegated cpu/memory/pids cgroup unavailable');
    }
}
