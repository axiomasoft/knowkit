<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class CreateKB
{
    public function __construct(public KbId $kbId, public string $name, public string $policyRef,
        public ProfileId $defaultRetrievalProfile, public CoveragePolicy $coveragePolicy)
    {
        ValueGuard::text($name, 128); ValueGuard::text($policyRef);
    }

    public function requestDigest(Scope $access, MutationContext $context): Fingerprint
    {
        if (!$access->kbId->equals($this->kbId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
        return $context->requestDigest(Operation::CatalogCreate, new JsonObject([
            'tenantId'=>$access->tenantId->value, 'kbId'=>$this->kbId->value, 'name'=>$this->name,
            'policyRef'=>$this->policyRef, 'defaultRetrievalProfile'=>$this->defaultRetrievalProfile->value,
            'coveragePolicy'=>new JsonObject(['allowPartial'=>$this->coveragePolicy->allowPartial,
                'requiredFeatures'=>$this->coveragePolicy->requiredFeatures]),
        ]));
    }
}
