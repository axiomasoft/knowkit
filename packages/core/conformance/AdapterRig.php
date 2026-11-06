<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

use KnowKit\Domain\{DomainFailure, Outcome};

/** Controls change fixture environment (time, policy, lifecycle); observations read actual effects. */
final readonly class AdapterRig
{
    /** @param array<string, object> $adapters */
    public function __construct(private array $adapters, private \Closure $control, private \Closure $observe) {}

    /** @template T of object @param class-string<T> $type @return T */
    public function adapter(string $name, string $type): object
    {
        $adapter = $this->adapters[$name] ?? null;
        if (!$adapter instanceof $type) { throw new DomainFailure(Outcome::Unavailable, 'contract_adapter_missing'); }
        return $adapter;
    }
    public function control(string $name, mixed $value = null): void { ($this->control)($name, $value); }
    public function observe(string $name): mixed { return ($this->observe)($name); }
}
