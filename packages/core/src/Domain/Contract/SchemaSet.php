<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Offline evaluator for the bounded JSON Schema 2020-12 subset used by bundled KnowKit schemas
 * (urn:knowkit:schema:<name>:1.0, local refs only, no network). Supported keywords and the
 * x-knowkit-* extensions are listed in specs/contracts/wire-contract.md; any other keyword is a
 * schema definition error. Error reporting is deterministic: all errors, deduplicated, sorted by
 * (instance path, code) bytes, so PHP and Python report the same list.
 */
final class SchemaSet
{
    public const COMMON_ID = 'urn:knowkit:schema:common:1.0';
    public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';
    private const KEYWORDS = [
        '$schema', '$id', '$defs', '$ref', '$comment', 'title', 'description', 'type', 'const', 'enum',
        'properties', 'required', 'unevaluatedProperties', 'additionalProperties', 'propertyNames',
        'maxProperties', 'minProperties', 'items', 'minItems', 'maxItems', 'uniqueItems', 'contains',
        'minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'allOf', 'if', 'then', 'else',
        'x-knowkit-decimalMax', 'x-knowkit-lessThan', 'x-knowkit-uniqueBy', 'x-knowkit-sortedBy',
        'x-knowkit-maxDocumentBytes', 'x-knowkit-schemaVersion',
    ];

