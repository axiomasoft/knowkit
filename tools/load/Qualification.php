<?php

declare(strict_types=1);

namespace KnowKit\Load;

/** Qualification is fail closed; measurement completion alone never grants a capacity claim. */
final class Qualification
{
    public const CASES = ['catalog', 'limits', 'lexical', 'hybrid', 'disclosure', 'reindex',
        'hot-tenant', 'many-tenants', 'outbox', 'purge', 'ocr'];

    /** Fixed disclosure loop. Warm qualification excludes the first (cold) request; the profile has no separate count. */
    public const DISCLOSURE_REQUESTS = 20;

    public static function failures(array $reports): array
    {
        $profile = Report::profile();
        $reference = is_array($profile['reference'] ?? null) ? $profile['reference'] : [];
        $quotas = is_array($profile['quotas'] ?? null) ? $profile['quotas'] : [];
        $txtBytes = Report::intField($quotas, 'txtDocumentBytes') * Report::intField($quotas, 'txtBatchDocuments');
        $lexicalSamples = Report::intField($reference, 'searchClients') * Report::intField($quotas, 'searchWarmIterationsPerClient');
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
            if (!self::sloMet(case: $case, measurements: $m, reference: $reference, quotas: $quotas, lexicalSamples: $lexicalSamples)) {
                $failures[] = 'slo_failed:' . $case;
            }
            if ($case === 'catalog' && (($m['totalBytes'] ?? 0) !== $txtBytes || ($m['peakBytes'] ?? PHP_INT_MAX) > Report::intField($reference, 'txtIngestPeakBytes'))) {
                $failures[] = 'txt_capacity_failed';
            }
            if ($case === 'lexical' && (($m['sources'] ?? 0) !== Report::intField($reference, 'sources')
                || ($m['chunks'] ?? 0) !== Report::intField($reference, 'chunks')
                || ($m['clients'] ?? 0) !== Report::intField($reference, 'searchClients'))) {
                $failures[] = 'reference_workload_changed';
            }
            if ($case === 'hybrid' && ($m['referenceScaleQualified'] ?? false) !== true) {
                $failures[] = 'hybrid_scale_unqualified';
            }
            if ($case === 'ocr' && (($m['workerPeakBytes'] ?? 0) <= 0 || ($m['workerPeakBytes'] ?? PHP_INT_MAX) > Report::intField($reference, 'workerPeakBytes'))) {
                $failures[] = 'ocr_capacity_failed';
            }
        }

        return $failures;
    }

    private static function sloMet(string $case, array $measurements, array $reference, array $quotas, int $lexicalSamples): bool
    {
        // Disclosure's first of 20 requests is cold and is not a separate profile field.
        $rules = [
            'lexical' => ['warmLatencyMs', $lexicalSamples, Report::intField($reference, 'lexicalWarmP95Ms')],
            'disclosure' => ['warmLatencyMs', self::DISCLOSURE_REQUESTS - 1, Report::intField($reference, 'disclosureWarmP95Ms')],
            'outbox' => ['lagMs', Report::intField($quotas, 'outboxBatch'), Report::intField($reference, 'outboxHealthyP95Ms')],
        ];
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
