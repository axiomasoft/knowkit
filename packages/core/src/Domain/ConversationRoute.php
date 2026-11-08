<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Host composition metadata; never an authorization grant. */
enum ConversationRoute: string
{
    case CallerManaged = 'caller_managed';
    case DirectChatom = 'direct_chatom';
    case AgentoolsOrchestrated = 'agentools_orchestrated';
}
