<?php

declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\SpaceRef;
use KnowKit\Port\EmbeddingProvider;

/** Explicit immutable host configuration. No global/default model or typo fallback. */
final readonly class EmbeddingProviderRegistry
{
    /** @var array<string, EmbeddingProvider> */
    private array $providers;

    /** @param list<EmbeddingRegistration> $registrations */
    public function __construct(array $registrations)
    {
        $providers = [];
        foreach ($registrations as $registration) {
            $digest = $registration->space->digest()->value;
            if (isset($providers[$digest])) {
                throw new \InvalidArgumentException('Duplicate embedding space registration');
            }
            $providers[$digest] = $registration->provider;
        }
        $this->providers = $providers;
    }

    public function resolve(SpaceRef $space): EmbeddingProvider
    {
        return $this->providers[$space->digest()->value]
            ?? throw new DomainFailure(Outcome::Incompatible, 'space_not_registered');
    }
}
