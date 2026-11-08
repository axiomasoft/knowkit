<?php

declare(strict_types=1);

namespace KnowKit\Answer\Port;

use KnowKit\Answer\Domain\AnswerOutcome;
use KnowKit\Answer\Domain\InvocationRecord;
use KnowKit\Answer\Domain\ModelCallIdentity;
use KnowKit\Domain\Scope;

/** Host authority, not a saved receipt. No result may carry protected response bytes.
 * Check current lifecycle, source/history visibility, whole-content egress and host audience.
 * beforeSend also checks current credentials/policy/capabilities against the pinned deployment.
 * Null means allowed. Denied/EgressDenied/PolicyUnknown are metadata-only decisions.
 */
interface InvocationAuthorization
{
    public function beforeSend(Scope $scope, ModelCallIdentity $identity): ?AnswerOutcome;

    public function beforeReplay(Scope $scope, InvocationRecord $record): ?AnswerOutcome;

    /** A new fence never reuses an old policy check. */
    public function beforeResume(Scope $scope, InvocationRecord $record): ?AnswerOutcome;
}
