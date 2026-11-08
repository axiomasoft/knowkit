<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\ScopeBinding;

/** Answer-side typed view; a historical EvidenceSet is never a current grant. */
final readonly class GroundingEvidence
{
    /** @var list<GroundingFragment> */
    public array $fragments;

    public function __construct(
        public D\EvidenceSet $set,
        public D\Selection $selection,
        D\Scope $scope,
        ContractValidator $validator,
    ) {
        $wire = $set->toWire();
        $selected = $selection->toWire();
        $binding = ScopeBinding::checkSelection($scope->toWire(), $selected);
        if ($binding['outcome'] !== 'success'
            || $wire->get('tenantId') !== $scope->tenantId->value
            || $wire->get('hostScopeHash') !== $scope->hostScopeHash->value
            || $wire->get('selectionId') !== $selection->id()->value
            || $wire->get('scopeBindingDigest') !== $selected->get('scopeBindingDigest')
            || $wire->get('snapshotRef')->get('kbId') !== $scope->kbId->value
            || $wire->get('snapshotRef')->get('snapshotId') !== $selected->get('snapshotId')
            || $set->checkedAt() === null) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        $fragments = [];
        foreach ($wire->get('fragments') as $fragment) {
            $citation = new D\CitationRef($fragment->get('citation'), $validator);
            $this->requireSelected($citation);
            $fragments[] = new GroundingFragment($citation, $fragment->get('text'), $fragment->get('joiner'), $fragment->get('provenanceKind'));
        }
        $this->fragments = $fragments;
    }

    public function policyBindingDigest(): D\Sha256
    {
        return new D\Sha256(HashDomain::PolicyBinding->digest($this->selection->policyBinding()));
    }

    public function disclosureAttemptId(): D\Ulid
    {
        return new D\Ulid($this->set->toWire()->get('disclosureAttemptId'));
    }

    /** @return list<D\CitationRef> */
    public function citations(): array
    {
        return array_map(static fn (GroundingFragment $fragment): D\CitationRef => $fragment->citation, $this->fragments);
    }

    public function text(): string
    {
        return implode('', array_map(static fn (GroundingFragment $fragment): string => $fragment->text, $this->fragments));
    }

    private function requireSelected(D\CitationRef $citation): void
    {
        foreach ($this->selection->candidateRefs() as $ref) {
            try {
                $citation->requireChunk($ref);

                return;
            } catch (D\DomainFailure) {
                // No citation may introduce an unselected source/version/revision/digest.
            }
        }
        throw new D\DomainFailure(D\Outcome::Incompatible, 'evidence_selection_mismatch');
    }
}
