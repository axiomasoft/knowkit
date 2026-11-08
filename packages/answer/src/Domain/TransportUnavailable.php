<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

/** Only a confirmed local configuration failure BEFORE dispatch may use this exception. */
final class TransportUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly UnavailableComponent $component,
    ) {
        parent::__construct('model_configuration_missing');
    }

    public function outcome(InvocationRef $ref): AnswerOutcome
    {
        return new AnswerOutcome(OutcomeCode::Unavailable, $ref, DispatchCertainty::KnownNotSent, component: $this->component);
    }
}
