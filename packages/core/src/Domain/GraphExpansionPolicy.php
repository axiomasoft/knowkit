<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Hard-capped graph expansion policy; host may tighten limits but never exceed caps. */
final readonly class GraphExpansionPolicy
{
    public const MAX_HOPS_CAP = 2;
    public const MAX_EXPANDED_NODES_CAP = 32;

    /**
     * @param list<string> $allowedRelations
     * @param list<LocalId> $effectiveDenySet
     */
    public function __construct(
        public int $maxHops = self::MAX_HOPS_CAP,
        public int $maxExpandedNodes = self::MAX_EXPANDED_NODES_CAP,
        public array $allowedRelations = ConceptEdge::RELATIONS,
        public array $effectiveDenySet = [],
    ) {
        if ($maxHops < 0 || $maxHops > self::MAX_HOPS_CAP) {
            throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
        }
        if ($maxExpandedNodes < 0 || $maxExpandedNodes > self::MAX_EXPANDED_NODES_CAP) {
            throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
        }
        ValueGuard::strings($allowedRelations, unique: true, codes: true);
        foreach ($allowedRelations as $relation) {
            if (!in_array($relation, ConceptEdge::RELATIONS, true)) {
                throw new DomainFailure(Outcome::Incompatible, 'profile_invalid');
            }
        }
        ValueGuard::objects($effectiveDenySet, LocalId::class, 1024);
    }
}
