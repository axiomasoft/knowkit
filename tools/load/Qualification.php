<?php

declare(strict_types=1);

namespace KnowKit\Load;

/** Qualification is fail closed; measurement completion alone never grants a capacity claim. */
final class Qualification
{
    public const CASES = ['catalog', 'limits', 'lexical', 'hybrid', 'disclosure', 'reindex',
        'hot-tenant', 'many-tenants', 'outbox', 'purge', 'ocr'];

    public static function failures(array $reports): array
    {
        $failures = [];
        foreach (self::CASES as $case) {
            $report = $reports[$case] ?? null;
            if (!is_array($report) || ($report['schemaVersion'] ?? '') !== 'capacity-report:1' || ($report['case'] ?? '') !== $case) {
                $failures[] = 'missing_or_invalid:' . $case;
                continue;
            }
            if (($report['referenceHardwareQualified'] ?? false) !== true) {
                $failures[] = 'reference_hardware_unqualified:' . $case;
            }
            $m = $report['measurements'] ?? [];
            if (!self::sloMet(case: $case, measurements: $m)) {
                $failures[] = 'slo_failed:' . $case;
            }
            if ($case === 'catalog' && (($m['totalBytes'] ?? 0) !== 67108864 || ($m['peakBytes'] ?? PHP_INT_MAX) > 268435456)) {
                $failures[] = 'txt_capacity_failed';
            }
            if ($case === 'lexical' && (($m['sources'] ?? 0) !== 10000 || ($m['chunks'] ?? 0) !== 100000 || ($m['clients'] ?? 0) !== 20)) {
                $failures[] = 'reference_workload_changed';
            }
            if ($case === 'hybrid' && ($m['referenceScaleQualified'] ?? false) !== true) {
                $failures[] = 'hybrid_scale_unqualified';
            }
            if ($case === 'ocr' && (($m['workerPeakBytes'] ?? 0) <= 0 || ($m['workerPeakBytes'] ?? PHP_INT_MAX) > 2147483648)) {
                $failures[] = 'ocr_capacity_failed';
            }
        }

        return $failures;
    }

    private static function sloMet(string $case, array $measurements): bool
    {
        $rules = ['lexical' => ['warmLatencyMs', 100, 500], 'disclosure' => ['warmLatencyMs', 19, 200], 'outbox' => ['lagMs', 64, 5000]];
        if (!isset($rules[$case])) {
            return true;
        }
        [$name, $count, $ceiling] = $rules[$case];
        $stats = $measurements[$name] ?? [];
        $p95 = $stats['p95'] ?? null;

        return ($measurements['targetMet'] ?? false) === true && ($stats['count'] ?? 0) === $count
            && (is_int($p95) || is_float($p95)) && is_finite((float) $p95) && $p95 >= 0 && $p95 <= $ceiling;
    }
}
