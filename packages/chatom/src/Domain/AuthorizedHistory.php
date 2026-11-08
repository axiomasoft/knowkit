<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use Chatom\Actors\Data\ActorRef;
use Chatom\Messages\Data\MessageView;
use KnowKit\Answer\Domain\BoundedTurns;

/** Exact native projections retained only for this bounded invocation, never a grant. */
final readonly class AuthorizedHistory
{
    /** @param list<MessageView> $messages Chronological prior turns followed by the trigger. */
    public function __construct(
        public ConversationBinding $binding,
        public ActorRef $viewer,
        public MessageView $trigger,
        public array $messages,
        public BoundedTurns $priorTurns,
    ) {}

    public function manifestRef(): string
    {
        $identities = array_map(static fn (MessageView $message): array => [
            $message->id->value,
            $message->channel->value,
            (string) $message->sender,
            $message->position,
            $message->revision,
            hash('sha256', $message->body),
        ], $this->messages);

        return 'chatom-history:' . hash('sha256', json_encode([
            $this->binding->conversation->registrationKey(),
            $this->binding->bindingRevision,
            $this->binding->viewerBindingRef,
            $this->binding->modelBindingRef->value,
            $this->binding->hostScopeInputs->hostScopeHash->value,
            (string) $this->viewer,
            $identities,
        ], JSON_THROW_ON_ERROR));
    }
}
