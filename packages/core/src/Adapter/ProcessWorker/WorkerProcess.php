<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\WorkerLimits;

/** Lifecycle adapted from DeepTutor isolated_worker at f7aed0744c36c5afef16a4261c31ebee1208cac2.
 * Copyright 2025 Data Intelligence Lab, The University of Hong Kong. AGPL-3.0-only;
 * private use, notices: docs/architecture/third-party/DeepTutor-LICENSE.txt. No pickle/callable dispatch.
 */
final class WorkerProcess
{
    /** @param list<string> $argv */
    public static function run(
        array $argv,
        string $seccomp,
        WorkerCgroup $group,
        WorkerLimits $limits,
        string $output,
        float $seconds,
        ?\Closure $cancel,
    ): int {
        $process = proc_open(
            $argv,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
                3 => ['file', $seccomp, 'r'],
            ],
            $pipes,
            null,
            ['LANG' => 'C.UTF-8', 'PATH' => '/usr/bin:/bin'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            return self::monitor($process, $pipes, $group, $limits, $output, $seconds, $cancel);
        } finally {
            try {
                try {
                    // Cancellation may arrive before the trusted launcher has joined its cgroup.
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process, 9);
                    }
                    $group->stop();
                } finally {
                    self::join($process);
                }
            } finally {
                foreach ($pipes as $pipe) {
                    @fclose($pipe);
                }
                @proc_close($process);
            }
        }
    }

    /**
     * @param resource $process
     */
    private static function join(mixed $process): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        while (proc_get_status($process)['running']) {
            if (hrtime(true) >= $deadline) {
                throw new DomainFailure(Outcome::Unavailable, 'sandbox_not_quiescent');
            }
            usleep(10000);
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private static function monitor(
        mixed $process,
        array $pipes,
        WorkerCgroup $group,
        WorkerLimits $limits,
        string $output,
        float $seconds,
        ?\Closure $cancel,
    ): int {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
        $count = [1 => 0, 2 => 0];
        $exit = null;
        while (true) {
            foreach ($pipes as $index => $pipe) {
                $chunk = stream_get_contents($pipe, 65537);
                $count[$index] += is_string($chunk) ? strlen($chunk) : 0;
                if ($count[$index] > 65536) {
                    throw new DomainFailure(Outcome::Incompatible, 'output_limit');
                }
            }
            self::assertBudget($group, $limits, $output, $deadline, $cancel);
            if ($exit === null) {
                $state = proc_get_status($process);
                if (!$state['running']) {
                    $exit = $state['signaled'] ? 128 + $state['termsig'] : $state['exitcode'];
                }
            }
            if ($exit !== null && $group->value('cgroup.events', 'populated') === 0) {
                if (!feof($pipes[1]) || !feof($pipes[2])) {
                    continue;
                }
                return $exit;
            }
            usleep(10000);
        }
    }

    private static function assertBudget(
        WorkerCgroup $group,
        WorkerLimits $limits,
        string $output,
        int $deadline,
        ?\Closure $cancel,
    ): void {
        if ($cancel !== null && $cancel()) {
            throw new DomainFailure(Outcome::Denied, 'cancelled');
        }
        if ($group->value('memory.events', 'oom_kill') > 0) {
            throw new DomainFailure(Outcome::Unavailable, 'worker_oom');
        }
        if (hrtime(true) >= $deadline || $group->value('cpu.stat', 'usage_usec') > $limits->cpuSeconds * 1_000_000) {
            throw new DomainFailure(Outcome::Unavailable, 'worker_timeout');
        }
        $files = WorkerFiles::inventory($output, $limits->maxOutputBytes);
        if (count($files) > $limits->maxAssets + 2) {
            throw new DomainFailure(Outcome::Incompatible, 'output_limit');
        }
    }
}
