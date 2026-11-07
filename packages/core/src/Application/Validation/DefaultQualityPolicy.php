<?php
declare(strict_types=1);

namespace KnowKit\Application\Validation;

use KnowKit\Domain\{Coverage, CoveragePolicy, Decision, QualityDecision};
use KnowKit\Port\QualityPolicy;

/** Technical validity does not establish OCR correctness or publication permission. */
final class DefaultQualityPolicy implements QualityPolicy
{
    public function evaluate(Coverage $coverage, CoveragePolicy $policy): QualityDecision
    {
        $wire = $coverage->toWire();
        $codes = array_map(static fn($d): string => $d->get('code'), $wire->get('diagnostics'));
        $missing = array_intersect($policy->requiredFeatures, $wire->get('missingFeatures'));
        if ($missing !== []) { $codes[] = 'required_feature_missing'; }
        $review = in_array('manual_review_needed', $codes, true);
        if ($review || $missing !== []) { $decision = Decision::Deny; }
        elseif ($policy->allowPartial) { $decision = Decision::Allow; }
        elseif ($wire->get('state') === 'unknown' || !$wire->get('counts')->has('expected')) {
            $decision = Decision::Unknown; $codes[] = 'coverage_unknown';
        } elseif ($wire->get('state') !== 'complete' || in_array('ocr_low_confidence', $codes, true)) {
            $decision = Decision::Deny; $codes[] = 'incomplete_coverage';
        } else { $decision = Decision::Allow; }
        $codes = array_values(array_unique($codes)); sort($codes, SORT_STRING);
        return new QualityDecision($decision, $coverage, $codes);
    }
}
