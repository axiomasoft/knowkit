<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Tooling\Qa;

final class Report
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function profile(): array
    {
        return json_decode(file_get_contents(self::root() . '/deploy/profiles/capacity.v1.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public static function directory(): string
    {
        $directory = self::root() . '/.phpunit.cache/P9.4';
        if (!is_dir($directory)) {
            Qa::ensure(mkdir($directory, 0700, true), 'cannot create private measurement directory');
        }

        return $directory;
    }

    public static function environment(): array
    {
        $cpu = file_get_contents('/proc/cpuinfo');
        preg_match('/^MemTotal:\s+(\d+)/m', file_get_contents('/proc/meminfo'), $memory);
        preg_match('/^model name\s*:\s*(.+)$/m', $cpu, $model);
        $group = '/sys/fs/cgroup' . trim(explode('::', file_get_contents('/proc/self/cgroup'))[1]);

        return ['php' => PHP_VERSION, 'kernel' => php_uname('r'), 'architecture' => php_uname('m'),
            'cpuModel' => $model[1] ?? 'unknown', 'logicalCpus' => preg_match_all('/^processor\s*:/m', $cpu),
            'hostMemoryBytes' => (int) $memory[1] * 1024,
            'processCpuMax' => trim((string) @file_get_contents($group . '/cpu.max')),
            'processMemoryMax' => trim((string) @file_get_contents($group . '/memory.max')),
            'composerLockSha256' => hash_file('sha256', self::root() . '/composer.lock'),
            'monetaryCost' => null, 'costReason' => 'No operator tariff; no price inferred from CPU time.'];
    }

    private static function fingerprints(): array
    {
        $files = ['deploy/profiles/capacity.v1.json', 'tests/ops/performance/fixtures/v1/cases.json',
            'tests/fixtures/check-groups/ops-performance.json'];
        foreach (glob(__DIR__ . '/*.php') as $file) {
            $files[] = substr($file, strlen(self::root()) + 1);
        }
        foreach (get_declared_classes() as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if (is_string($file) && str_starts_with($file, self::root() . '/packages/')) {
                $files[] = substr($file, strlen(self::root()) + 1);
            }
        }
        $manifest = json_decode(file_get_contents(self::root() . '/tests/fixtures/check-groups/ops-performance.json'), true, 64, JSON_THROW_ON_ERROR);
        $files = array_unique([...$files, ...array_column($manifest['fixtures'], 'path')]);
        sort($files, SORT_STRING);
        $fingerprints = [];
        foreach ($files as $file) {
            $fingerprints[$file] = hash_file('sha256', self::root() . '/' . $file);
        }

        return $fingerprints;
    }

    public static function save(string $case, array $measurements): array
    {
        Qa::ensure(preg_match('/^[a-z0-9-]+$/D', $case) === 1, 'invalid report id');
        $env = self::environment();
        $report = ['schemaVersion' => 'capacity-report:1', 'case' => $case,
            'recordedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'environment' => $env,
            'environmentFingerprint' => hash('sha256', json_encode($env, JSON_THROW_ON_ERROR)),
            'fingerprints' => self::fingerprints(), 'measurements' => $measurements,
            'processPeakBytes' => max(memory_get_peak_usage(true), getrusage()['ru_maxrss'] * 1024),
            'accountingLimits' => ['postgresCpuMs' => null, 'postgresPeakBytes' => null,
                'note' => 'PHP process and explicit worker/client counters only. Shared PostgreSQL server resource attribution is not instrumented.'],
            'referenceHardwareQualified' => false,
            'referenceQualificationReason' => 'Current shared development host; no dedicated 4 vCPU/8 GiB/local SSD hardware attestation.',
            'confidence' => 'Observed fixed synthetic workload only; nearest-rank percentiles, no population confidence interval.'];
        file_put_contents(self::directory() . '/' . $case . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

        return $report;
    }
}
