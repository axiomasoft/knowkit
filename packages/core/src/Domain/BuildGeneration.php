<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class BuildGeneration
{
    /**
     * @param list<SourceVersionRef> $sourceRefs
     * @param list<ProcessingIdentity> $processingIdentities One complete processing identity per exact source.
     */
    public function __construct(public GenerationId $generationId, public array $sourceRefs, public array $processingIdentities)
    {
        ValueGuard::objects($sourceRefs, SourceVersionRef::class);
        ValueGuard::objects($processingIdentities, ProcessingIdentity::class);
        if (count($sourceRefs) !== count($processingIdentities)) { throw new \InvalidArgumentException('One identity per source required'); }
        $tenant = $sourceRefs[0]->tenantId ?? null;
        $guard = $tenant === null ? null : new ExactIdentityGuard($tenant);
        $seen = [];
        foreach ($sourceRefs as $i => $ref) {
            if ($tenant !== null && !$tenant->equals($ref->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
            $guard?->remember($ref);
            $processing = $processingIdentities[$i];
            if (isset($seen[$ref->sourceVersionId->value]) || !$ref->sourceVersionId->equals($processing->sourceVersionId)
                || !$ref->sourceDigest->equals($processing->sourceDigest)) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
            $seen[$ref->sourceVersionId->value] = true;
        }
    }
}
