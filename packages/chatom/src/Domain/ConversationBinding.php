<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use InvalidArgumentException;
use KnowKit\Answer\Domain\ModelBindingRef;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\InstanceId;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Scope;

/** Immutable host snapshot. The adapter never resolves or selects a model. */
final readonly class ConversationBinding
{
    public function __construct(
        public ConversationRef $conversation,
        public ConversationRoute $route,
        public KbId $kbId,
        public ModelBindingRef $modelBindingRef,
        public Scope $hostScopeInputs,
        public string $viewerBindingRef,
        public string $bindingRevision,
        public InstanceId $instanceId,
        public ?LayerStackRef $layerStackRef = null,
    ) {
        ConversationRef::assertReference($viewerBindingRef);
        ConversationRef::assertReference($bindingRevision);

        if (!$conversation->tenantId->equals($hostScopeInputs->tenantId)
            || !$kbId->equals($hostScopeInputs->kbId)) {
            throw new InvalidArgumentException('Binding must preserve full tenant and knowledge scope');
        }
    }

    public function equals(self $other): bool
    {
        return $this->conversation->equals($other->conversation)
            && $this->route === $other->route
            && $this->kbId->equals($other->kbId)
            && $this->modelBindingRef->value === $other->modelBindingRef->value
            && $this->hostScopeInputs->actorId->equals($other->hostScopeInputs->actorId)
            && $this->hostScopeInputs->purpose === $other->hostScopeInputs->purpose
            && $this->hostScopeInputs->policyEpoch->value === $other->hostScopeInputs->policyEpoch->value
            && $this->hostScopeInputs->hostScopeHash->equals($other->hostScopeInputs->hostScopeHash)
            && $this->viewerBindingRef === $other->viewerBindingRef
            && $this->bindingRevision === $other->bindingRevision
            && $this->instanceId->equals($other->instanceId)
            && (($this->layerStackRef === null && $other->layerStackRef === null)
                || ($this->layerStackRef !== null && $other->layerStackRef !== null && $this->layerStackRef->equals($other->layerStackRef)));
    }
}
