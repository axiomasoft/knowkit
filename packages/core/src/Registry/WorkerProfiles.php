<?php

declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\Capability;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ParserCapabilities;
use KnowKit\Domain\ParserProfile;

/** Host-owned qualification registry; subprocess negotiation cannot enable a profile. */
final class WorkerProfiles
{
    /** @var array<string, array{ParserProfile, ParserCapabilities, bool, bool}> */
    private array $profiles = [];
    /** @var array<string, true> */
    private array $revoked = [];
    /** @var array<string, array<string, true>> */
    private array $revokedCapabilities = [];

    public function register(ParserProfile $profile, ParserCapabilities $capabilities, bool $qualified, bool $network = false): void
    {
        if (isset($this->profiles[$profile->id])) {
            throw new DomainFailure(Outcome::Incompatible, 'duplicate_profile');
        }
        $this->profiles[$profile->id] = [$profile, $capabilities, $qualified, $network];
    }

    public function revoke(string $id): void
    {
        $this->revoked[$id] = true;
    }

    public function revokeCapability(string $id, Capability $capability): void
    {
        // Profile ids are arbitrary text and may contain ':'. Do not join them into one map key.
        $this->revokedCapabilities[$id][$capability->value] = true;
    }

    /** @param list<Capability> $required */
    public function require(ParserProfile $profile, array $required): void
    {
        if (isset($this->revoked[$profile->id])) {
            throw new DomainFailure(Outcome::Denied, 'profile_revoked');
        }
        [$registered, $capabilities, $qualified, $network] = $this->profiles[$profile->id]
            ?? throw new DomainFailure(Outcome::Incompatible, 'unsupported_profile');
        if (!$registered->signature->equals($profile->signature) || !$registered->options->equals($profile->options)) {
            throw new DomainFailure(Outcome::Incompatible, 'profile_mismatch');
        }
        if ($network) {
            throw new DomainFailure(Outcome::Denied, 'network_parser_denied');
        }
        if (!$qualified) {
            throw new DomainFailure(Outcome::Unavailable, 'profile_not_qualified');
        }
        foreach ($required as $capability) {
            if (isset($this->revokedCapabilities[$profile->id][$capability->value])) {
                throw new DomainFailure(Outcome::Denied, 'capability_revoked');
            }
            $capabilities->require($capability, 'ir-1.0');
        }
    }
}
