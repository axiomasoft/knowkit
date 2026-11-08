<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Answer\Port\InvocationPayloads;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\StrictJson;

final readonly class InvocationReplay
{
    public function __construct(
        private InvocationLedger $ledger,
        private InvocationPayloads $payloads,
        private InvocationAuthorization $authorization,
        private ContractValidator $validator,
    ) {
    }

    public function replay(A\AnswerRequest $original, D\Scope $current): A\AnswerOutcome
    {
        try {
            $record = $this->ledger->find($original->invocationRef());
        } catch (D\DomainFailure $error) {
            if ($error->outcome !== D\Outcome::Unavailable && $error->errorCode !== 'blob_missing') {
                throw $error;
            }

            return $this->unavailable($original->invocationRef(), A\DispatchCertainty::Unknown);
        }
        if ($record === null) {
            return $this->unavailable($original->invocationRef());
        }
        $record->requireOriginal($original);
        A\InvocationDecision::requireAudience($current, $record->pins->binding->scope);
        if (in_array($record->state, [A\InvocationState::Sending, A\InvocationState::Unknown], true)) {
            return new A\AnswerOutcome(A\OutcomeCode::TransportUnknown, $record->invocationRef, A\DispatchCertainty::Unknown);
        }
        if ($record->state !== A\InvocationState::Completed || $record->responsePayloadRef === null || $record->responsePayloadHash === null) {
            return $this->unavailable($record->invocationRef);
        }
        // No mutable binding lookup, credential refresh or model generation during replay.
        return $this->authorizedResponse($current, $record);
    }

    private function authorizedResponse(D\Scope $current, A\InvocationRecord $record): A\AnswerOutcome
    {
        $denial = $this->authorize($current, $record);
        if ($denial !== null) {
            return $denial;
        }
        $outcome = $this->materialize($current, $record);
        if ($outcome->answeredContent() === null) {
            return $outcome;
        }

        // Blob I/O can take time. Recheck authority immediately before disclosing the bytes.
        return $this->authorize($current, $record) ?? $outcome;
    }

    private function authorize(D\Scope $current, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        $denial = $this->authorization->beforeReplay($current, $record);
        if ($denial !== null) {
            A\InvocationDecision::requireMetadata($denial, $record->invocationRef);

            return new A\AnswerOutcome($denial->code, $record->invocationRef, $record->dispatchCertainty,
                fixture: $denial->fixture, reason: $denial->reason);
        }

        return $denial;
    }

    private function materialize(D\Scope $scope, A\InvocationRecord $record): A\AnswerOutcome
    {
        try {
            $ref = new A\InvocationPayloadRef($scope->tenantId, new D\ResourceId($record->responsePayloadRef), $record->responsePayloadHash);
            $outcome = A\AnswerOutcome::fromWire(StrictJson::decode($this->payloads->read($scope, $ref)), $this->validator);
        } catch (D\DomainFailure) {
            return $this->unavailable($record->invocationRef, $record->dispatchCertainty);
        }
        if ($outcome->invocationRef != $record->invocationRef || $outcome->code !== $record->outcomeCode
            || $outcome->certainty !== $record->dispatchCertainty) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }

        return $outcome;
    }

    private function unavailable(A\InvocationRef $ref, A\DispatchCertainty $certainty = A\DispatchCertainty::KnownNotSent): A\AnswerOutcome
    {
        return new A\AnswerOutcome(A\OutcomeCode::Unavailable, $ref, $certainty,
            component: A\UnavailableComponent::Payload);
    }
}
