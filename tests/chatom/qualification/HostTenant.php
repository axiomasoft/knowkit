<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Spaces\Contracts\TenantContext;
use Chatom\Spaces\Data\TenantKey;
use KnowKit\Tests\Answer\Contracts\Fixture;

final class HostTenant implements TenantContext
{
    public function current(): TenantKey
    {
        return TenantKey::from(Fixture::scope()->tenantId->value);
    }
}
