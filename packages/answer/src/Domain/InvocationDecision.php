<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Scope;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final class InvocationDecision
{
    public static function requireAudience(Scope $current, Scope $admitted): void
    {
        if ($current->tenantId->value !== $admitted->tenantId->value || $current->kbId->value !== $admitted->kbId->value
            || $current->actorId->value !== $admitted->actorId->value || $current->purpose !== $admitted->purpose
            || $current->hostScopeHash->value !== $admitted->hostScopeHash->value) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    public static function requireMetadata(AnswerOutcome $decision, InvocationRef $ref): void
    {
        if (!$decision->invocationRef->equals($ref) || $decision->answeredContent() !== null
            || !in_array($decision->code, [OutcomeCode::Denied, OutcomeCode::EgressDenied, OutcomeCode::PolicyUnknown], true)) {
            throw new \InvalidArgumentException('Metadata-only current authorization decision required');
        }
    }
}
