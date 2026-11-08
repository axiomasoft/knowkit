<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Domain\Scope;

final readonly class InvocationRecovery
{
    public function __construct(
        private InvocationLedger $ledger,
        private InvocationAuthorization $authorization,
    ) {
    }

    public function resume(A\AnswerRequest $original, Scope $current): A\InvocationRecord|A\AnswerOutcome
    {
        $record = $this->ledger->find($original->invocationRef());
        if ($record === null) {
            throw new \LogicException('Existing admission required');
        }
        $record->requireOriginal($original);
        A\InvocationDecision::requireAudience($current, $record->pins->binding->scope);
        if (in_array($record->state, [A\InvocationState::Completed, A\InvocationState::Unknown], true)) {
            return $record;
        }
        if ($record->state === A\InvocationState::Sending) {
            // Closing an uncertain dispatch is technical recovery, never a new access grant.
            return $this->ledger->resume($record->claim);
        }
        $denial = $this->authorization->beforeResume($current, $record);
        if ($denial !== null) {
            A\InvocationDecision::requireMetadata($denial, $record->invocationRef);

            return $denial;
        }

        return $this->ledger->resume($record->claim);
    }
}
