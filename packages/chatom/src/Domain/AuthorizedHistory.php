<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use Chatom\Actors\Data\ActorRef;
use Chatom\Messages\Data\MessageView;
use KnowKit\Answer\Domain\BoundedTurns;

/** Exact native projections retained only for this bounded invocation, never a grant. */
final readonly class AuthorizedHistory
{
    private string $manifest;

    /** @param list<MessageView> $messages Chronological prior turns followed by the trigger. */
    public function __construct(
        public ConversationBinding $binding,
        public ActorRef $viewer,
        public MessageView $trigger,
        public array $messages,
        public BoundedTurns $priorTurns,
    ) {
        $this->manifest = self::digest(binding: $binding, viewer: $viewer, messages: $messages);
    }

    public function manifestRef(): string
    {
        return $this->manifest;
    }

    /** @param list<MessageView> $messages */
    private static function digest(ConversationBinding $binding, ActorRef $viewer, array $messages): string
    {
        $identities = array_map(static fn (MessageView $message): array => [
            $message->id->value,
            $message->channel->value,
            (string) $message->sender,
            $message->position,
            $message->revision,
            hash('sha256', $message->body),
        ], $messages);

        return 'chatom-history:' . hash('sha256', json_encode([
            $binding->conversation->registrationKey(),
            $binding->bindingRevision,
            $binding->viewerBindingRef,
            $binding->modelBindingRef->value,
            $binding->hostScopeInputs->hostScopeHash->value,
            (string) $viewer,
            $identities,
        ], JSON_THROW_ON_ERROR));
    }
}
