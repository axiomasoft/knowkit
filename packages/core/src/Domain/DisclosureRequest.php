<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
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
        $wire = $this->toWire();
        $check = ScopeBinding::checkDisclosure($wire, $record->selection->toWire());
        if ($check['outcome'] !== 'success') {
            throw new DomainFailure(Outcome::Denied, $check['code'] ?? 'scope_mismatch');
        }
        $candidates = [];
        foreach ($record->selection->candidateRefs() as $ref) {
            // First candidate wins, matching the previous nested scan.
            $candidates[Jcs::canonicalize($ref->toWire())] ??= $ref;
        }
        $refs = [];
        foreach ($wire->get('chunkRefs') as $requested) {
            $ref = $requested instanceof JsonObject ? ($candidates[Jcs::canonicalize($requested)] ?? null) : null;
            if (!$ref instanceof ChunkRef) {
                throw new DomainFailure(Outcome::Denied, 'selection_ref_mismatch');
            }
            $refs[] = $ref;
        }

        return $refs;
    }
}
