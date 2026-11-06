<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Scoped cursor: opaque pagination never creates tenant authority. */
final readonly class Cursor
{
    public function __construct(public TenantId $tenantId, public ?string $token = null)
    {
        if ($token !== null) { ValueGuard::text($token, 1024); }
    }
}
