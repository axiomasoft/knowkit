<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Transport lease capability; token is transient, only its SHA256 is persisted. */
final readonly class Lease
{
    public function __construct(public Scope $scope, public JobId $jobId, public AttemptId $attemptId,
        public SourceVersionRef $sourceRef, public ?GenerationId $generationId, public Decimal $fence,
        #[\SensitiveParameter] private string $token, public UtcInstant $leaseUntil,
        public UtcInstant $attemptDeadline, public Decimal $sourceEpoch)
    {
        $scope->assertTenant($sourceRef->tenantId);
        if (strlen($token) < 32 || $leaseUntil->compare($attemptDeadline) > 0) {
            throw new \InvalidArgumentException('Bounded lease capability required');
        }
    }
    public function tokenHash(): Sha256 { return new Sha256(hash('sha256', $this->token)); }
    public function isCurrent(self $presented, UtcInstant $now): bool
    {
        return $this->scope == $presented->scope && $this->jobId == $presented->jobId
            && $this->attemptId == $presented->attemptId && $this->sourceRef == $presented->sourceRef
            && $this->generationId == $presented->generationId && $this->sourceEpoch == $presented->sourceEpoch
            && $this->fence == $presented->fence && hash_equals($this->tokenHash()->value, $presented->tokenHash()->value)
            && $this->leaseUntil->compare($now) > 0 && $this->attemptDeadline->compare($now) > 0;
    }
    public function __debugInfo(): array { return ['jobId'=>$this->jobId,'fence'=>$this->fence,'leaseUntil'=>$this->leaseUntil]; }
}
