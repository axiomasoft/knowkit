<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Sha256;

/** Reference-only checkpoint: SQL/payload custody implementations belong to the runtime milestone. */
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
}
