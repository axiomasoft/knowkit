<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\InstanceId;
use KnowKit\Domain\Scope;

/** Host credential/session integration. Return null for unauthenticated; never trust body claims. */
interface Authenticator
{
    public function authenticate(Request $request): ?HostIdentity;
}

final readonly class AuthenticatedContext implements HostIdentity
{
    public function __construct(
        public InstanceId $instanceId,
        public PolicyContext $policy,
    ) {}

    public function current(): PolicyContext
    {
        return $this->policy;
    }

    public function scope(): Scope
    {
        return $this->policy->scope;
    }
}
