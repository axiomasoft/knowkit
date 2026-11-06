<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class GenerationReceipt
{
    public function __construct(public TenantId $tenantId, public GenerationId $generationId, public JobId $jobId) {}
}
