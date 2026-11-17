<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use DateTimeImmutable;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\AnswerBudget;
use KnowKit\Answer\Port\InvocationPayloads;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\Clock;

/** Capture first, settle by stable reservation key. Settlement never regenerates a response. */
final readonly class AnswerSettlement
{
    public function __construct(
        private InvocationPayloads $payloads,
        private AnswerBudget $budget,
        private Clock $clock,
        private int $retentionSeconds = 86400,
    ) {
        if ($retentionSeconds < 1) {
            throw new \InvalidArgumentException('Observation retention required');
        }
    }

    public function capture(A\TransportRequest $request, A\BudgetReservation $reservation, A\TransportResult $result,
        A\EgressDecisionRef $finalCheck): string
    {
        $wire = new JsonObject([
            'schemaVersion' => 'answer-observation:1',
            'invocationRef' => $request->identity->invocationRef->toWire(),
            'modelCallIdentityDigest' => $request->identity->digest()->value,
            'requestBytesDigest' => $request->identity->requestBytesDigest->value,
            'finalEgressCheck' => $finalCheck->toWire(),
            'reservationRef' => $reservation->ref,
            'certainty' => $result->certainty->value,
            'complete' => $result->complete,
            'text' => $result->text,
            'observedModelRef' => $result->observedModelRef,
            'fixture' => $result->fixture,
            'inputTokens' => $result->usage->inputTokens,
            'outputTokens' => $result->usage->outputTokens,
            'providerRequestId' => $result->usage->providerRequestId,
        ]);
        $ref = $this->payloads->put($request->identity->binding->scope, 'observation', Jcs::canonicalize($wire),
            $request->identity->dependencies, new DateTimeImmutable($this->clock->now()->plusSeconds($this->retentionSeconds)->value));

        return $ref->resourceId->value . ':' . $ref->digest->value;
    }

    /** Persist the actual owner receipt, including its check time/version, for these exact bytes. */
    public function egressDecision(A\TransportRequest $request, A\EgressContentManifest $manifest, D\DecisionReceipt $receipt): A\EgressDecisionRef
    {
        if (!$manifest->identity->digest()->equals($request->identity->digest())) {
            throw new \InvalidArgumentException('Egress receipt must bind the exact call');
        }
        $wire = $receipt->toWire();
        $record = new JsonObject([
            'schemaVersion' => 'answer-egress-check:1',
            'invocationRef' => $request->identity->invocationRef->toWire(),
            'modelCallIdentityDigest' => $request->identity->digest()->value,
            'requestBytesDigest' => $request->identity->requestBytesDigest->value,
            'manifest' => $manifest->toWire(),
            'receipt' => $wire,
        ]);
        $ref = $this->payloads->put($request->identity->binding->scope, 'egress-check', Jcs::canonicalize($record),
            $request->identity->dependencies, new DateTimeImmutable($this->clock->now()->plusSeconds($this->retentionSeconds)->value));

        return new A\EgressDecisionRef($ref->resourceId->value . ':' . $ref->digest->value,
            $request->identity->egressDecision->policyVersion,
            new DateTimeImmutable($wire->get('checkedAt')), new DateTimeImmutable($wire->get('expiresAt')));
    }

    /** False leaves the captured response intact and the reservation with its owner. */
    public function settle(D\Scope $scope, A\AnswerOutcome $outcome): bool
    {
        if ($outcome->usageRef === null || $outcome->certainty === A\DispatchCertainty::Unknown) {
            return true;
        }
        try {
            [$id, $digest] = explode(':', $outcome->usageRef, 2);
            $ref = new A\InvocationPayloadRef($scope->tenantId, new D\ResourceId($id), new D\Sha256($digest));
            $wire = StrictJson::decode($this->payloads->read($scope, $ref));
            $this->requireObservation($wire, $outcome);
            $reservation = new A\BudgetReservation($outcome->invocationRef, $wire->get('reservationRef'), A\ReservationStatus::Confirmed);
            if ($outcome->certainty === A\DispatchCertainty::KnownNotSent) {
                $this->budget->release($reservation, A\ReleaseReason::FailedBeforeSend);

                return true;
            }
            $this->budget->commit($reservation, new A\UsageObservation($wire->get('inputTokens'), $wire->get('outputTokens'), $wire->get('providerRequestId')));

            return true;
        } catch (\Throwable) {
            // Immutable observation/response remain available for owning reconciliation by key.
            return false;
        }
    }

    private function requireObservation(mixed $wire, A\AnswerOutcome $outcome): void
    {
        if (!$wire instanceof JsonObject || $wire->get('schemaVersion') !== 'answer-observation:1'
            || !$wire->get('invocationRef') instanceof JsonObject
            || Jcs::canonicalize($wire->get('invocationRef')) !== Jcs::canonicalize($outcome->invocationRef->toWire())
            || $wire->get('certainty') !== $outcome->certainty->value) {
            throw new \InvalidArgumentException('Settlement observation mismatch');
        }
    }
}
