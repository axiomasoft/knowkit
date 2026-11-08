<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Port;

use Chatom\Actors\Data\ActorRef;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Chatom\Domain\ConversationRef;

/** Fresh host identity, viewer binding, audience/consent and source authority for EACH read.
 * Citation handles are locators only. Never restore an old answer's access context.
 */
interface CitationViewerContext
{
    public function current(ConversationRef $conversation, ActorRef $viewer): AccessContext;
}
