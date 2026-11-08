<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Agents\Contracts\DurableAgentReply;
use Chatom\Documents\Data\MessageDocument;
use Chatom\Messages\Data\MessageId;
use RuntimeException;

/** Decorates an actual installed reply; no persistence or receipt emulation. */
final readonly class AcknowledgementLoss implements DurableAgentReply
{
    public function __construct(
        private DurableAgentReply $native,
    ) {}

    public function send(string $slot, MessageDocument $content): MessageId
    {
        $this->native->send(slot: $slot, content: $content);

        throw new RuntimeException('QUALIFICATION_LOST_ACK_AFTER_NATIVE_COMMIT');
    }

    public function completed(string $slot): ?MessageId
    {
        return $this->native->completed($slot);
    }

    public function draft(string $slot, MessageDocument $content): MessageId
    {
        throw new RuntimeException('Streaming disabled');
    }

    public function revise(string $slot, string $changeKey, MessageDocument $content): MessageId
    {
        throw new RuntimeException('Immutable final slot');
    }
}
