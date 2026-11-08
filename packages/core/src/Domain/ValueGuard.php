<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/**
 * Validation adapted from AgenTools Integrity/Wire, (c) 2026 Dmitry Vostrikov,
 * under private local reuse authority. Modified for bounded typed KnowKit values.
 * Exact pins, notices and qualification: tests/contract/ports/evidence/P1.5-reuse.json.
 */
final class ValueGuard
{
    public static function text(string $value, int $max = 256): void
    {
        if (trim($value) === '' || preg_match('//u', $value) !== 1 || mb_strlen($value, 'UTF-8') > $max) {
            throw new \InvalidArgumentException('Bounded nonempty UTF8 required');
        }
    }
    /**
     * @param array<array-key, mixed> $values
     * @param class-string $type
     */
    public static function objects(array $values, string $type, int $max = 4096): void
    {
        if (!array_is_list($values) || count($values) > $max) { throw new \InvalidArgumentException('Bounded list required'); }
        foreach ($values as $value) {
            if (!$value instanceof $type) { throw new \InvalidArgumentException('Typed list required'); }
        }
    }
    /** @param list<Ulid> $ids */
    public static function uniqueIds(array $ids, string $type): void
    {
        self::objects($ids, $type);
        $values = array_map(static fn(Ulid $id): string => $id->value, $ids);
        if (count(array_unique($values)) !== count($values)) { throw new \InvalidArgumentException('Duplicate identifier'); }
    }

    public static function semanticOptions(\KnowKit\Domain\Contract\JsonObject $options): void
    {
        foreach ($options as $key => $value) {
            if (preg_match('/secret|credential|password|api.?key|token|path|signed.?url|temporary.?url/i', $key) === 1) {
                throw new \InvalidArgumentException('Only semantic profile settings may be fingerprinted');
            }
            if ($value instanceof \KnowKit\Domain\Contract\JsonObject) { self::semanticOptions($value); }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \KnowKit\Domain\Contract\JsonObject) { self::semanticOptions($item); }
                }
            }
        }
    }

    /** @param array<array-key, mixed> $values */
    public static function strings(array $values, bool $unique = false, bool $codes = false): void
    {
        if (!array_is_list($values) || ($unique && count(array_unique($values, SORT_REGULAR)) !== count($values))) {
            throw new \InvalidArgumentException('Unique string list required');
        }
        foreach ($values as $value) {
            if (!is_string($value) || ($codes && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1)) {
                throw new \InvalidArgumentException('Safe string required');
            }
            self::text($value, $codes ? 64 : 128);
        }
    }

    /** @param array<array-key, mixed> $values */
    public static function vector(array $values, EmbeddingSpace $space): void
    {
        if (!array_is_list($values) || count($values) !== $space->dimensions) {
            throw new DomainFailure(Outcome::Incompatible, 'space_mismatch');
        }
        $norm = 0.0;
        foreach ($values as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
            }
            // hypot avoids overflow for otherwise finite provider coordinates.
            $norm = hypot($norm, (float) $value);
        }
        if ($space->normalization === 'l2' && (!is_finite($norm) || abs($norm - 1.0) > 1.0e-6)) {
            throw new DomainFailure(Outcome::Incompatible, 'space_mismatch');
        }
    }
}
