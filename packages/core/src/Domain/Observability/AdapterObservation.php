<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

use KnowKit\Domain\AdapterId;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Outcome;

/** Host observation for this exact profile; credentials/configuration alone never verify an adapter. */
final readonly class AdapterObservation
{
    public function __construct(
        public AdapterId $adapterId,
        public bool $required,
        public bool $enabled,
        public Outcome $outcome = Outcome::Unknown,
        public ?Fingerprint $verifiedProfile = null,
    ) {
        if ($outcome === Outcome::Empty) {
            throw new \InvalidArgumentException('Empty is not a dependency observation');
        }
    }
}
