<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;

final readonly class AnswerRequest
{
    public function __construct(
        public Scope $scope,
        public string $question,
        public BoundedTurns $priorTurns,
        public ModelBindingRef $modelBindingRef,
        public ProfileRef $retrievalProfileRef,
        public InvocationKey $invocationKey,
        public AnswerLimits $limits = new AnswerLimits(),
    ) {
        Guard::utf8($question, $limits->questionBytes, false);
        if (count($priorTurns->turns) > $limits->priorTurns || $priorTurns->byteLength() > $limits->priorBytes) {
            throw new \InvalidArgumentException('Prior turns exceed admitted limits');
        }
    }

    public function invocationRef(): InvocationRef
    {
        return new InvocationRef($this->scope->tenantId, $this->invocationKey);
    }

    /** Compare this BEFORE resolving any mutable binding on retries. */
    public function originalRequestDigest(): Sha256
    {
        return new Sha256(hash('sha256', Jcs::canonicalize($this->originalIdentity())));
    }

    public function fingerprint(ResolvedModelBinding $binding): Sha256
    {
        $binding->requireScope($this->scope);
        if ($binding->requestedRef->value !== $this->modelBindingRef->value) {
            throw new \InvalidArgumentException('Pinned binding belongs to another requested handle');
        }

        return new Sha256(HashDomain::AnswerInvocationFingerprint->digest(
            $this->originalIdentity()->with('pinnedBindingDigest', $binding->bindingDigest->value),
        ));
    }

    private function originalIdentity(): JsonObject
    {
        return new JsonObject([
            'operationVersion' => 'answer:v1',
            'tenant' => $this->scope->tenantId->value,
            'kb' => $this->scope->kbId->value,
            'actor' => $this->scope->actorId->value,
            'businessPurpose' => $this->scope->purpose,
            'policyEpoch' => $this->scope->policyEpoch->value,
            'hostScopeHash' => $this->scope->hostScopeHash->value,
            'questionDigest' => hash('sha256', $this->question),
            'orderedTurnsDigest' => $this->priorTurns->digest()->value,
            'requestedBindingRef' => $this->modelBindingRef->value,
            'exactRetrievalProfileRef' => $this->retrievalProfileRef->toWire(),
            'limits' => $this->limits->toWire(),
        ]);
    }

    public function __debugInfo(): array
    {
        return ['invocationRef' => $this->invocationRef(), 'questionBytes' => strlen($this->question)];
    }
}
