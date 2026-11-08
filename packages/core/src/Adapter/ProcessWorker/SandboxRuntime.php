<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\WorkerLimits;

/** Host configuration only. Never constructed from a public request or job envelope. */
final readonly class SandboxRuntime
{
    public function __construct(
        public string $runtimeRoot,
        public string $stagingParent,
        public string $cgroupParent,
        public string $python,
        public string $launcher,
        public string $bubblewrap = '/usr/bin/bwrap',
    ) {
    }

    public function assertAvailable(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_getuid') || !function_exists('proc_open')
            || !function_exists('proc_get_status') || !function_exists('proc_close') || posix_getuid() === 0
            || !is_executable($this->bubblewrap) || !is_executable($this->python)
            || !is_file($this->launcher) || !is_writable($this->stagingParent)
            || !is_writable($this->cgroupParent)) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
        $this->assertBom();
    }

    private function assertBom(): void
    {
        $bytes = @file_get_contents($this->runtimeRoot . '/bom.json');
        $bom = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($bom) || ($bom['schemaVersion'] ?? '') !== 'worker-runtime-bom-1.0'
            || !is_array($bom['files'] ?? null)
            || empty($bom['files']['seccomp.bpf']) || empty($bom['files']['runtime/bin/knowkit-worker'])) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
        foreach ($bom['files'] as $relative => $digest) {
            $path = $this->runtimeRoot . '/' . $relative;
            if (!is_string($relative) || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || !WorkerFiles::relative($relative) || is_link($path) || !is_file($path)
                || !hash_equals($digest, hash_file('sha256', $path))) {
                throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
            }
        }
        $inventory = WorkerFiles::inventory($this->runtimeRoot, 1073741824);
        unset($inventory['bom.json']);
        if (array_diff(array_keys($inventory), array_keys($bom['files'])) !== []) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
    }

    /** @return list<string> */
    public function argv(string $stage, string $group, WorkerLimits $limits, bool $probe): array
    {
        $command = $probe
            ? ['/runtime/bin/knowkit-worker-probe']
            : ['/runtime/bin/knowkit-worker', 'run', '--envelope', '/input/job.json'];
        return [
            $this->python, '-I', $this->launcher, $group, (string) $limits->cpuSeconds,
            (string) $limits->maxOutputBytes, $this->bubblewrap,
            '--unshare-all', '--die-with-parent', '--new-session', '--cap-drop', 'ALL',
            '--ro-bind', $this->runtimeRoot, '/', '--ro-bind', $stage . '/input', '/input',
            '--bind', $stage . '/output', '/output', '--size', (string) $limits->tempBytes,
            '--tmpfs', '/tmp', '--proc', '/proc', '--dev', '/dev', '--chdir', '/output',
            '--clearenv', '--setenv', 'PATH', '/runtime/bin', '--setenv', 'LANG', 'C.UTF-8',
            '--setenv', 'PYTHONDONTWRITEBYTECODE', '1', '--seccomp', '3', '--', ...$command,
        ];
    }
}
