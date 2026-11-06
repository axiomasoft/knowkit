<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{ContractValidator, ContractViolation, JsonObject};

/** Immutable common coverage value; document-relative status totals are also checked by the P1.4 IR validator. */
final readonly class Coverage
{
    private FrozenJson $value;
    public function __construct(JsonObject $value)
    {
        $state = $value->get('state'); $counts = $value->get('counts');
        if (!in_array($state, ['complete', 'partial', 'unknown'], true) || !$counts instanceof JsonObject
            || array_diff($value->keys(), ['state','counts','missingFeatures','diagnostics']) !== []) {
            throw new \InvalidArgumentException('Coverage required');
        }
        foreach (['processed','empty','failed','skipped'] as $key) {
            $n = $counts->get($key);
            if (!is_int($n) || $n < 0 || $n > 4294967295) { throw new \InvalidArgumentException('Coverage count required'); }
        }
        if (array_diff($counts->keys(), ['expected','processed','empty','failed','skipped']) !== []) {
            throw new \InvalidArgumentException('Unknown coverage count');
        }
        if ($counts->has('expected') && (!is_int($counts->get('expected')) || $counts->get('expected') < 0
            || $counts->get('expected') > 4294967295)) { throw new \InvalidArgumentException('Expected count required'); }
        foreach (['missingFeatures','diagnostics'] as $key) {
            if (!is_array($value->get($key)) || !array_is_list($value->get($key))) { throw new \InvalidArgumentException('Coverage list required'); }
        }
        if (count($value->get('missingFeatures')) > 64 || count($value->get('diagnostics')) > 1024) {
            throw new \InvalidArgumentException('Bounded coverage required');
        }
        ValueGuard::strings($value->get('missingFeatures'), true, true);
        $hasError = false;
        foreach ($value->get('diagnostics') as $diagnostic) {
            if (!$diagnostic instanceof JsonObject || !in_array($diagnostic->get('severity'), ['info','warning','error'], true)
                || !is_string($diagnostic->get('code')) || array_diff($diagnostic->keys(), ['segmentId','code','severity']) !== []) {
                throw new \InvalidArgumentException('Safe diagnostic required');
            }
            ValueGuard::strings([$diagnostic->get('code')], false, true);
            $hasError = $hasError || $diagnostic->get('severity') === 'error';
            if ($diagnostic->has('segmentId')) { new LocalId($diagnostic->get('segmentId')); }
        }
        $sum = $counts->get('processed') + $counts->get('failed') + $counts->get('skipped');
        if ($counts->get('empty') > $counts->get('processed')
            || ($counts->has('expected') && $sum !== $counts->get('expected'))
            || ($state === 'complete' && ($counts->get('failed') !== 0 || $counts->get('skipped') !== 0
                || $value->get('missingFeatures') !== [] || $hasError))) {
            throw new \InvalidArgumentException('Coverage status mismatch');
        }
        $this->value = new FrozenJson($value);
    }
    public function toWire(): JsonObject { return $this->value->toWire(); }
}
