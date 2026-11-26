<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\ProcessWorker\ProcessWorkerSupervisor;
use KnowKit\Adapter\ProcessWorker\SandboxRuntime;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain as D;
use KnowKit\Tests\Worker\Ocr\OcrFixture;
use KnowKit\Tests\Worker\Protocol\Fixture;
use KnowKit\Tooling\Qa;

require_once Report::root() . '/tests/worker/ocr/OcrFixture.php';

final class Ocr
{
    public static function run(): array
    {
        $base = Qa::temporary();
        mkdir($base . '/stages', 0700);
        $parent = self::parent();
        $group = $parent . '/knowkit-capacity-' . bin2hex(random_bytes(8));
        Qa::ensure(mkdir($group, 0700), 'required writable worker cgroup unavailable');
        file_put_contents($group . '/cgroup.subtree_control', '+cpu +memory +pids');
        try {
            return self::batch(base: $base, group: $group);
        } finally {
            WorkerFiles::remove($base);
            rmdir($group);
        }
    }

    private static function parent(): string
    {
        $configured = getenv('KNOWKIT_TEST_CGROUP_PARENT');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $membership = \KnowKit\Tooling\LocalIO::read('/proc/self/cgroup');
        if (preg_match('/^0::([^\r\n]+)$/m', $membership, $match) !== 1) {
            throw new \RuntimeException('Required delegated OCR cgroup unavailable');
        }
        $group = $match[1];
        // A lexical prefix check accepts "/sys/fs/cgroup/../../tmp". Reject dot segments before joining.
        if (!str_starts_with($group, '/') || str_contains($group, "\0")
            || in_array('.', explode('/', $group), true) || in_array('..', explode('/', $group), true)) {
            throw new \RuntimeException('Required delegated OCR cgroup unavailable');
        }
        $path = '/sys/fs/cgroup' . $group;
        while (str_starts_with($path, '/sys/fs/cgroup/')) {
            $enabled = explode(' ', trim(\KnowKit\Tooling\LocalIO::read($path . '/cgroup.subtree_control')));
            if (is_writable($path) && array_diff(['cpu', 'memory', 'pids'], $enabled) === []) {
                return $path;
            }
            $path = dirname($path);
        }
        throw new \RuntimeException('Required delegated OCR cgroup unavailable');
    }

