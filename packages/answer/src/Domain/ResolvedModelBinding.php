<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use DateTimeImmutable;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\CredentialRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;

/** Trusted host snapshot. bindingDigest is opaque and is never re-tagged or recomputed. */
final readonly class ResolvedModelBinding
{
    public function __construct(
        public Scope $scope,
        public ModelBindingRef $requestedRef,
        public string $provider,
        public string $account,
        public string $region,
        public string $model,
        public string $modelRevision,
        public TransportDescriptor $transport,
        public ModelCapabilities $capabilities,
        public CredentialRef $credentialRef,
        public GenerationSettings $settings,
        public Sha256 $bindingDigest,
    ) {
        foreach ([$provider, $account, $region, $model, $modelRevision] as $ref) {
            Guard::reference($ref);
        }
    }

    public function requireScope(Scope $scope): void
    {
        Guard::sameScope($this->scope, $scope);
    }

    public function requireCurrent(DateTimeImmutable $now): void
    {
        if (!$this->capabilities->textGeneration || $this->capabilities->expiresAt <= $now) {
            throw new DomainFailure(Outcome::Incompatible, 'model_capability_invalid');
        }
    }

    public function assertInput(
        string $completeInput,
        string $evidence,
        AnswerLimits $limits,
        ?\KnowKit\Port\Tokenizer $tokenizer = null,
    ): void {
        $identity = $this->capabilities->tokenizer;
        $limits->assertInput($completeInput, $evidence, $tokenizer, $identity);
        if ($this->settings->maxOutputTokens > $limits->outputTokens) {
            throw new DomainFailure(Outcome::Incompatible, 'token_limit');
        }
        $inputLimit = $this->capabilities->inputTokenLimit;
        $window = $this->capabilities->contextTokenLimit;
        if ($inputLimit === null && $window === null) {
            return;
        }
        if ($tokenizer === null || $identity === null) {
            throw new DomainFailure(Outcome::Incompatible, 'tokenizer_required');
        }
        $tokenizer->capabilities()->requireExact($identity);
        $tokens = $tokenizer->count($completeInput, $identity);
        if ($tokens < 0 || ($inputLimit !== null && $tokens > $inputLimit)
            || ($window !== null && $tokens + $this->settings->maxOutputTokens > $window)) {
            throw new DomainFailure(Outcome::Incompatible, 'token_limit');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'scope' => $this->scope->toWire(),
            'requestedRef' => $this->requestedRef->value,
            'provider' => $this->provider,
            'account' => $this->account,
            'region' => $this->region,
            'model' => $this->model,
            'modelRevision' => $this->modelRevision,
            'transport' => $this->transport->toWire(),
            'capabilities' => $this->capabilities->toWire(),
            'credentialRef' => new JsonObject(['handle' => $this->credentialRef->handle, 'present' => $this->credentialRef->present]),
            'settings' => $this->settings->toWire(),
            'bindingDigest' => $this->bindingDigest->value,
        ]);
    }

    public static function fromWire(JsonObject $wire): self
    {
        $scope = $wire->get('scope');
        $transport = $wire->get('transport');
        $credential = $wire->get('credentialRef');
        $settings = $wire->get('settings');

        return new self(
            new Scope(
                new \KnowKit\Domain\TenantId($scope->get('tenantId')),
                new \KnowKit\Domain\KbId($scope->get('kbId')),
                new \KnowKit\Domain\ActorId($scope->get('actorId')),
                $scope->get('purpose'),
                new \KnowKit\Domain\Decimal($scope->get('policyEpoch')),
                new Sha256($scope->get('hostScopeHash')),
            ),
            new ModelBindingRef($wire->get('requestedRef')),
            $wire->get('provider'),
            $wire->get('account'),
            $wire->get('region'),
            $wire->get('model'),
            $wire->get('modelRevision'),
            new TransportDescriptor(...iterator_to_array($transport)),
            ModelCapabilities::fromWire($wire->get('capabilities')),
            new \KnowKit\Domain\CredentialRef($credential->get('handle'), $credential->get('present')),
            new GenerationSettings(...iterator_to_array($settings)),
            new Sha256($wire->get('bindingDigest')),
        );
    }
}
