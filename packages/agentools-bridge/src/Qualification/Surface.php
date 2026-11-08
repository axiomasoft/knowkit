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
            if ($reflection->isInterface() !== ($definition['kind'] === 'interface')
                || $reflection->isEnum() !== ($definition['kind'] === 'enum')) {
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
        if (!$method->isPublic() || $method->isStatic()
            || self::type((string) $method->getReturnType(), $classMap) !== $signature['returnType']
            || count($method->getParameters()) !== count($signature['parameters'])) {
            throw new RuntimeException('Method signature mismatch: ' . $method->getName());
        }
        foreach ($method->getParameters() as $index => $parameter) {
            $expected = $signature['parameters'][$index];
            if ($parameter->getName() !== $expected['name']
                || self::type((string) $parameter->getType(), $classMap) !== $expected['type']
                || $parameter->isOptional() !== $expected['optional']
                || $parameter->isPassedByReference() || $parameter->isVariadic()
                || ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() !== $expected['default'])) {
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
        $name = array_search($type, $classMap, true);

        return is_string($name) ? $name : $type;
    }
}
