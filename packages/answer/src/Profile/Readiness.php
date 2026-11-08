<?php

declare(strict_types=1);

namespace KnowKit\Answer\Profile;

use KnowKit\Answer\Domain\AnswerOutcome;
use KnowKit\Answer\Domain\DispatchCertainty;
use KnowKit\Answer\Domain\InvocationRef;
use KnowKit\Answer\Domain\OutcomeCode;
use KnowKit\Answer\Domain\UnavailableComponent;

/** Local configuration readiness does not establish remote availability or paid verification. */
final readonly class Readiness
{
    public function __construct(
        public ?UnavailableComponent $unavailable,
    ) {
    }

    public function configured(): bool
    {
        return $this->unavailable === null;
    }

    public function outcome(InvocationRef $invocation): ?AnswerOutcome
    {
        if ($this->unavailable === null) {
            return null;
        }

        return new AnswerOutcome(
            OutcomeCode::Unavailable,
            $invocation,
            DispatchCertainty::KnownNotSent,
            component: $this->unavailable,
        );
    }
}
