<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Domain;

use KnowKit\Domain as D;

/** Host accepts provider results before entering a catalog transaction. No provider I/O here. */
final readonly class DocumentVectors
{
    /**
     * @param array<string, D\Vector> $vectors Exact chunk IDs, never positional provider IDs.
     * @param array<string, D\ChunkRef> $references Immutable input bindings accepted by the host provider pipeline.
     */
    public function __construct(
        private D\SpaceRef $space,
        private string $role,
        private array $vectors,
        private array $references,
    ) {
        VectorPayload::assertSpace($space);
        if ($role !== $space->documentRole) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'embedding_role_mismatch');
        }
        foreach ($vectors as $id => $vector) {
            new D\ChunkId($id);
            if (!isset($references[$id]) || $references[$id]->chunkId->value !== $id) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_input_binding_missing');
            }
            $space->assertCompatible($vector->space);
            new VectorPayload($vector);
        }
    }

    /** @return array<string, VectorPayload> */
    public function forSet(D\ChunkSet $set, D\EmbeddingSpace $space): array
    {
        $this->space->assertCompatible($space);
        $payloads = [];
        foreach ($set->chunks as $chunk) {
            $id = $chunk->ref->chunkId->value;
            $vector = $this->vectors[$id] ?? null;
            if ($vector === null) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'missing_vector');
            }
            if ($this->references[$id] != $chunk->ref) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
            }
            $payloads[$id] = new VectorPayload($vector);
        }

        return $payloads;
    }

    public function assertInventory(ManifestView $manifest): void
    {
        foreach (array_keys($this->vectors) as $id) {
            $ref = $this->references[$id];
            if (!$manifest->tenant->equals($ref->revisionRef->sourceRef->tenantId)) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }
            $manifest->candidate->assertCandidate($ref);
        }
    }
}
