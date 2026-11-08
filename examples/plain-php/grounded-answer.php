<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\GroundedAnswering;
use KnowKit\Domain\Scope;

/** Caller-side composition: the host hydrates prior turns and attests their CURRENT visibility.
 * KnowKit receives one bounded invocation and does not own or append a conversation transcript.
 */
final readonly class GroundedAnswerCaller
{
    public function __construct(
        private GroundedAnswering $answering,
    ) {
    }

    /** @param list<A\PriorTurn> $callerTurns */
    public function answer(
        Scope $currentScope,
        string $question,
        array $callerTurns,
        ?A\HistoryReadAttestation $currentHistory,
        A\ModelBindingRef $tutorModel,
        A\ProfileRef $retrievalProfile,
        A\InvocationKey $hostInvocationKey,
        A\CancellationToken $cancellation,
    ): A\AnswerOutcome {
        // Select history explicitly before admission. Never omit it as an invisible retry fallback.
        $request = new A\AnswerRequest($currentScope, $question, new A\BoundedTurns($callerTurns, $currentHistory),
            $tutorModel, $retrievalProfile, $hostInvocationKey, new A\AnswerLimits());

        // The caller separately decides whether/how to publish this outcome or append it to its chat.
        return $this->answering->answer($request, $cancellation);
    }
}
