<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Identity and storage/restore serving gate; ready never constitutes an access grant. */
final readonly class Tenant
{
    public function __construct(public TenantId $tenantId, public string $servingState = 'blocked')
    {
        if (!in_array($servingState, ['blocked', 'ready'], true)) { throw new \InvalidArgumentException('Serving state required'); }
    }
}
