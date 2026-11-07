<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\ScopeBinding;

/** Private bounded canonical buffer. It cannot be returned as authorization or logged. */
final readonly class DisclosureBuffer
{
    private FrozenJson $body;

    /** @param list<JsonObject> $fragments @param list<ChunkRef> $refs */
    public function __construct(
        array $fragments,
        public array $refs,
        public int $totalBytes,
    ) {
        if ($fragments === [] || count($fragments) !== count($refs) || $totalBytes > 262144) {
            throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
        }
        $this->body = new FrozenJson(new JsonObject(['fragments' => $fragments]));
    }

    public function prepare(
        SelectionRecord $record,
        DisclosureRequest $request,
        UsageId $usageId,
        UtcInstant $now,
        ContractValidator $validator,
        ?DisclosureDeadline $deadline = null,
    ): PreparedDisclosure {
        $scope = $record->context->scope;
        $fragments = $this->body->toWire()->get('fragments');
        $usage = $this->usage($record, $request, $usageId, $now, $validator);
        $wire = new JsonObject([
            'tenantId' => $scope->tenantId->value,
            'selectionId' => $record->selection->id()->value,
            'disclosureAttemptId' => $request->attemptId()->value,
            'usageId' => $usageId->value,
            'hostScopeHash' => $scope->hostScopeHash->value,
            'scopeBindingDigest' => $record->selection->scopeBindingDigest()->value,
            'snapshotRef' => $record->snapshot->toWire(),
            'fragments' => $fragments,
            'totalBytes' => $this->totalBytes,
        ]);
        $evidence = EvidenceSet::prepared(
            $wire->with('evidenceSetDigest', HashDomain::EvidenceSet->digest($wire)), $validator, $usage,
        );

        return new PreparedDisclosure($evidence, $usage, $this->event($usage, $validator), $deadline);
    }

    private function event(Usage $usage, ContractValidator $validator): DomainEvent
    {
        $wire = $usage->toWire();
        $payload = $wire->without('usageId')->without('tenantId')->without('status')->with('usageId', $usage->id()->value);

        return new DomainEvent(new JsonObject([
            'eventId' => $usage->id()->value,
            'schemaVersion' => 'event-1.0',
            'tenantId' => $wire->get('tenantId'),
            'aggregateType' => 'usage',
            'aggregateId' => $usage->id()->value,
            'aggregateVersion' => '1',
            'type' => 'usage.prepared_disclosure',
            'occurredAt' => $usage->checkedAt()->value,
            'causationId' => null,
            'correlationId' => $wire->get('disclosureAttemptId'),
            'payload' => $payload,
            'payloadDigest' => HashDomain::DomainEventPayload->digest($payload),
        ]), $validator);
    }

    private function usage(
        SelectionRecord $record,
        DisclosureRequest $request,
        UsageId $id,
        UtcInstant $now,
        ContractValidator $validator,
    ): Usage {
        $scope = $record->context->scope;

        return new Usage(new JsonObject([
            'usageId' => $id->value,
            'tenantId' => $scope->tenantId->value,
            'disclosureAttemptId' => $request->attemptId()->value,
            'selectionId' => $record->selection->id()->value,
            'actorId' => $scope->actorId->value,
            'purpose' => $scope->purpose,
            'hostScopeHash' => $scope->hostScopeHash->value,
            'runRef' => $request->runRef(),
            'checkedAt' => $now->value,
            'policyBindingDigest' => ScopeBinding::policyBindingDigest($record->selection->policyBinding()),
            'status' => 'prepared_disclosure',
            'fragmentRefs' => array_map(static fn (ChunkRef $ref): JsonObject => new JsonObject([
                'chunkRef' => $ref->toWire(), 'fragmentDigest' => $ref->chunkDigest->value,
            ]), $this->refs),
        ]), $validator);
    }

    public function __debugInfo(): array
    {
        return ['totalBytes' => $this->totalBytes];
    }
}
