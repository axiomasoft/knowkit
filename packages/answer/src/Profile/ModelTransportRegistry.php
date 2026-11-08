<?php

declare(strict_types=1);

namespace KnowKit\Answer\Profile;

use KnowKit\Answer\Domain\TransportDescriptor;
use KnowKit\Answer\Domain\UnavailableComponent;
use KnowKit\Answer\Port\ModelTransport;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Explicit model registry, separate from the core embedding adapter registry. */
final class ModelTransportRegistry
{
    /** @var array<string, array{TransportDescriptor, ModelTransport}> */
    private array $entries = [];

    public function register(ModelTransport $transport): void
    {
        $descriptor = $transport->descriptor();
        if (isset($this->entries[$descriptor->id])) {
            throw new DomainFailure(Outcome::Incompatible, 'duplicate_transport');
        }
        $this->entries[$descriptor->id] = [$descriptor, $transport];
    }

    public function readiness(EffectiveProfile $profile): Readiness
    {
        $descriptor = $profile->transport;
        if ($descriptor === null || !isset($this->entries[$descriptor->id])
            || $this->entries[$descriptor->id][0] != $descriptor) {
            return new Readiness(UnavailableComponent::Transport);
        }
        if (!$profile->clientConfigured) {
            return new Readiness(UnavailableComponent::HttpClient);
        }
        if ($descriptor->requiresCredentials && $profile->credentialRef?->present !== true) {
            return new Readiness(UnavailableComponent::Credentials);
        }

        return new Readiness(null);
    }

    public function resolve(EffectiveProfile $profile): ModelTransport
    {
        if (!$this->readiness($profile)->configured()) {
            throw new DomainFailure(Outcome::Unavailable, 'model_configuration_missing');
        }

        return $this->entries[$profile->transport->id][1];
    }
}
