<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Port;

use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationRef;

/** Host-owned current authority. Missing/unknown bindings throw; no default binding. */
interface ConversationBindingResolver
{
    public function resolve(ConversationRef $conversation): ConversationBinding;
}
