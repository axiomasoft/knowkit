<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\ResourceId;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TenantId;

final readonly class InvocationPayloadRef
{
    public function __construct(
        public TenantId $tenantId,
        public ResourceId $resourceId,
        public Sha256 $digest,
    ) {
    }
}
