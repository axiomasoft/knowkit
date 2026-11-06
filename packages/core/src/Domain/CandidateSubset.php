<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class CandidateSubset
{
    /** @param list<Candidate> $candidates */
    public function __construct(public CandidateList $input, public array $candidates)
    {
        ValueGuard::objects($candidates, Candidate::class);
        $known = [];
        foreach ($input->candidates as $candidate) { $known[$candidate->ref->chunkId->value] = $candidate->ref; }
        $seen = [];
        foreach ($candidates as $candidate) {
            $id = $candidate->ref->chunkId->value;
            if (isset($seen[$id]) || !isset($known[$id]) || $known[$id] != $candidate->ref) {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
            }
            $seen[$id] = true;
        }
    }
}
