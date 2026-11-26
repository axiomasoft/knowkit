<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\Jcs;

/** SQL-only authorization operands discovered before acquiring ordered guards. */
final readonly class DisclosurePlan
{
    private FrozenJson $binding;

    /**
     * @param list<SourceId> $sources
     * @param list<ResourceRef> $resources
     */
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
        $sources = array_map(static fn (SourceId $id): string => $id->value, $this->sources);
        $currentSources = array_map(static fn (SourceId $id): string => $id->value, $current->sources);
        $resources = array_map(static fn (ResourceRef $ref): JsonObject => $ref->toWire(), $this->resources);
        $currentResources = array_map(static fn (ResourceRef $ref): JsonObject => $ref->toWire(), $current->resources);
        if ($sources !== $currentSources
            || Jcs::canonicalize($resources) !== Jcs::canonicalize($currentResources)
            || !$this->binding->equals($current->binding)) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
    }
}