    /** @param array<string, mixed> $schemas decoded schema documents keyed by $id (validated here) */
    public function __construct(private readonly array $schemas)
    {
        foreach ($schemas as $id => $schema) {
            if (!$schema instanceof JsonObject || $schema->get('$id') !== $id || $schema->get('$schema') !== self::DIALECT) {
                throw new \LogicException('schema $id/dialect mismatch: ' . $id);
            }
            $this->lint($schema, $id);
        }
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = array_keys($this->schemas);
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function has(string $id): bool
    {
        return $id !== self::COMMON_ID && isset($this->schemas[$id]);
    }

    public function root(string $id): JsonObject
    {
        if (!$this->has($id)) {
            throw new ContractViolation('unknown_schema');
        }
        return $this->schemas[$id];
    }

    /** @return list<array{code: string, path: string}> */
    public function validate(mixed $instance, string $schemaId): array
    {
        $errors = [];
        $this->evaluate($this->root($schemaId), $instance, '', $schemaId, $errors);
        return self::sortErrors($errors);
    }

    /**
     * @param list<array{code: string, path: string}> $errors
     * @return list<array{code: string, path: string}>
     */
    public static function sortErrors(array $errors): array
    {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error['path'] . "\x00" . $error['code']] = $error;
        }
        $sorted = array_values($unique);
        usort($sorted, static fn(array $left, array $right): int
            => strcmp($left['path'], $right['path']) ?: strcmp($left['code'], $right['code']));
        return $sorted;
    }

    public static function pointer(string $token): string
    {
        return '/' . str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    public static function jsonEqual(mixed $left, mixed $right): bool
    {
        if ($left instanceof JsonObject && $right instanceof JsonObject) {
            $keys = $left->keys();
            $other = $right->keys();
            sort($keys, SORT_STRING);
            sort($other, SORT_STRING);
            if ($keys !== $other) {
                return false;
            }
            foreach ($keys as $key) {
                if (!self::jsonEqual($left->get($key), $right->get($key))) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $index => $item) {
                if (!self::jsonEqual($item, $right[$index])) {
                    return false;
                }
            }
            return true;
        }
        if ($left instanceof JsonObject || $right instanceof JsonObject || is_array($left) || is_array($right)) {
            return false;
        }
        return $left === $right;
    }

    private function lint(mixed $schema, string $base): void
    {
        if (is_bool($schema)) {
            return;
        }
        if (!$schema instanceof JsonObject) {
            throw new \LogicException('schema must be object/boolean');
        }
        $unknown = array_diff($schema->keys(), self::KEYWORDS);
        if ($unknown !== []) {
            throw new \LogicException('unsupported schema keyword: ' . implode(',', $unknown));
        }
        if ($schema->has('unevaluatedProperties') && $schema->get('unevaluatedProperties') !== false) {
            throw new \LogicException('unevaluatedProperties must be false');
        }
        if ($schema->has('pattern')) {
            $pattern = $schema->get('pattern');
            if (!is_string($pattern) || !str_starts_with($pattern, '^') || !str_ends_with($pattern, '$') || str_contains($pattern, '#')) {
                throw new \LogicException('pattern must be anchored and #-free');
            }
        }
        if ($schema->has('$ref')) {
            $this->resolve((string) $schema->get('$ref'), $base);
        }
        foreach (['properties', '$defs'] as $keyword) {
            if ($schema->get($keyword) instanceof JsonObject) {
                foreach ($schema->get($keyword) as $child) {
                    $this->lint($child, $base);
                }
            }
        }
        foreach (['additionalProperties', 'propertyNames', 'items', 'contains', 'if', 'then', 'else'] as $keyword) {
            if ($schema->has($keyword)) {
                $this->lint($schema->get($keyword), $base);
            }
        }
        foreach ((array) $schema->get('allOf') as $child) {
            $this->lint($child, $base);
        }
    }

    /** @return array{0: mixed, 1: string} */
    private function resolve(string $ref, string $base): array
    {
        $hash = strpos($ref, '#');
        $document = $hash === false ? $ref : substr($ref, 0, $hash);
        $fragment = $hash === false ? '' : substr($ref, $hash + 1);
        $document = $document === '' ? $base : $document;
        if (!isset($this->schemas[$document])) {
            throw new \LogicException('unresolved $ref: ' . $ref);
        }
        $node = $this->schemas[$document];
        if ($fragment !== '') {
            foreach (explode('/', ltrim($fragment, '/')) as $token) {
                $token = str_replace(['~1', '~0'], ['/', '~'], $token);
                if (!$node instanceof JsonObject || !$node->has($token)) {
                    throw new \LogicException('unresolved $ref: ' . $ref);
                }
                $node = $node->get($token);
            }
        }
        return [$node, $document];
    }

    private static function typeOk(mixed $expected, mixed $value): bool
    {
        foreach (is_array($expected) ? $expected : [$expected] as $name) {
            $ok = match ($name) {
                'integer', 'number' => is_int($value),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value),
                'object' => $value instanceof JsonObject,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{code: string, path: string}> $errors
     * @return array<string, true> evaluated member names
     */
    private function evaluate(mixed $schema, mixed $value, string $path, string $base, array &$errors): array
    {
        if ($schema === true) {
            return [];
        }
        if ($schema === false) {
            $errors[] = ['code' => 'forbidden_field', 'path' => $path];
            return [];
        }
        /** @var JsonObject $schema */
        if ($schema->has('type') && !self::typeOk($schema->get('type'), $value)) {
            $errors[] = ['code' => 'type', 'path' => $path];
            return [];
        }
        $evaluated = [];
        if ($schema->has('$ref')) {
            [$target, $targetBase] = $this->resolve((string) $schema->get('$ref'), $base);
            $evaluated += $this->evaluate($target, $value, $path, $targetBase, $errors);
        }
        if ($schema->has('const') && !self::jsonEqual($schema->get('const'), $value)) {
            $errors[] = ['code' => 'const', 'path' => $path];
        }
        if ($schema->has('enum')) {
            $found = false;
            foreach ($schema->get('enum') as $option) {
                $found = $found || self::jsonEqual($option, $value);
            }
            if (!$found) {
                $errors[] = ['code' => 'enum', 'path' => $path];
            }
        }
        if ($value instanceof JsonObject) {
            $evaluated += $this->object($schema, $value, $path, $base, $errors);
        } elseif (is_array($value)) {
            $this->array($schema, $value, $path, $base, $errors);
        } elseif (is_string($value)) {
            $this->string($schema, $value, $path, $errors);
        } elseif (is_int($value)) {
            if ($schema->has('minimum') && $value < $schema->get('minimum')) {
                $errors[] = ['code' => 'minimum', 'path' => $path];
            }
            if ($schema->has('maximum') && $value > $schema->get('maximum')) {
                $errors[] = ['code' => 'maximum', 'path' => $path];
            }
        }
        foreach ((array) $schema->get('allOf') as $child) {
            $evaluated += $this->evaluate($child, $value, $path, $base, $errors);
        }
        if ($schema->has('if')) {
            $probe = [];
            $matched = $this->evaluate($schema->get('if'), $value, $path, $base, $probe);
            if ($probe === []) {
                $evaluated += $matched;
                if ($schema->has('then')) {
                    $evaluated += $this->evaluate($schema->get('then'), $value, $path, $base, $errors);
                }
            } elseif ($schema->has('else')) {
                $evaluated += $this->evaluate($schema->get('else'), $value, $path, $base, $errors);
            }
        }
        if ($value instanceof JsonObject && $schema->get('unevaluatedProperties') === false) {
            foreach ($value->keys() as $key) {
                if (!isset($evaluated[$key])) {
                    $errors[] = ['code' => 'unknown_field', 'path' => $path . self::pointer($key)];
                }
                $evaluated[$key] = true;
            }
        }
        return $evaluated;
    }

    /**
     * @param list<array{code: string, path: string}> $errors
     * @return array<string, true>
     */
    private function object(JsonObject $schema, JsonObject $value, string $path, string $base, array &$errors): array
    {
        $evaluated = [];
        foreach ((array) $schema->get('required') as $name) {
            if (!$value->has($name)) {
                $errors[] = ['code' => 'required', 'path' => $path . self::pointer($name)];
            }
        }
        $properties = $schema->get('properties');
        $properties = $properties instanceof JsonObject ? $properties : new JsonObject();
        foreach ($properties as $name => $child) {
            if ($value->has($name)) {
                $evaluated[$name] = true;
                $this->evaluate($child, $value->get($name), $path . self::pointer($name), $base, $errors);
            }
        }
        if ($schema->has('additionalProperties')) {
            foreach ($value as $key => $child) {
                if (!$properties->has($key)) {
                    $evaluated[$key] = true;
                    $this->evaluate($schema->get('additionalProperties'), $child, $path . self::pointer($key), $base, $errors);
                }
            }
        }
        if ($schema->has('propertyNames')) {
            foreach ($value->keys() as $key) {
                $probe = [];
                $this->evaluate($schema->get('propertyNames'), $key, $path . self::pointer($key), $base, $probe);
                if ($probe !== []) {
                    $errors[] = ['code' => 'property_name', 'path' => $path . self::pointer($key)];
                }
            }
        }
        if ($schema->has('minProperties') && count($value) < $schema->get('minProperties')) {
            $errors[] = ['code' => 'min_properties', 'path' => $path];
        }
        if ($schema->has('maxProperties') && count($value) > $schema->get('maxProperties')) {
            $errors[] = ['code' => 'max_properties', 'path' => $path];
        }
        $rule = $schema->get('x-knowkit-lessThan');
        if ($rule instanceof JsonObject) {
            foreach ($rule->get('pairs') as [$left, $right]) {
                $a = $value->get($left);
                $b = $value->get($right);
                if (is_int($a) && is_int($b) && !($rule->get('orEqual') === true ? $a <= $b : $a < $b)) {
                    $errors[] = ['code' => (string) $rule->get('code'), 'path' => $path . self::pointer($right)];
                }
            }
        }
        return $evaluated;
    }

    /**
     * @param list<mixed> $value
     * @param list<array{code: string, path: string}> $errors
     */
    private function array(JsonObject $schema, array $value, string $path, string $base, array &$errors): void
    {
        if ($schema->has('items')) {
            foreach ($value as $index => $item) {
                $this->evaluate($schema->get('items'), $item, $path . '/' . $index, $base, $errors);
            }
        }
        if ($schema->has('minItems') && count($value) < $schema->get('minItems')) {
            $errors[] = ['code' => 'min_items', 'path' => $path];
        }
        if ($schema->has('maxItems') && count($value) > $schema->get('maxItems')) {
            $errors[] = ['code' => 'max_items', 'path' => $path];
        }
        if ($schema->get('uniqueItems') === true) {
            foreach ($value as $index => $item) {
                for ($other = 0; $other < $index; $other++) {
                    if (self::jsonEqual($item, $value[$other])) {
                        $errors[] = ['code' => 'unique_items', 'path' => $path . '/' . $index];
                        break;
                    }
                }
            }
        }
        if ($schema->has('contains')) {
            $found = false;
            foreach ($value as $index => $item) {
                $probe = [];
                $this->evaluate($schema->get('contains'), $item, $path . '/' . $index, $base, $probe);
                if ($probe === []) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $errors[] = ['code' => 'contains', 'path' => $path];
            }
        }
        $key = $schema->get('x-knowkit-uniqueBy');
        if (is_string($key)) {
            $seen = [];
            foreach ($value as $index => $item) {
                if ($item instanceof JsonObject && is_string($item->get($key))) {
                    if (isset($seen[$item->get($key)])) {
                        $errors[] = ['code' => 'duplicate_identifier', 'path' => $path . '/' . $index . self::pointer($key)];
                    }
                    $seen[$item->get($key)] = true;
                }
            }
        }
        $keys = $schema->get('x-knowkit-sortedBy');
        if (is_array($keys)) {
            $previous = null;
            foreach ($value as $index => $item) {
                if (!$item instanceof JsonObject) {
                    continue;
                }
                $current = [];
                foreach ($keys as $field) {
                    if (!is_string($item->get($field))) {
                        continue 2;
                    }
                    $current[] = $item->get($field);
                }
                if ($previous !== null && self::compareTuples($previous, $current) >= 0) {
                    $errors[] = ['code' => 'unsorted_members', 'path' => $path . '/' . $index];
                }
                $previous = $current;
            }
        }
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private static function compareTuples(array $left, array $right): int
    {
        foreach ($left as $index => $item) {
            $order = strcmp($item, $right[$index]);
            if ($order !== 0) {
                return $order;
            }
        }
        return 0;
    }

    /** @param list<array{code: string, path: string}> $errors */
    private function string(JsonObject $schema, string $value, string $path, array &$errors): void
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($schema->has('minLength') && $length < $schema->get('minLength')) {
            $errors[] = ['code' => 'min_length', 'path' => $path];
        }
        if ($schema->has('maxLength') && $length > $schema->get('maxLength')) {
            $errors[] = ['code' => 'max_length', 'path' => $path];
        }
        if ($schema->has('pattern') && preg_match('#' . $schema->get('pattern') . '#Du', $value) !== 1) {
            $errors[] = ['code' => 'pattern', 'path' => $path];
        }
        $maximum = $schema->get('x-knowkit-decimalMax');
        if (is_string($maximum) && preg_match('/^[0-9]+$/D', $value) === 1) {
            if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
                $errors[] = ['code' => 'decimal_out_of_range', 'path' => $path];
            }
        }
    }
}
