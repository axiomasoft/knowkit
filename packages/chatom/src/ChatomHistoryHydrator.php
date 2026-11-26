<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use Chatom\Agents\Data\AgentInvocation;
use Chatom\Messages\Contracts\ViewerMessageReader;
use Chatom\Messages\Data\MessageView;
use Chatom\Messages\Data\ViewerMessageHistory;
use Chatom\Messages\Enums\MessageHistoryOrder;
use Chatom\Messages\Enums\MessageType;
use DateTimeImmutable;
use KnowKit\Answer\Domain\AnswerLimits;
use KnowKit\Answer\Domain\BoundedTurns;
use KnowKit\Answer\Domain\MessageRole;
use KnowKit\Answer\Domain\PriorTurn;
use KnowKit\Chatom\Domain\AuthorizedHistory;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationDenied;
use KnowKit\Chatom\Port\ConversationAudience;
use KnowKit\Port\Clock;
use Throwable;

final readonly class ChatomHistoryHydrator
{
    public function __construct(
        private ViewerMessageReader $reader,
        private ConversationAudience $audience,
        private Clock $clock,
    ) {}

    public function hydrate(
        ConversationBinding $binding,
        AgentInvocation $invocation,
        AnswerLimits $limits,
    ): AuthorizedHistory {
        try {
            $viewer = $this->audience->viewer(binding: $binding, invocation: $invocation);
            $page = $this->reader->history(new ViewerMessageHistory(
                space: $invocation->space,
                channel: $invocation->channel,
                viewer: $viewer,
                order: MessageHistoryOrder::PositionDescending,
                limit: 100,
            ));
            [$trigger, $prior] = $this->select(invocation: $invocation, items: $page->items, limits: $limits);
            $turns = new BoundedTurns(array_map(static fn (MessageView $message): PriorTurn => new PriorTurn(
                role: $message->sender->equals($invocation->agent) ? MessageRole::Assistant : MessageRole::User,
                text: $message->body,
            ), $prior));
            $history = new AuthorizedHistory(
                binding: $binding,
                viewer: $viewer,
                trigger: $trigger,
                messages: [...$prior, $trigger],
                priorTurns: $turns,
            );

            return $this->attested(binding: $binding, invocation: $invocation, history: $history);
        } catch (Throwable) {
            throw new ConversationDenied();
        }
    }

    /** @param list<MessageView> $items
     * @return array{MessageView, list<MessageView>}
     */
    private function select(AgentInvocation $invocation, array $items, AnswerLimits $limits): array
    {
        if (count($items) > 100) {
            throw new ConversationDenied();
        }
        $trigger = null;
        $prior = [];
        $lastPosition = PHP_INT_MAX;
        $bytes = 0;
        $budgetReached = false;

        foreach ($items as $message) {
            if ($message->channel->value !== $invocation->channel->value
                || $message->position >= $lastPosition || $message->status->isHiddenFromStream()
                || $message->type === MessageType::Deleted) {
                throw new ConversationDenied();
            }
            $lastPosition = $message->position;
            if ($message->id->value === $invocation->message->id->value) {
                $this->assertTrigger(invocation: $invocation, message: $message);
                $trigger = $message;
                continue;
            }
            if ($trigger === null || $budgetReached || count($prior) >= $limits->priorTurns) {
                continue;
            }
            if ($bytes + strlen($message->body) > $limits->priorBytes) {
                $budgetReached = true;
                continue;
            }
            $prior[] = $message;
            $bytes += strlen($message->body);
        }
        if ($trigger === null) {
            throw new ConversationDenied();
        }

        return [$trigger, array_reverse($prior)];
    }

    private function assertTrigger(AgentInvocation $invocation, MessageView $message): void
    {
        if ($message->revision !== $invocation->message->revision
            || $message->position !== $invocation->message->position
            || !$message->sender->equals($invocation->message->sender)) {
            throw new ConversationDenied();
        }
    }

    private function attested(
        ConversationBinding $binding,
        AgentInvocation $invocation,
        AuthorizedHistory $history,
    ): AuthorizedHistory {
        $attestation = $this->audience->attest(binding: $binding, invocation: $invocation, history: $history);
        $now = new DateTimeImmutable($this->clock->now()->value);
        $attestation->requireCurrent(scope: $binding->hostScopeInputs, digest: $history->priorTurns->digest(), now: $now);
        if ($attestation->ref !== $history->manifestRef() || $attestation->expiresAt > $now->modify('+60 seconds')) {
            throw new ConversationDenied();
        }

        return new AuthorizedHistory(
            binding: $binding,
            viewer: $history->viewer,
            trigger: $history->trigger,
            messages: $history->messages,
            priorTurns: new BoundedTurns(turns: $history->priorTurns->turns, attestation: $attestation),
        );
    }
}
