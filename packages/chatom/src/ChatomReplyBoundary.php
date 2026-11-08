<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use Chatom\Actors\Data\ActorRef;
use Chatom\Agents\Contracts\DurableAgentReply;
use Chatom\Agents\Data\AgentInvocation;
use Chatom\Authorization\Contracts\ChannelAccess;
use Chatom\Authorization\Data\AccessDecision;
use Chatom\Authorization\Data\AuthorizedChannel;
use Chatom\Authorization\Data\LockedChannel;
use Chatom\Authorization\Enums\ChannelAccessIntent;
use Chatom\Channels\Contracts\GrantableAction;
use Chatom\Channels\Data\ChannelId;
use Chatom\Channels\Enums\ChannelAction;
use Chatom\Documents\Data\MessageDocument;
use Chatom\Messages\Data\MessageId;
use Chatom\Operations\Pipeline\OperationContext;
use Chatom\Operations\Pipeline\Phase;
use Chatom\Spaces\Data\SpaceId;
use Closure;
use KnowKit\Chatom\Domain\ConversationDenied;

/** Explicit request-scoped ChannelAccess decorator installed in the owning Chatom pipeline.
 * Contains no durable state. Never share one instance between concurrent fibers/requests.
 */
final class ChatomReplyBoundary implements ChannelAccess
{
    private ?Closure $guard = null;
    private ?AgentInvocation $invocation = null;

    public function __construct(
        private readonly ChannelAccess $native,
    ) {}

    public function send(
        AgentInvocation $invocation,
        DurableAgentReply $reply,
        MessageDocument $content,
        Closure $guard,
    ): MessageId {
        if ($this->guard !== null) {
            throw new ConversationDenied();
        }
        $this->guard = $guard;
        $this->invocation = $invocation;
        try {
            $guard();

            return $reply->send(slot: ChatomReplyMapper::SLOT, content: $content);
        } finally {
            $this->guard = null;
            $this->invocation = null;
        }
    }

    public function view(SpaceId $space, ChannelId $channel, ActorRef $actor): bool
    {
        return $this->native->view(space: $space, channel: $channel, actor: $actor);
    }

    public function decide(SpaceId $space, ChannelId $channel, ActorRef $actor, GrantableAction $action): AccessDecision
    {
        return $this->native->decide(space: $space, channel: $channel, actor: $actor, action: $action);
    }

    public function lock(OperationContext $context, ChannelId $channel): LockedChannel
    {
        return $this->native->lock(context: $context, channel: $channel);
    }

    public function reauthorize(
        OperationContext $context,
        LockedChannel $channel,
        ?GrantableAction $action,
        ChannelAccessIntent $intent = ChannelAccessIntent::Read,
    ): AuthorizedChannel {
        $authorized = $this->native->reauthorize(context: $context, channel: $channel, action: $action, intent: $intent);
        if ($this->guard === null) {
            return $authorized;
        }
        $invocation = $this->invocation;
        if ($context->operation() !== 'message.send' || $context->phase() !== Phase::Reauthorize
            || $intent !== ChannelAccessIntent::Write
            || $action !== ChannelAction::SendMessage || !$context->actor()->equals($invocation->agent)
            || $context->space()->value !== $invocation->space->value
            || $channel->snapshot->id->value !== $invocation->channel->value
            || $channel->snapshot->space->value !== $invocation->space->value) {
            throw new ConversationDenied();
        }
        ($this->guard)();

        return $authorized;
    }
}
