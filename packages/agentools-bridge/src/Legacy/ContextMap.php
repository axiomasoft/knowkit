<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Core\Security\HostAuthorization;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;

/** Host-only mapping, adapted from TextContextAuthority/ExecutionContext current checks. */
final readonly class ContextMap
{
    public function __construct(
        private HostAuthorization $authorization,
    ) {
    }

    public function scope(ExecutionContext $context, Scope $scope, PayloadHash $hostScopeHash): Scope
    {
        $context->assertCurrent($this->authorization);
        if ($context->identity->tenant->value !== $scope->tenantId->value
            || $context->identity->actorRef->value !== $scope->actorId->value
            || $context->purpose !== $scope->purpose
            || substr($hostScopeHash->value, strlen('sha-256:')) !== $scope->hostScopeHash->value) {
            throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'scope_mismatch');
        }

        return $scope;
    }

    public function usage(
        ExecutionContext $context,
        Scope $scope,
        PayloadHash $hostScopeHash,
        RunId $run,
        Ref $application,
    ): UsageContext {
        $trusted = $this->scope(context: $context, scope: $scope, hostScopeHash: $hostScopeHash);
        $context->identity->tenant->assertSame($run->tenant);
        $run->tenant->assertSame($application->tenant);

        return new UsageContext(
            scope: $trusted,
            applicationRef: $application,
            runRef: $run,
            correlationId: $context->correlation->traceId,
        );
    }
}
