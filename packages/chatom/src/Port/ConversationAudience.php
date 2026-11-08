<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Port;

use Chatom\Actors\Data\ActorRef;
use Chatom\Agents\Data\AgentInvocation;
use KnowKit\Answer\Domain\DependencyManifest;
use KnowKit\Answer\Domain\HistoryReadAttestation;
use KnowKit\Chatom\Domain\AuthorizedHistory;
use KnowKit\Chatom\Domain\ConversationBinding;

/** Host-owned CURRENT authority. Throw on denied, missing, stale or unavailable facts.
 * Check bot/channel/assistant AND intended learner, audience, class, lesson, consent,
 * rights and source revisions, including sources in historical assistant turns.
 * Preserve the entire trusted Scope/hostScopeHash and business purpose.
 */
interface ConversationAudience
{
    public function viewer(ConversationBinding $binding, AgentInvocation $invocation): ActorRef;

    /** Recheck all projected messages and their exact revisions/hashes after reader I/O.
     * The receipt ref must equal history.manifestRef(); expiry must be short and current.
     */
    public function attest(
        ConversationBinding $binding,
        AgentInvocation $invocation,
        AuthorizedHistory $history,
    ): HistoryReadAttestation;

    /** No saved receipt, membership or successful model response substitutes for this check. */
    public function assertCurrent(
        ConversationBinding $binding,
        AgentInvocation $invocation,
        AuthorizedHistory $history,
        ?DependencyManifest $dependencies,
    ): void;
}
