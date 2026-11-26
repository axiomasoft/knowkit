<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;

final readonly class EvidenceSet
{
    private EvidencePayload $value;

    public function __construct(
        JsonObject $value,
        ContractValidator $validator,
        private ?Usage $usage = null,
    ) {
        $this->value = new EvidencePayload($value, $validator);
        if ($usage !== null) {
            $this->requireUsage($usage);
        }
    }

    /** Runtime metadata complements the frozen v1 wire contract; no schema change. */
    public static function prepared(
        JsonObject $wire,
        ContractValidator $validator,
        Usage $usage,
    ): self {
        return new self($wire, $validator, $usage);
    }

    public function requireUsage(Usage $usage): void
    {
        foreach (['usageId', 'tenantId', 'selectionId', 'disclosureAttemptId', 'hostScopeHash'] as $key) {
            if ($usage->toWire()->get($key) !== $this->toWire()->get($key)) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
        }
        $refs = $usage->toWire()->get('fragmentRefs');
        $fragments = $this->toWire()->get('fragments');
        if (count($refs) !== count($fragments)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        foreach ($fragments as $index => $fragment) {
            $ref = $refs[$index]->get('chunkRef');
            $citation = $fragment->get('citation');
            foreach (['tenantId', 'sourceId', 'sourceVersionId', 'sourceDigest', 'documentRevisionId', 'irDigest'] as $key) {
                if ($ref->get($key) !== $citation->get($key)) {
                    throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
                }
            }
            if ($refs[$index]->get('fragmentDigest') !== $fragment->get('fragmentDigest')
                || $ref->get('chunkDigest') !== $fragment->get('fragmentDigest')) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
        }
    }

    public function usageRef(): UsageId
    {
        return new UsageId($this->toWire()->get('usageId'));
    }

    public function digest(): Sha256
    {
        return new Sha256($this->toWire()->get('evidenceSetDigest'));
    }

    public function checkedAt(): ?UtcInstant
    {
        return $this->usage?->checkedAt();
    }

    public function trustClass(): string
    {
        return 'untrusted';
    }

    public function memoryActivation(): bool
    {
        return false;
    }

    /** @return list<EvidenceRepresentation> Search text is labelled separately from canonical quotes. */
    public function searchRepresentations(): array
    {
        return array_values(array_map(
            static fn (JsonObject $fragment): EvidenceRepresentation => new EvidenceRepresentation($fragment),
            $this->toWire()->get('fragments'),
        ));
    }

    public function toWire(): JsonObject
    {
        return $this->value->toWire();
    }

    public function canonical(): string
    {
        return $this->value->canonical();
    }

    public function __debugInfo(): array
    {
        return ['usageRef' => $this->usageRef(), 'trustClass' => $this->trustClass()];
    }
}
