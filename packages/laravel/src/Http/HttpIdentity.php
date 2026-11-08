<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use KnowKit\Application\Access\HostIdentity;

/** The host resolves current policy from its authenticated principal, never request data. */
interface HttpIdentity
{
    public function forUser(Authenticatable $user): HostIdentity;
}
