<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class CapabilityDeclaration
{
    /** @param list<Capability> $requires */
    public function __construct(public Capability $id, public array $requires = [])
    {
        ValueGuard::objects($requires, Capability::class, 64);
        if (count(array_unique(array_map(static fn(Capability $c): string => $c->value, $requires))) !== count($requires)) {
            throw new \InvalidArgumentException('Duplicate capability dependency');
        }
    }
    /** @param list<string> $requires */
    public static function named(string $name, array $requires = []): self
    {
        $id = Capability::tryFrom($name);
        if ($id === null) { throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability'); }
        $dependencies = [];
        foreach ($requires as $name) {
            $dependency = Capability::tryFrom($name);
            if ($dependency === null) { throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability'); }
            $dependencies[] = $dependency;
        }
        return new self($id, $dependencies);
    }
}
