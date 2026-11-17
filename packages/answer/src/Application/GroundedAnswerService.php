<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use DateTimeImmutable;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\GroundedAnswering;
use KnowKit\Answer\Port\AnswerBudget;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Answer\Port\ModelTransport;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Domain as D;
use KnowKit\Port\Clock;

/** One admitted bounded invocation; existing ledger owns all durable state and fencing. */
final readonly class GroundedAnswerService implements GroundedAnswering
{
    public function __construct(
        private InvocationAdmission $admission,
        private InvocationLedger $ledger,
        private InvocationReplay $replay,
        private GroundingEvidenceReader $evidence,
        private GroundingPromptComposer $composer,
        private InvocationAuthorization $authorization,
        private CurrentModelEgress $egress,
        private AnswerBudget $budget,
        private ModelTransport $transport,
        private AnswerOutcomeMapper $mapper,
        private AnswerSettlement $settlement,
        private Clock $clock,
        private ProfileMode $mode = ProfileMode::Production,
    ) {
        new EffectiveProfile($mode, $transport->descriptor());
    }

    public function answer(A\AnswerRequest $request, A\CancellationToken $cancellation): A\AnswerOutcome
    {
        $deadline = $this->now()->modify("+{$request->limits->deadlineSeconds} seconds");
        // Admission conflicts deliberately remain typed conflicts, never cached answers.
        $record = $this->admission->admit($request);
        if ($record->state !== A\InvocationState::Preparing) {
            $outcome = $this->replay->replay($request, $request->scope);
            $this->settlement->settle($request->scope, $outcome);

            return $outcome;
        }
        $prepared = $this->prepare($record, $request, $deadline);
        if ($prepared instanceof A\AnswerOutcome) {
            return $prepared;
        }

        return $this->reserve($record, $request, $prepared->prompt, $prepared->evidence, $prepared->request, $cancellation);
    }

    private function prepare(A\InvocationRecord $record, A\AnswerRequest $request, DateTimeImmutable $deadline): GroundingPreparation|A\AnswerOutcome
    {
        try {
            $evidence = $this->evidence->read($request);
            if ($evidence === null) {
                return $this->complete($record, $this->notSent($request, A\OutcomeCode::NoEvidence));
            }
            $prompt = $this->composer->compose($request, $record->pins->binding, $evidence);
            $outbound = $this->outbound($request, $prompt, $evidence, $deadline);
            $decision = $this->guard($request, $outbound, $prompt, $evidence);
            if ($decision instanceof A\AnswerOutcome) {
                return $this->complete($record, $decision);
            }
            $receipt = $this->settlement->egressDecision($outbound, $prompt->manifest($outbound), $decision);
            $outbound = $prompt->request($request->invocationRef(), $receipt, $deadline);
            // Bind the final identity to an actual check of the same exact bytes/destination.
            $decision = $this->guard($request, $outbound, $prompt, $evidence);
            if ($decision instanceof A\AnswerOutcome) {
                return $this->complete($record, $decision);
            }
        } catch (D\DomainFailure $failure) {
            if ($failure->outcome === D\Outcome::Incompatible) {
                $this->complete($record, $this->mapper->failure($request, $failure));
                throw $failure;
            }

            return $this->complete($record, $this->mapper->failure($request, $failure));
        } catch (\Throwable) {
            return $this->complete($record, $this->unavailable($request, A\UnavailableComponent::RetrievalProfile));
        }

        return new GroundingPreparation($prompt, $evidence, $outbound);
    }

    private function reserve(A\InvocationRecord $record, A\AnswerRequest $request, GroundingPrompt $prompt,
        A\GroundingEvidence $evidence, A\TransportRequest $outbound, A\CancellationToken $cancellation): A\AnswerOutcome
    {
        try {
            $reservation = $this->budget->reserve($request->invocationKey, $this->composer->estimate($request, $prompt));
        } catch (\Throwable) {
            // A lost reserve ACK may have reserved funds; do not release or invent a confirmation.
            return $this->complete($record, $this->notSent($request, A\OutcomeCode::BudgetDenied));
        }
        if (!$reservation->invocationRef->equals($request->invocationRef()) || $reservation->status !== A\ReservationStatus::Confirmed) {
            return $this->complete($record, $this->notSent($request, A\OutcomeCode::BudgetDenied));
        }
        try {
            $record = $this->ledger->reserve($record->claim, $outbound, $reservation);
        } catch (\Throwable) {
            // A concurrent/uncertain CAS cannot prove this stable-key reservation unused.
            return $this->unavailable($request, A\UnavailableComponent::Ledger);
        }
        if ($cancellation->isCancelled()) {
            return $this->unused($record, $this->cancelled($request), $reservation);
        }

        return $this->send($record, $request, $prompt, $evidence, $outbound, $reservation, $cancellation);
    }

    private function send(A\InvocationRecord $record, A\AnswerRequest $request, GroundingPrompt $prompt,
        A\GroundingEvidence $evidence, A\TransportRequest $outbound, A\BudgetReservation $reservation,
        A\CancellationToken $cancellation): A\AnswerOutcome
    {
        try {
            $record = $this->ledger->markSending($record->claim, $outbound->identity);
        } catch (\Throwable) {
            return $this->transportUnknown($request);
        }
        try {
            $decision = $this->guard($request, $outbound, $prompt, $evidence);
            if ($decision instanceof A\AnswerOutcome) {
                return $this->unused($record, $decision, $reservation);
            }
            $finalCheck = $this->settlement->egressDecision($outbound, $prompt->manifest($outbound), $decision);
            $this->ledger->assertLive($record->claim);
            if ($cancellation->isCancelled()) {
                return $this->unused($record, $this->cancelled($request), $reservation);
            }
            if ($outbound->identity->deadline <= $this->now() || $finalCheck->expiresAt <= $this->now()) {
                return $this->unused($record, $this->unavailable($request, A\UnavailableComponent::Transport), $reservation);
            }
        } catch (\Throwable) {
            return $this->unknown($record, $this->transportUnknown($request));
        }
        try {
            // Literal single local dispatch. No repair, retry, fallback or paid probe.
            $result = $this->transport->send($outbound, $cancellation);
        } catch (A\TransportUnavailable $failure) {
            return $this->unused($record, $failure->outcome($request->invocationRef()), $reservation);
        } catch (\Throwable) {
            $result = new A\TransportResult(A\DispatchCertainty::Unknown, null, false, new A\UsageObservation());
        }

        return $this->finish($record, $request, $evidence, $outbound, $reservation, $result, $cancellation, $finalCheck);
    }

    private function finish(A\InvocationRecord $record, A\AnswerRequest $request, A\GroundingEvidence $evidence,
        A\TransportRequest $outbound, A\BudgetReservation $reservation, A\TransportResult $result,
        A\CancellationToken $cancellation, A\EgressDecisionRef $finalCheck): A\AnswerOutcome
    {
        try {
            // Persist raw final observation + nullable usage BEFORE publishing or settling.
            $usageRef = $this->settlement->capture($outbound, $reservation, $result, $finalCheck);
            $outcome = $this->mapper->result($request, $record->pins->binding, $evidence, $result, $usageRef, $cancellation->isCancelled());
            if ($outcome->certainty === A\DispatchCertainty::Unknown) {
                return $this->unknown($record, $outcome);
            }
            $this->ledger->complete($record->claim, $outcome);
        } catch (\Throwable) {
            // Lost persistence ACK cannot be repaired by generating again; retain reservation.
            return $this->unknown($record, $this->transportUnknown($request));
        }
        $this->settlement->settle($request->scope, $outcome);
        if ($outcome->answeredContent() !== null) {
            return $this->replay->replay($request, $request->scope);
        }

        return $outcome;
    }

    private function guard(A\AnswerRequest $request, A\TransportRequest $outbound, GroundingPrompt $prompt,
        A\GroundingEvidence $evidence): D\DecisionReceipt|A\AnswerOutcome
    {
        try {
            $request->priorTurns->requireCurrentAttestation($request->scope, $this->now());
            $outbound->identity->binding->requireCurrent($this->now());
            if ($outbound->identity->deadline <= $this->now() || $outbound->identity->egressDecision->expiresAt <= $this->now()) {
                throw new D\DomainFailure(D\Outcome::Denied, 'stale_egress');
            }
            if (!$outbound->identity->binding->transport->equals($this->transport->descriptor())) {
                throw new D\DomainFailure(D\Outcome::Unavailable, 'transport_mismatch');
            }
            $decision = $this->authorization->beforeSend($request->scope, $outbound->identity);
            if ($decision !== null) {
                A\InvocationDecision::requireMetadata($decision, $request->invocationRef());
                if ($decision->certainty !== A\DispatchCertainty::KnownNotSent) {
                    throw new \InvalidArgumentException('Guard must prove no dispatch');
                }

                return $decision;
            }

            return $this->egress->authorize($outbound->identity, $prompt->manifest($outbound), $evidence->policyBindingDigest());
        } catch (D\DomainFailure $failure) {
            return $this->mapper->failure($request, $failure);
        } catch (\Throwable) {
            return $this->notSent($request, A\OutcomeCode::PolicyUnknown);
        }
    }

    private function outbound(A\AnswerRequest $request, GroundingPrompt $prompt, A\GroundingEvidence $evidence,
        DateTimeImmutable $deadline): A\TransportRequest
    {
        $now = $this->now();
        $checkRef = 'egress:' . hash('sha256', $prompt->bytes . $now->format('U.u'));
        $decision = new A\EgressDecisionRef($checkRef, $evidence->policyBindingDigest()->value, $now, $now->modify('+5 seconds'));

        return $prompt->request($request->invocationRef(), $decision, $deadline);
    }

    private function unused(A\InvocationRecord $record, A\AnswerOutcome $outcome, A\BudgetReservation $reservation): A\AnswerOutcome
    {
        try {
            $this->ledger->complete($record->claim, $outcome);
        } catch (\Throwable) {
            if ($record->state === A\InvocationState::Sending) {
                return $this->unknown($record, new A\AnswerOutcome(A\OutcomeCode::TransportUnknown, $record->invocationRef, A\DispatchCertainty::Unknown));
            }

            return new A\AnswerOutcome(A\OutcomeCode::Unavailable, $record->invocationRef, A\DispatchCertainty::KnownNotSent,
                component: A\UnavailableComponent::Ledger);
        }

        if ($outcome->certainty === A\DispatchCertainty::KnownNotSent) {
            try {
                $reason = $outcome->code === A\OutcomeCode::Cancelled ? A\ReleaseReason::CancelledBeforeSend : A\ReleaseReason::DeniedBeforeSend;
                $this->budget->release($reservation, $reason);
            } catch (\Throwable) {
                // Confirmed unused reservation remains with its owner for stable-key reconciliation.
            }
        }

        return $outcome;
    }

    private function complete(A\InvocationRecord $record, A\AnswerOutcome $outcome): A\AnswerOutcome
    {
        try {
            $this->ledger->complete($record->claim, $outcome);

            return $outcome;
        } catch (\Throwable) {
            return new A\AnswerOutcome(A\OutcomeCode::Unavailable, $record->invocationRef, A\DispatchCertainty::KnownNotSent,
                component: A\UnavailableComponent::Ledger);
        }
    }

    private function unknown(A\InvocationRecord $record, A\AnswerOutcome $outcome): A\AnswerOutcome
    {
        try {
            $this->ledger->markUnknown($record->claim);
        } catch (\Throwable) {
            // Sending/unknown remains non-sendable under existing ledger recovery.
        }

        return $outcome;
    }

    private function notSent(A\AnswerRequest $request, A\OutcomeCode $code): A\AnswerOutcome
    {
        return new A\AnswerOutcome($code, $request->invocationRef(), A\DispatchCertainty::KnownNotSent,
            fixture: $this->mode === ProfileMode::Test);
    }

    private function cancelled(A\AnswerRequest $request): A\AnswerOutcome
    {
        return new A\AnswerOutcome(A\OutcomeCode::Cancelled, $request->invocationRef(), A\DispatchCertainty::KnownNotSent,
            fixture: $this->mode === ProfileMode::Test, sent: false);
    }

    private function unavailable(A\AnswerRequest $request, A\UnavailableComponent $component): A\AnswerOutcome
    {
        return new A\AnswerOutcome(A\OutcomeCode::Unavailable, $request->invocationRef(), A\DispatchCertainty::KnownNotSent,
            fixture: $this->mode === ProfileMode::Test, component: $component);
    }

    private function transportUnknown(A\AnswerRequest $request): A\AnswerOutcome
    {
        return new A\AnswerOutcome(A\OutcomeCode::TransportUnknown, $request->invocationRef(), A\DispatchCertainty::Unknown,
            fixture: $this->mode === ProfileMode::Test);
    }

    /** @phpstan-impure */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->clock->now()->value);
    }
}
