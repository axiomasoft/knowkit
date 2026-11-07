<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\ScopeBinding;

final readonly class DisclosureRequest extends SchemaValue
{
    protected const SCHEMA = 'disclosure-request';

    public function attemptId(): Ulid
    {
        return new Ulid($this->toWire()->get('disclosureAttemptId'));
    }

    public function selectionId(): SelectionId
    {
        return new SelectionId($this->toWire()->get('selectionId'));
    }

    public function runRef(): ?string
    {
        return $this->toWire()->get('usageContext')?->get('runRef');
    }

    /** @return list<ChunkRef> */
    public function refs(SelectionRecord $record): array
    {
        $check = ScopeBinding::checkDisclosure($this->toWire(), $record->selection->toWire());
        if ($check['outcome'] !== 'success') {
            throw new DomainFailure(Outcome::Denied, $check['code']);
        }
        $refs = [];
        foreach ($this->toWire()->get('chunkRefs') as $wire) {
            foreach ($record->selection->candidateRefs() as $ref) {
                if ($wire == $ref->toWire()) {
                    $refs[] = $ref;
                    break;
                }
            }
        }

        return $refs;
    }
}
