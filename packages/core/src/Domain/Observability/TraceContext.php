<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Request/job-local correlation. No credentials, arbitrary labels or document identities. */
final readonly class TraceContext
{
    private function __construct(
        public D\Ulid $correlationId,
        private D\TenantId $tenant,
        public D\Sha256 $tenantRef,
        public ?D\Sha256 $runRef = null,
        public ?D\UsageId $usageRef = null,
        public ?D\JobId $jobId = null,
        public ?D\AttemptId $attemptId = null,
        public ?D\Decimal $fence = null,
    ) {}

    public static function forTenant(
        D\Ulid $correlationId,
        D\TenantId $tenant,
        #[\SensitiveParameter] string $redactionKey,
        #[\SensitiveParameter] ?string $runRef = null,
    ): self {
        if (strlen($redactionKey) < 32) {
            throw new \InvalidArgumentException('Host redaction key of at least 32 bytes required');
        }
        if ($runRef !== null) {
            D\ValueGuard::text(value: $runRef, max: 256);
        }

        return new self(
            correlationId: $correlationId,
            tenant: $tenant,
            tenantRef: new D\Sha256(hash_hmac('sha256', "tenant\0" . $tenant->value, $redactionKey)),
            runRef: $runRef === null ? null : new D\Sha256(hash_hmac('sha256', "run\0" . $tenant->value . "\0" . $runRef, $redactionKey)),
        );
    }

    public function assertTenant(D\TenantId $tenant): void
    {
        if (!$this->tenant->equals($tenant)) {
            throw new D\DomainFailure(outcome: D\Outcome::Denied, errorCode: 'scope_mismatch');
        }
    }

    public function withUsage(D\UsageId $usage): self
    {
        return new self(
            correlationId: $this->correlationId, tenant: $this->tenant, tenantRef: $this->tenantRef,
            runRef: $this->runRef, usageRef: $usage, jobId: $this->jobId,
            attemptId: $this->attemptId, fence: $this->fence,
        );
    }

    public function withLease(D\Lease $lease): self
    {
        $this->assertTenant($lease->scope->tenantId);

        return new self(
            correlationId: $this->correlationId, tenant: $this->tenant, tenantRef: $this->tenantRef,
            runRef: $this->runRef, usageRef: $this->usageRef, jobId: $lease->jobId,
            attemptId: $lease->attemptId, fence: $lease->fence,
        );
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'correlationId' => $this->correlationId->value, 'tenantRef' => $this->tenantRef->value,
            'runRef' => $this->runRef?->value, 'usageRef' => $this->usageRef?->value,
            'jobId' => $this->jobId?->value, 'attemptId' => $this->attemptId?->value,
            'fence' => $this->fence?->value,
        ]);
    }

    public function __debugInfo(): array
    {
        return iterator_to_array($this->toWire());
    }
}
