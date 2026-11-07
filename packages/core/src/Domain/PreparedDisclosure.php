<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\ScopeBinding;

/** Fully bounded response and its text-free lineage, ready before COMMIT. */
final readonly class PreparedDisclosure
{
    public function __construct(
        public EvidenceSet $evidence,
        public Usage $usage,
        public DomainEvent $event,
        public ?DisclosureDeadline $deadline = null,
    ) {
        $evidence->requireUsage($usage);
    }

    public function requireRequest(SelectionRecord $record, DisclosureRequest $request, UtcInstant $now): void
    {
        if ($this->usage->selectionId() != $record->selection->id()
            || $this->usage->attemptId() != $request->attemptId() || $this->usage->runRef() !== $request->runRef()
            || $this->usage->checkedAt()->compare($now) !== 0
            || $this->usage->policyBindingDigest()->value !== ScopeBinding::policyBindingDigest($record->selection->policyBinding())) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $expected = array_map(static fn (ChunkRef $ref): JsonObject => $ref->toWire(), $request->refs($record));
        $actual = array_map(static fn (JsonObject $fragment): mixed => $fragment->get('chunkRef'), $this->usage->toWire()->get('fragmentRefs'));
        if ($actual != $expected) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }

    public function __debugInfo(): array
    {
        return ['usageRef' => $this->usage->id()];
    }
}
