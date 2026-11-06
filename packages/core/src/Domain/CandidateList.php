<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class CandidateList
{
    /** @param list<Candidate> $candidates */
    public function __construct(public AuthorizedSearchScope $scope, public array $candidates)
    {
        ValueGuard::objects($candidates, Candidate::class);
        $seen = [];
        foreach ($candidates as $candidate) {
            $scope->assertCandidate($candidate->ref);
            $id = $candidate->ref->chunkId->value;
            if (isset($seen[$id])) { throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation'); }
            $seen[$id] = true;
        }
    }
}
