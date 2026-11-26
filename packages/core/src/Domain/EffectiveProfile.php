<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** Strict redacted PHP profile; no secret-bearing config, no implicit binding or mutable config reference. */
final readonly class EffectiveProfile
{
    private FrozenJson $value;

    /**
     * @param array<array-key, mixed> $adapterBindings Native arrays require runtime validation at this boundary.
     * @param array<string, array<string, mixed>> $configuration
     * @param array<string, list<Capability>> $requiredCapabilities
     */
    public function __construct(
        public string $id,
        public string $version,
        public array $adapterBindings,
        array $configuration = [],
        public array $requiredCapabilities = [],
    ) {
        ValueGuard::text($id, 128);
        ValueGuard::text($version, 64);
        $bindings = [];
        foreach ($adapterBindings as $port => $adapter) {
            if (!is_string($port) || !$adapter instanceof AdapterId) {
                throw new \InvalidArgumentException('Explicit adapter bindings required');
            }
            ValueGuard::text($port, 128);
            $caps = $requiredCapabilities[$adapter->value] ?? [];
            ValueGuard::objects($caps, Capability::class, 64);
            $config = self::redact($configuration[$adapter->value] ?? []);
            $bindings[$port] = new JsonObject([
                'adapterId' => $adapter->value,
                'config' => $config,
                'capabilities' => array_map(static fn(Capability $cap): string => $cap->value, $caps),
            ]);
        }
        $ids = array_map(static fn(AdapterId $id): string => $id->value, array_values($adapterBindings));
        if (array_diff(array_keys($configuration), $ids) !== [] || array_diff(array_keys($requiredCapabilities), $ids) !== []) {
            throw new \InvalidArgumentException('Unbound adapter configuration');
        }
        $this->value = new FrozenJson(new JsonObject([
            'id' => $id,
            'version' => $version,
            'adapterBindings' => new JsonObject($bindings),
        ]));
    }

    public function digest(): Fingerprint
    {
        return new Fingerprint(hash('sha256', $this->value->canonical()));
    }

    public function toWire(): JsonObject
    {
        return $this->value->toWire();
    }

    public function config(AdapterId $id): JsonObject
    {
        foreach ($this->value->toWire()->get('adapterBindings') as $binding) {
            if ($binding->get('adapterId') === $id->value) {
                return $binding->get('config');
            }
        }
        throw new DomainFailure(Outcome::Incompatible, 'unknown_adapter');
    }

    /** @param array<array-key, mixed> $values */
    private static function redact(array $values): JsonObject
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Named config required');
            }
            if ($value instanceof CredentialRef) {
                $out[$key] = new JsonObject(['handle' => $value->handle, 'present' => $value->present]);
                continue;
            }
            if (preg_match('/secret|password|credential|api.?key|token/i', $key) === 1) {
                throw new \InvalidArgumentException('CredentialRef required');
            }
            $out[$key] = self::sanitize($value);
        }
        return new JsonObject($out);
    }

    private static function sanitize(mixed $item): mixed
    {
        if (is_array($item)) {
            return array_is_list($item)
                ? array_map(self::sanitize(...), $item)
                : self::redact($item);
        }
        if ($item instanceof JsonObject) {
            return self::redact(iterator_to_array($item));
        }
        if (!is_string($item) && !is_int($item) && !is_bool($item) && $item !== null) {
            throw new \InvalidArgumentException('Strict redacted config required');
        }
        return $item;
    }
}
