<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Handler;

use Chatom\Agents\Contracts\DurableAgentReply;
use Chatom\Authorization\Data\LockedChannel;
use Chatom\Authorization\Enums\ChannelAccessIntent;
use Chatom\Channels\Enums\ChannelAction;
use Chatom\Documents\Data\MessageDocument;
use Chatom\Messages\Data\MessageId;
use Chatom\Operations\Pipeline\OperationContext;
use Closure;
use KnowKit\Chatom\ChatomReplyBoundary;
use RuntimeException;

/** Owning native contract fixture: first saved body wins, including lost ACK.
 * Deliberately DOES NOT enforce changed-body conflicts; the answer ledger must do so.
 */
final class NativeReply implements DurableAgentReply
{
    public array $proposals = [];
    public array $saved = [];
    public bool $loseAck = false;
    public ?Closure $beforeMutation = null;

    public function __construct(
        public ChatomReplyBoundary $boundary,
        public OperationContext $context,
        public LockedChannel $channel,
        public string $key,
    ) {}

    public function send(string $slot, MessageDocument $content): MessageId
    {
        $this->proposals[] = [$this->key, $slot, $content->toArray()];
        $id = MessageId::from('native-reply');
        if (isset($this->saved[$slot])) {
            return $id;
        }
        if ($this->beforeMutation !== null) {
            ($this->beforeMutation)();
        }
        $this->boundary->reauthorize(
            context: $this->context,
            channel: $this->channel,
            action: ChannelAction::SendMessage,
            intent: ChannelAccessIntent::Write,
        );
        $this->saved[$slot] = $content->toArray();
        if ($this->loseAck) {
            $this->loseAck = false;
            throw new RuntimeException('lost_native_ack');
        }

        return $id;
    }

    public function completed(string $slot): ?MessageId
    {
        throw new RuntimeException('A saved reply receipt is not current authority');
    }

    public function draft(string $slot, MessageDocument $content): MessageId
    {
        throw new RuntimeException('Streaming is disabled');
    }

    public function revise(string $slot, string $changeKey, MessageDocument $content): MessageId
    {
        throw new RuntimeException('Immutable final reply');
    }
}
