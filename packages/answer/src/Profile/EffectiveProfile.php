<?php

declare(strict_types=1);

namespace KnowKit\Answer\Profile;

use KnowKit\Answer\Domain\TransportDescriptor;
use KnowKit\Domain\CredentialRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Explicit local configuration metadata; constructing it never probes a provider. */
final readonly class EffectiveProfile
{
    public function __construct(
        public ProfileMode $mode,
        public ?TransportDescriptor $transport = null,
        public bool $clientConfigured = false,
        public ?CredentialRef $credentialRef = null,
    ) {
        if ($transport !== null && !$transport->production && $mode !== ProfileMode::Test) {
            throw new DomainFailure(Outcome::Incompatible, 'test_transport_forbidden');
        }
    }

    public function requireTest(): void
    {
        if ($this->mode !== ProfileMode::Test) {
            throw new DomainFailure(Outcome::Incompatible, 'test_transport_forbidden');
        }
    }
}
