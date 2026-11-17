<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\Tokenizer;

final readonly class AnswerOutcomeMapper
{
    public function __construct(
        private CitationValidator $citations,
        private ?Tokenizer $tokenizer = null,
    ) {
    }

    public function result(A\AnswerRequest $request, A\ResolvedModelBinding $binding, A\GroundingEvidence $evidence,
        A\TransportResult $result, string $usageRef, bool $cancelled): A\AnswerOutcome
    {
        if ($result->certainty === A\DispatchCertainty::Sent
            && (!$result->complete || $result->observedModelRef !== $binding->model)) {
            $result = new A\TransportResult(A\DispatchCertainty::Unknown, null, false, $result->usage, $result->observedModelRef, $result->fixture);
        }
        if ($result->certainty === A\DispatchCertainty::Unknown) {
            return $this->observation($request, $result, $usageRef, A\OutcomeCode::TransportUnknown);
        }
        if ($cancelled) {
            return $this->observation($request, $result, $usageRef, A\OutcomeCode::Cancelled);
        }
        if ($result->certainty === A\DispatchCertainty::KnownNotSent) {
            return new A\AnswerOutcome(A\OutcomeCode::Unavailable, $request->invocationRef(), $result->certainty,
                usageRef: $usageRef, fixture: $result->fixture, component: A\UnavailableComponent::Transport);
        }
        if (!$result->complete || $result->text === null || $result->observedModelRef !== $binding->model) {
            return $this->refused($request, $result, $usageRef, A\RefusalReason::InvalidOutput);
        }

        return $this->content($request, $binding, $evidence, $result, $usageRef);
    }

    public function failure(A\AnswerRequest $request, D\DomainFailure $failure): A\AnswerOutcome
    {
        $code = match ($failure->outcome) {
            D\Outcome::Denied => A\OutcomeCode::Denied,
            D\Outcome::Unknown => A\OutcomeCode::PolicyUnknown,
            default => A\OutcomeCode::Unavailable,
        };

        return new A\AnswerOutcome($code, $request->invocationRef(), A\DispatchCertainty::KnownNotSent,
            reason: $code === A\OutcomeCode::Denied ? $this->denialReason($failure->errorCode) : null,
            component: $code === A\OutcomeCode::Unavailable ? A\UnavailableComponent::RetrievalProfile : null);
    }

    private function denialReason(string $code): A\DenialReason
    {
        return match ($code) {
            'history_attestation_required' => A\DenialReason::HistoryRequired,
            'history_attestation_invalid' => A\DenialReason::HistoryExpired,
            default => A\DenialReason::tryFrom($code) ?? A\DenialReason::AccessDenied,
        };
    }

    private function content(A\AnswerRequest $request, A\ResolvedModelBinding $binding, A\GroundingEvidence $evidence,
        A\TransportResult $result, string $usageRef): A\AnswerOutcome
    {
        $text = $result->text ?? '';
        try {
            A\Guard::utf8($text, $request->limits->outputBytes, false);
            if ($result->usage->outputTokens !== null && $result->usage->outputTokens > $binding->settings->maxOutputTokens) {
                throw new \InvalidArgumentException('Output tokens exceed bound');
            }
            if ($this->tokenizer !== null && $binding->capabilities->tokenizer !== null) {
                $request->limits->assertOutput($text, $this->tokenizer, $binding->capabilities->tokenizer);
            }
            $wire = StrictJson::decode($text, $request->limits->outputBytes);
            if (!$wire instanceof JsonObject || count(iterator_to_array($wire)) !== 2
                || !is_string($wire->get('text')) || !is_array($wire->get('citations'))) {
                throw new \InvalidArgumentException('Exact grounded answer envelope required');
            }
            A\Guard::utf8($wire->get('text'), $request->limits->outputBytes, false);
        } catch (\Throwable) {
            return $this->refused($request, $result, $usageRef, A\RefusalReason::InvalidOutput);
        }
        try {
            $citations = $this->citations->validate($wire->get('citations'), $evidence);
        } catch (\Throwable) {
            return $this->refused($request, $result, $usageRef, A\RefusalReason::InvalidCitation);
        }

        return new A\AnswerOutcome(A\OutcomeCode::Answered, $request->invocationRef(), A\DispatchCertainty::Sent,
            $result->observedModelRef, $usageRef, $result->fixture,
            new A\AnsweredContent($wire->get('text'), $citations, $binding->bindingDigest, $evidence->set->digest()));
    }

    private function refused(A\AnswerRequest $request, A\TransportResult $result, string $usageRef, A\RefusalReason $reason): A\AnswerOutcome
    {
        return new A\AnswerOutcome(A\OutcomeCode::Refused, $request->invocationRef(), $result->certainty,
            $result->observedModelRef, $usageRef, $result->fixture, reason: $reason);
    }

    private function observation(A\AnswerRequest $request, A\TransportResult $result, string $usageRef, A\OutcomeCode $code): A\AnswerOutcome
    {
        return new A\AnswerOutcome($code, $request->invocationRef(), $result->certainty,
            $result->observedModelRef, $usageRef, $result->fixture,
            sent: $code === A\OutcomeCode::Cancelled ? $result->certainty === A\DispatchCertainty::Sent : null);
    }
}
