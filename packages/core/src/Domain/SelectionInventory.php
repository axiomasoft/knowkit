<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Exact snapshot inventory supplied by the authoritative catalog, never by a model/tool. */
final readonly class SelectionInventory
{
    /** @var array<string, SelectionUnit> */
    private array $units;

    /** @param list<SelectionUnit> $units */
    public function __construct(
        public SnapshotRef $snapshot,
        public ManifestCandidate $manifest,
        array $units,
    ) {
        $wire = $manifest->manifest->toWire();
        if ($wire->get('tenantId') !== $snapshot->tenantId->value || $wire->get('kbId') !== $snapshot->kbId->value) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $byId = [];
        foreach ($units as $unit) {
            $manifest->assertCandidate($unit->chunk->ref);
            $id = $unit->chunk->ref->chunkId->value;
            if (isset($byId[$id])) {
                throw new DomainFailure(Outcome::Incompatible, 'duplicate_identifier');
            }
            $byId[$id] = $unit;
        }
        if (count($byId) !== $wire->get('counts')->get('chunks')) {
            throw new DomainFailure(Outcome::Incompatible, 'manifest_mismatch');
        }
        $this->units = $byId;
    }

    public function generationId(): GenerationId
    {
        return new GenerationId($this->manifest->manifest->toWire()->get('generationId'));
    }

    public function unit(ChunkRef $ref): SelectionUnit
    {
        $this->manifest->assertCandidate($ref);

        return $this->units[$ref->chunkId->value];
    }

    public function assertSearch(AuthorizedSearchScope $scope, QueryBinding $query): void
    {
        if (!$scope->tenantId->equals($this->snapshot->tenantId)
            || !$scope->kbId->equals($this->snapshot->kbId)
            || !$scope->snapshotId->equals($this->snapshot->snapshotId)
            || !$scope->generationId->equals($this->generationId())) {
            throw new DomainFailure(Outcome::Incompatible, 'snapshot_mismatch');
        }
        $space = $this->manifest->manifest->toWire()->get('embeddingSpaceDigest');
        if ($space !== $query->spaceFingerprint?->value || $scope->embeddingSpaceDigest?->value !== $space) {
            throw new DomainFailure(Outcome::Incompatible, 'space_mismatch');
        }
    }
}
