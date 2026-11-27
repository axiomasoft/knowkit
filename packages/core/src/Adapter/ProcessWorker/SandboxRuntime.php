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
        $bytes = $this->readRegular($this->runtimeRoot . '/bom.json', 134217728);
        $bom = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($bom) || ($bom['schemaVersion'] ?? '') !== 'worker-runtime-bom-1.0'
            || !is_array($bom['files'] ?? null)
            || empty($bom['files']['seccomp.bpf']) || empty($bom['files']['runtime/bin/knowkit-worker'])) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
        $inventory = $this->runtimeInventory();
        foreach ($bom['files'] as $relative => $digest) {
            $fileDigest = is_string($relative) && is_string($digest) ? $this->digestFile($relative) : null;
            if (!is_string($relative) || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || !is_string($fileDigest) || !hash_equals($digest, $fileDigest)) {
                throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
            }
        }
        unset($inventory['bom.json']);
        if (array_diff(array_keys($inventory), array_keys($bom['files'])) !== []) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
    }

    /** Hash the opened inode. hash_file() would follow a symlink planted in the runtime tree. */
    private function digestFile(string $relative): ?string
    {
        $handle = $this->openRegular($this->checkedPath($relative), PHP_INT_MAX);
        if ($handle === null) {
            return null;
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $handle);

            return hash_final($hash);
        } finally {
            fclose($handle);
        }
    }

    private function readRegular(string $path, int $maxBytes): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return null;
        }
        $handle = $this->openRegular($path, $maxBytes);
        if ($handle === null) {
            return null;
        }
        try {
            $opened = fstat($handle);
            $bytes = $opened === false ? false : stream_get_contents($handle, $maxBytes + 1);
            if (!is_string($bytes) || strlen($bytes) !== $opened['size']) {
                return null;
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** @return resource|null */
    private function openRegular(string $path, int $maxBytes): mixed
    {
        clearstatcache(true, $path);
        if ($path === '' || is_link($path)) {
            return null;
        }
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || $stat['size'] > $maxBytes) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $opened = fstat($handle);
        if ($opened === false || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']
            || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1 || $opened['size'] > $maxBytes) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function checkedPath(string $relative): string
    {
        if (!self::runtimePath($relative)) {
            return '';
        }
        $path = $this->runtimeRoot;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            clearstatcache(true, $path);
            if (is_link($path)) {
                return '';
            }
        }

        return $path;
    }

    private static function runtimePath(string $path): bool
    {
        return strlen($path) <= 1024
            && preg_match('~\A[ A-Za-z0-9_.+()\[\]-]{1,128}(/[ A-Za-z0-9_.+()\[\]-]{1,128}){0,31}\z~D', $path) === 1
            && array_intersect(explode('/', $path), ['.', '..']) === [];
    }

    /** @return array<string, int> */
    private function runtimeInventory(): array
    {
        $files = [];
        $bytes = 0;
        $count = 0;
        try {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->runtimeRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($entries as $entry) {
                if (++$count > 65536 || $entry->isLink()) {
                    throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
                }
                if ($entry->isDir()) {
                    continue;
                }
                $relative = substr($entry->getPathname(), strlen($this->runtimeRoot) + 1);
                $stat = @lstat($entry->getPathname());
                if ($stat === false) {
                    throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
                }
                $bytes += $stat['size'];
                if (!self::runtimePath($relative) || ($stat['mode'] & 0170000) !== 0100000
                    || $stat['nlink'] !== 1 || $bytes > 4294967296) {
                    throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
                }
                $files[$relative] = $stat['size'];
            }
        } catch (\UnexpectedValueException) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }

        return $files;
    }

    /** @return list<string> */
    public function argv(string $stage, string $group, WorkerLimits $limits, bool $probe, string $command = 'run'): array
    {
        if (!in_array($command, ['run', 'negotiate'], true)) {
            throw new DomainFailure(Outcome::Incompatible, 'unsupported_worker_command');
        }
        $command = $probe
            ? ['/runtime/bin/knowkit-worker-probe']
            : ['/runtime/bin/knowkit-worker', $command, '--envelope', '/input/job.json'];
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
