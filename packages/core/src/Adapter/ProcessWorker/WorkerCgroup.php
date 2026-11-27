<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\WorkerLimits;

final readonly class WorkerCgroup
{
    public function __construct(public string $path)
    {
    }

    public static function create(string $parent, WorkerLimits $limits): self
    {
        $group = new self($parent . '/knowkit-' . bin2hex(random_bytes(12)));
        if (!@mkdir($group->path, 0700)) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
        try {
            foreach (['memory.max' => $limits->memoryBytes, 'memory.swap.max' => 0,
                'memory.oom.group' => 1, 'pids.max' => 64, 'cpu.max' => '100000 100000'] as $file => $value) {
                $payload = (string) $value;
                if (!is_file($group->path . '/' . $file)
                    || @file_put_contents($group->path . '/' . $file, $payload) !== strlen($payload)) {
                    throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
                }
            }
            if (!is_writable($group->path . '/cgroup.kill')) {
                throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
            }
        } catch (\Throwable $failure) {
            rmdir($group->path);
            throw $failure;
        }
        return $group;
    }

    public function value(string $file, string $key): int
    {
        $values = file_get_contents($this->path . '/' . $file);
        if ($values === false || preg_match('/^' . preg_quote($key, '/') . ' ([0-9]+)$/m', $values, $match) !== 1) {
            throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
        }
        return (int) $match[1];
    }

    public function stop(): void
    {
        if ($this->value('cgroup.events', 'populated') !== 0) {
            $written = @file_put_contents($this->path . '/cgroup.kill', '1');
            if ($written !== 1 && $this->value('cgroup.events', 'populated') !== 0) { // @phpstan-ignore notIdentical.alwaysTrue
                throw new DomainFailure(Outcome::Unavailable, 'sandbox_not_quiescent');
            }
        }
        $deadline = hrtime(true) + 2_000_000_000;
        while ($this->value('cgroup.events', 'populated') !== 0) {
            if (hrtime(true) >= $deadline) {
                throw new DomainFailure(Outcome::Unavailable, 'sandbox_not_quiescent');
            }
            usleep(10000);
        }
    }

    public function close(): void
    {
        $this->stop();
        rmdir($this->path);
    }
}
