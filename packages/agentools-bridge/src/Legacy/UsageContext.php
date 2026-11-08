<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RunId;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Scope;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\ValueGuard;

/** Host metadata; only runRef belongs in the existing closed DisclosureRequest usageContext. */
final readonly class UsageContext
{
    public function __construct(
        public Scope $scope,
        public Ref $applicationRef,
        public RunId $runRef,
        public string $correlationId,
    ) {
        $runRef->tenant->assertSame($applicationRef->tenant);
        $scope->assertTenant(new TenantId($runRef->tenant->value));
        ValueGuard::text(value: $correlationId, max: 128);
    }

    public function disclosureWire(): JsonObject
    {
        return new JsonObject(['runRef' => $this->runRef->value]);
    }
}
