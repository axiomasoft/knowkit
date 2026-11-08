<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Agents\Contracts\AgentTrigger;
use Chatom\Agents\Contracts\TriggerPolicy;

final class Trigger implements TriggerPolicy
{
    public function shouldTrigger(AgentTrigger $trigger): bool
    {
        return $trigger->message->sender->type === 'user';
    }
}
