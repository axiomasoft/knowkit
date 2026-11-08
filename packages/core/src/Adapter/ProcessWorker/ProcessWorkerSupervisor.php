<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\JobEnvelope;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Capability;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\ParserCapabilities;
use KnowKit\Port\WorkerRunner;

final readonly class ProcessWorkerSupervisor implements WorkerRunner
{
    public function __construct(
        private SandboxRuntime $runtime,
        private WorkerIngressBundleValidator $validator,
    ) {
    }

    public function run(JobEnvelope $job, #[\SensitiveParameter] string $source, ?\Closure $cancel = null): WorkerBundle
    {
        $job->assertStagingPaths();
        if (strlen($source) > 67108864 || !hash_equals($job->inputDigest(), hash('sha256', $source))) {
            throw new DomainFailure(Outcome::Incompatible, 'input_digest_mismatch');
        }
        if ($job->secondsRemaining() <= 0) {
            throw new DomainFailure(Outcome::Denied, 'stale_lease');
        }
        $this->runtime->assertAvailable();
        $limits = $job->limits();
        $group = WorkerCgroup::create($this->runtime->cgroupParent, $limits);
        $stage = $this->runtime->stagingParent . '/job-' . bin2hex(random_bytes(12));
        try {
            $this->stage($stage, $job, $source);
            $deadline = hrtime(true) + (int) (min($limits->wallSeconds, $job->secondsRemaining()) * 1_000_000_000);
            $this->execute($stage, $group, $job, $deadline, $cancel, true);
            $this->execute($stage, $group, $job, $deadline, $cancel, false, 'negotiate');
            $capabilities = $this->negotiated($stage, $job);
            $this->execute($stage, $group, $job, $deadline, $cancel, false);
            $bundle = $this->validator->verify($job, $stage . '/output');
            return new WorkerBundle($bundle->result, $bundle->ir, $bundle->assets, $capabilities);
        } finally {
            // Keep staging if quiescence cannot be proved; never race a live writer.
            $group->close();
            if (is_dir($stage)) {
                WorkerFiles::remove($stage);
            }
        }
    }

    private function stage(string $stage, JobEnvelope $job, string $source): void
    {
        mkdir($stage, 0700);
        mkdir($stage . '/input', 0700);
        mkdir($stage . '/output', 0700);
        $input = $stage . '/input/' . $job->inputRelativePath();
        if (!is_dir(dirname($input))) {
            mkdir(dirname($input), 0700, true);
        }
        $output = $stage . '/output/' . $job->outputRelativePath();
        if (!is_dir($output)) {
            mkdir($output, 0700, true);
        }
        file_put_contents($input, $source);
        file_put_contents($stage . '/input/job.json', $job->canonical());
        chmod($input, 0400);
        chmod($stage . '/input/job.json', 0400);
        chmod($stage . '/input', 0500);
    }

    private function execute(string $stage, WorkerCgroup $group, JobEnvelope $job,
        int $deadline, ?\Closure $cancel, bool $probe, string $command = 'run'): void
    {
        $limits = $job->limits();
        $seconds = max(0.0, ($deadline - hrtime(true)) / 1_000_000_000);
        $exit = WorkerProcess::run($this->runtime->argv($stage, $group->path, $limits, $probe, $command),
            $this->runtime->runtimeRoot . '/seccomp.bpf', $group, $limits, $stage . '/output', $seconds, $cancel);
        if ($exit !== 0) {
            if ($probe) {
                throw new DomainFailure(Outcome::Unavailable, 'sandbox_unavailable');
            }
            $outcome = match ($exit) {
                3 => Outcome::Denied,
                5 => Outcome::Incompatible,
                default => Outcome::Unavailable,
            };
            throw new DomainFailure($outcome, 'worker_failed');
        }
    }

    private function negotiated(string $stage, JobEnvelope $job): ParserCapabilities
    {
        $out = $stage . '/output';
        if ($job->outputRelativePath() !== '') {
            $out .= '/' . $job->outputRelativePath();
        }
        $wire = StrictJson::decode(WorkerFiles::read($out, 'negotiated.json', 65536));
        $expected = $job->toWire();
        if ($wire->get('schemaVersion') !== 'worker-negotiation-1.0' || $wire->get('transport') !== 'subprocess'
            || $wire->get('jobSchema') !== 'job-envelope-1.0' || $wire->get('resultSchema') !== 'worker-result-1.0'
            || $wire->get('irSchema') !== 'ir-1.0' || $wire->get('network') !== false
            || $wire->get('parserProfile') !== $expected->get('parserProfile')
            || $wire->get('parserSignature') != $expected->get('parserSignature')
            || !is_array($wire->get('capabilities')) || count($wire->get('capabilities')) > 64) {
            throw new DomainFailure(Outcome::Incompatible, 'capabilities_mismatch');
        }
        $capabilities = [];
        foreach ($wire->get('capabilities') as $name) {
            $capabilities[] = is_string($name) ? (Capability::tryFrom($name)
                ?? throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability'))
                : throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability');
        }
        unlink($out . '/negotiated.json');
        return new ParserCapabilities($capabilities, ['ir-1.0']);
    }
}
