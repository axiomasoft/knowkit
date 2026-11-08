<?php

declare(strict_types=1);

namespace KnowKit\Answer\Port;

use KnowKit\Answer\Domain\Credential;
use KnowKit\Answer\Domain\ResolvedModelBinding;
use KnowKit\Domain\CredentialRef;
use KnowKit\Domain\Scope;

/** Revalidates the current credential against the pinned deployment; rotation cannot rebind it. */
interface CredentialResolver
{
    public function resolve(Scope $scope, CredentialRef $ref, ResolvedModelBinding $binding): ?Credential;
}
