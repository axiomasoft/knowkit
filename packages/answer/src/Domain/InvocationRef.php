<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\TenantId;

final readonly class InvocationRef
{
    public function __construct(
        public TenantId $tenantId,
        public InvocationKey $key,
    ) {
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['tenantId' => $this->tenantId->value, 'invocationKey' => $this->key->value]);
    }
}
