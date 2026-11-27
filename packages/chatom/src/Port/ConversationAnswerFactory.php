<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Port;

use KnowKit\Answer\GroundedAnswering;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Chatom\Domain\LayerStackRef;
use KnowKit\Domain\InstanceId;

/** Explicit host composition, sharing the existing answer ledger/transport/budget.
 * Install the supplied guard in BOTH GroundedAnswerService and InvocationReplay.
 * Compose any additional host checks with it; never replace or bypass it.
 * This creates no invocation journal, retries, provider fallback or readiness probe.
 */
interface ConversationAnswerFactory
{
    public function create(
        InvocationAuthorization $authorization,
        InstanceId $instanceId,
        ?LayerStackRef $layerStackRef = null,
    ): GroundedAnswering;
}
