<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\Jcs;

/** SQL-only authorization operands discovered before acquiring ordered guards. */
final readonly class DisclosurePlan
{
    private FrozenJson $binding;

    /** @param list<SourceId> $sources @param list<ResourceRef> $resources */
    public function __construct(
        public array $sources,
        public array $resources,
        JsonObject $binding,
    ) {
        ValueGuard::objects($sources, SourceId::class);
        ValueGuard::objects($resources, ResourceRef::class);
        $this->binding = new FrozenJson($binding);
    }

    public function binding(): JsonObject
    {
        return $this->binding->toWire();
    }

    public function requireSame(self $current): void
    {
        if ($this->sources != $current->sources || $this->resources != $current->resources
            || $this->binding->canonical() !== Jcs::canonicalize($current->binding())) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
    }
}
