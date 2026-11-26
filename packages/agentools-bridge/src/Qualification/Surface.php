<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Qualification;

use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/** Exact reflection comparison; production qualification never supplies a fixture mapping. */
final class Surface
{
    public static function assertMatches(array $definitions, array $classMap = []): void
    {
        foreach ($definitions as $name => $definition) {
            $reflection = new ReflectionClass($classMap[$name] ?? $name);
            $kind = match (true) {
                $reflection->isInterface() => 'interface',
                $reflection->isEnum() => 'enum',
                $reflection->isTrait() => 'trait',
                default => 'class',
            };
            if ($kind !== ($definition['kind'] ?? null)) {
                throw new RuntimeException('Interface kind mismatch: ' . $name);
            }
            if ($reflection->isInterface()
                && array_map(static fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods())
                    !== array_keys($definition['methods'])) {
                throw new RuntimeException('Unexpected interface method: ' . $name);
            }
            foreach ($definition['methods'] ?? [] as $method => $signature) {
                self::assertMethod(
                    method: $reflection->getMethod($method),
                    signature: $signature,
                    classMap: $classMap,
                );
            }
            self::assertProperties(reflection: $reflection, expected: $definition['properties'] ?? null, classMap: $classMap);
            if (isset($definition['cases'])) {
                $cases = array_column($reflection->getConstants(), 'value', 'name');
                if ($cases !== $definition['cases']) {
                    throw new RuntimeException('Boundary stage mismatch');
                }
            }
        }
    }

    private static function assertMethod(ReflectionMethod $method, array $signature, array $classMap): void
    {
        $return = $method->getReturnType();
        if (!$method->isPublic() || $method->isStatic()
            || ($return === null ? '' : self::type((string) $return, $classMap)) !== $signature['returnType']
            || count($method->getParameters()) !== count($signature['parameters'])) {
            throw new RuntimeException('Method signature mismatch: ' . $method->getName());
        }
        foreach ($method->getParameters() as $index => $parameter) {
            $expected = $signature['parameters'][$index];
            $parameterType = $parameter->getType();
            $hasDefault = $parameter->isDefaultValueAvailable();
            if ($parameter->getName() !== $expected['name']
                || ($parameterType === null ? '' : self::type((string) $parameterType, $classMap)) !== $expected['type']
                || $parameter->isOptional() !== $expected['optional']
                || $parameter->isPassedByReference() || $parameter->isVariadic()
                || $hasDefault !== array_key_exists('default', $expected)
                || ($hasDefault && $parameter->getDefaultValue() !== $expected['default'])) {
                throw new RuntimeException('Parameter signature mismatch: ' . $parameter->getName());
            }
        }
    }

    private static function assertProperties(ReflectionClass $reflection, ?array $expected, array $classMap): void
    {
        if ($expected === null) {
            return;
        }
        $actual = [];
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isPublic() || !$property->isReadOnly()) {
                throw new RuntimeException('DTO properties must be public readonly');
            }
            $actual[$property->getName()] = self::type((string) $property->getType(), $classMap);
        }
        if ($actual !== $expected) {
            throw new RuntimeException('DTO property signature mismatch: ' . $reflection->getName());
        }
    }

    private static function type(string $type, array $classMap): string
    {
        $nullable = str_starts_with($type, '?');
        $body = $nullable ? substr($type, 1) : $type;
        $parts = preg_split('/([&|])/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $type;
        }
        $mapped = '';
        foreach ($parts as $part) {
            if ($part === '&' || $part === '|') {
                $mapped .= $part;
                continue;
            }
            $mapped .= self::mappedName($part, $classMap);
        }

        return ($nullable ? '?' : '') . $mapped;
    }

    /** Map a reflected class, including a leading backslash, onto the contract name. */
    private static function mappedName(string $part, array $classMap): string
    {
        $candidates = [$part];
        $stripped = ltrim($part, '\\');
        if ($stripped !== $part) {
            $candidates[] = $stripped;
        }
        foreach ($candidates as $candidate) {
            $name = array_search($candidate, $classMap, true);
            if (is_string($name)) {
                return $name;
            }
        }

        return $part;
    }
}
