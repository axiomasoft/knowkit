<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class JobCommand
{
    public function __construct(public Scope $scope, public SourceVersionRef $sourceRef, public ?GenerationId $generationId,
        public JobType $type, public ProcessingIdentity $processingIdentity, public UtcInstant $availableAt)
    {
        $scope->assertTenant($sourceRef->tenantId);
        if (!$sourceRef->sourceVersionId->equals($processingIdentity->sourceVersionId)
            || !$sourceRef->sourceDigest->equals($processingIdentity->sourceDigest)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
