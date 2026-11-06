<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * RFC 8785 JSON Canonicalization Scheme for the KnowKit integer-only wire profile.
 * Keys sort by UTF-16 code units; strings are emitted literally except '"', '\', and C0 controls
 * (short escapes or lowercase \u00XX); no Unicode normalization. Floats are rejected
 * (non_integer_number) and integers must stay within +/-(2^53-1); bigints travel as decimal strings.
 * Adapted from the AgenTools CanonicalJson port contract (2054acda51eb69eca1579c78900858c5ff3de01e);
 * core keeps its own encoder because its runtime is vendor-free.
 */
final class Jcs
{
    /** Accepts any PHP value; anything outside the JSON integer profile is rejected with a typed violation. */
    public static function canonicalize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            if ($value > StrictJson::SAFE_INTEGER || $value < -StrictJson::SAFE_INTEGER) {
                throw new ContractViolation('unsafe_integer');
            }
            return (string) $value;
        }
        if (is_float($value)) {
            throw new ContractViolation('non_integer_number');
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof JsonObject) {
            return self::object(iterator_to_array($value->getIterator()));
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::canonicalize(...), $value)) . ']';
            }
            return self::object($value);
        }
        throw new ContractViolation('invalid_json');
    }

    /** Canonicalize JSON text after strict decoding (duplicate names rejected, {} kept distinct from []). */
    public static function canonicalizeJson(string $json, int $maxBytes = StrictJson::DEFAULT_MAX_BYTES): string
    {
        return self::canonicalize(StrictJson::decode($json, $maxBytes));
    }

    /** @param array<array-key, mixed> $members */
    private static function object(array $members): string
    {
        $keys = array_map(static fn(int|string $key): string => (string) $key, array_keys($members));
        usort($keys, static fn(string $left, string $right): int => strcmp(self::utf16($left), self::utf16($right)));
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::string($key) . ':' . self::canonicalize($members[$key]);
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function utf16(string $value): string
    {
        return (string) mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    private static function string(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new ContractViolation('invalid_utf8');
        }
        $short = ['"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\x0c" => '\\f', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];
        return '"' . preg_replace_callback('/[\x00-\x1f"\\\\]/', static fn(array $match): string
            => $short[$match[0]] ?? sprintf('\\u%04x', ord($match[0])), $value) . '"';
    }
}