    private static function batch(string $base, string $group): array
    {
        $runtime = new SandboxRuntime(
            runtimeRoot: getenv('KNOWKIT_OCR_RUNTIME') ?: Report::root() . '/python/knowkit-worker/.local-checks/ocr-runtime',
            stagingParent: $base . '/stages', cgroupParent: $group,
            python: Report::root() . '/python/knowkit-worker/.venv/bin/python',
            launcher: Report::root() . '/deploy/worker/cgroup_exec.py',
        );
        \KnowKit\Tests\Worker\Protocol\RuntimeCandidate::requireCurrent($runtime->runtimeRoot, Report::root());
        $supervisor = new ProcessWorkerSupervisor(runtime: $runtime, validator: new WorkerIngressBundleValidator(Fixture::validator()));
        $source = \KnowKit\Tooling\LocalIO::read(OcrFixture::corpus() . '/approved-eng.pdf');
        $expected = OcrFixture::document('approved-eng');
        $signature = $expected->toWire()->get('parserSignature');
        $quotas = Report::profile()['quotas'];
        $documents = Report::intField($quotas, 'ocrBatchDocuments');
        $limits = Fixture::wire('envelope')->get('limits')->with('wallSeconds', 60)->with('cpuSeconds', 45)
            ->with('memoryBytes', (string) Report::intField($quotas, 'workerMemoryBytes'))->with('maxOutputBytes', '67108864')->with('tempBytes', '4294967296')
            ->with('maxPages', Report::intField($quotas, 'workerMaxPages'))->with('maxAssets', Report::intField($quotas, 'workerMaxAssets'));
        $samples = $memory = $cpu = [];
        for ($i = 0; $i < $documents; ++$i) {
            $job = Fixture::job(source: $source, changes: ['parserProfile' => 'docling-pdf-ocr-eng-v1',
                'parserSignature' => $signature, 'profileFingerprint' => $signature->get('profileDigest'), 'limits' => $limits]);
            [$peak, $used] = self::sample(supervisor: $supervisor, job: $job, source: $source, group: $group, expected: $expected, samples: $samples);
            $memory[] = $peak;
            $cpu[] = $used / 1000;
        }
        self::verifyOom(supervisor: $supervisor, source: $source, signature: $signature, limits: $limits);
        Qa::ensure(\KnowKit\Tooling\LocalIO::paths($base . '/stages/*') === [] && \KnowKit\Tooling\LocalIO::paths($group . '/knowkit-*') === [], 'OCR stage/cgroup leaked');
        return Report::save('ocr', ['documents' => $documents, 'pagesPerDocument' => count($expected->pages()),
            'latencyMs' => Metrics::percentiles($samples), 'cpuMs' => array_sum($cpu), 'workerPeakBytes' => Metrics::maximum($memory),
            'pagesPerSecond' => $documents * count($expected->pages()) / (array_sum($samples) / 1000),
            'sourceSha256' => hash('sha256', $source), 'irSha256' => hash('sha256', $expected->canonical()),
            'runtimeBomSha256' => hash_file('sha256', $runtime->runtimeRoot . '/bom.json'),
            'parserSignature' => ['name' => $signature->get('name'), 'version' => $signature->get('version'),
                'profileDigest' => $signature->get('profileDigest')],
            'oomOutcome' => 'unavailable/worker_oom', 'providerCalls' => 0,
            'accounting' => 'Kernel cgroup memory.peak and cumulative cpu.stat usage_usec observed before supervisor cleanup. Throughput includes sandbox verification, probe, negotiate and extraction.']);
    }

    private static function sample(
        ProcessWorkerSupervisor $supervisor,
        D\JobEnvelope $job,
        string $source,
        string $group,
        D\IrDocument $expected,
        array &$samples,
    ): array {
        $peak = $used = 0;
        $observe = static function () use ($group, &$peak, &$used): bool {
            foreach (\KnowKit\Tooling\LocalIO::paths($group . '/knowkit-*', GLOB_ONLYDIR) as $child) {
                $peak = max($peak, (int) \KnowKit\Tooling\LocalIO::read($child . '/memory.peak'));
                preg_match('/^usage_usec (\d+)$/m', \KnowKit\Tooling\LocalIO::read($child . '/cpu.stat'), $match);
                $used = max($used, (int) ($match[1] ?? 0));
            }

            return false;
        };
        $bundle = Metrics::timed(operation: fn () => $supervisor->run(job: $job, source: $source, cancel: $observe), samples: $samples);
        Qa::ensure($bundle->ir->canonical() === $expected->canonical(), 'real OCR golden digest changed');
        Qa::ensure($peak > 0 && $peak <= Report::intField(Report::profile()['reference'], 'workerPeakBytes') && $used > 0, 'missing or excessive OCR resource accounting');

        return [$peak, $used];
    }

    private static function verifyOom(ProcessWorkerSupervisor $supervisor, string $source, D\Contract\JsonObject $signature, D\Contract\JsonObject $limits): void
    {
        $oomJob = Fixture::job(source: $source, changes: ['parserProfile' => 'docling-pdf-ocr-eng-v1',
            'parserSignature' => $signature, 'profileFingerprint' => $signature->get('profileDigest'),
            'limits' => $limits->with('memoryBytes', '1')]);
        try {
            $supervisor->run(job: $oomJob, source: $source);
            throw new \RuntimeException('OCR ignored cgroup memory limit');
        } catch (D\DomainFailure $failure) {
            Qa::ensure($failure->outcome === D\Outcome::Unavailable && $failure->errorCode === 'worker_oom', 'OCR OOM did not preserve unavailable');
        }
    }
}
