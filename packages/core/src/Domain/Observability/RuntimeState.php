<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

use KnowKit\Domain\Coverage;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\ValueGuard;

/** A request-local observation, not an authority/cache for later disclosure. */
final readonly class RuntimeState
{
    /** @param list<AdapterObservation> $adapters */
    public function __construct(
        public Scope $scope,
        public Fingerprint $profile,
        public bool $metadataSchemaReady,
        public bool $tombstonesReplayed,
        public array $adapters,
        public ?Coverage $coverage = null,
        public Outcome $indexOutcome = Outcome::Unknown,
    ) {
        if ($indexOutcome === Outcome::Empty) {
            throw new \InvalidArgumentException('Index readiness needs an observed outcome, not query emptiness');
        }
        ValueGuard::objects(values: $adapters, type: AdapterObservation::class, max: 64);
        $seen = [];
        foreach ($adapters as $adapter) {
            if (isset($seen[$adapter->adapterId->value])) {
                throw new \InvalidArgumentException('Duplicate dependency observation');
            }
            $seen[$adapter->adapterId->value] = true;
        }
    }
}
