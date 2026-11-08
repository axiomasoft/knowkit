<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use Chatom\Agents\Data\AgentInvocation;
use InvalidArgumentException;
use KnowKit\Answer\Domain\AnswerLimits;
use KnowKit\Answer\Domain\ProfileRef;

final readonly class HandlerConfiguration
{
    public function __construct(
        public ConversationRef $conversation,
        public string $handlerKey,
        public int $handlerVersion,
        public ProfileRef $retrievalProfile,
        public AnswerLimits $limits = new AnswerLimits(),
    ) {
        ConversationRef::assertReference($handlerKey);
        if ($handlerVersion < 1) {
            throw new InvalidArgumentException('Positive native handler version required');
        }
    }

    public function matches(AgentInvocation $invocation): bool
    {
        return $invocation->tenant?->value === $this->conversation->tenantId->value
            && $invocation->space->value === $this->conversation->spaceId
            && $invocation->channel->value === $this->conversation->channelId
            && (string) $invocation->agent === $this->conversation->botId
            && $invocation->handlerKey === $this->handlerKey
            && $invocation->handlerVersion === $this->handlerVersion;
    }
}
