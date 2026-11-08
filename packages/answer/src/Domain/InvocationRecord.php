<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Sha256;

/** Immutable checkpoint. Its metadata and historical receipts grant no current access. */
final readonly class InvocationRecord
{
    public function __construct(
        public InvocationRef $invocationRef,
        public Sha256 $originalRequestDigest,
        public Sha256 $requestFingerprint,
        public InvocationPins $pins,
        public InvocationState $state,
        public LedgerClaim $claim,
        public ?Sha256 $modelCallIdentityDigest = null,
        public ?string $responsePayloadRef = null,
        public ?Sha256 $responsePayloadHash = null,
        public ?DependencyManifest $dependencies = null,
        public ?OutcomeCode $outcomeCode = null,
        public DispatchCertainty $dispatchCertainty = DispatchCertainty::KnownNotSent,
    ) {
        $pins->binding->scope->assertTenant($invocationRef->tenantId);
        if ($originalRequestDigest != $pins->originalRequestDigest) {
            throw new \InvalidArgumentException('Checkpoint must retain its original admitted request');
        }
        if ($invocationRef != $claim->invocationRef) {
            throw new \InvalidArgumentException('Claim belongs to another invocation');
        }
        if ($responsePayloadRef !== null) {
            Guard::reference($responsePayloadRef);
        }
        if (in_array($state, [InvocationState::Sending, InvocationState::Unknown], true)
            && $modelCallIdentityDigest === null) {
            throw new \InvalidArgumentException('Sending and unknown pin exact outbound identity');
        }
    }

    public function requireOriginal(AnswerRequest $request): void
    {
        if ($this->invocationRef != $request->invocationRef()
            || $this->originalRequestDigest != $request->originalRequestDigest()) {
            throw new \KnowKit\Domain\DomainFailure(\KnowKit\Domain\Outcome::Incompatible, 'invocation_conflict');
        }
        if ($this->pins->retrievalProfile != $request->retrievalProfileRef
            || $this->requestFingerprint != $request->fingerprint($this->pins->binding)) {
            throw new \KnowKit\Domain\DomainFailure(\KnowKit\Domain\Outcome::Incompatible, 'invocation_conflict');
        }
    }
}
