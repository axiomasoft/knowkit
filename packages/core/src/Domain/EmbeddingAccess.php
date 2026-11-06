<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Trusted per-call processing scope and COMPLETE outbound manifest; never an Allow receipt cache. */
final readonly class EmbeddingAccess
{
    public function __construct(public Scope $scope, public EgressRequest $request)
    {
        foreach ($request->toWire()->get('items') as $item) {
            foreach ($item->get('sourceVersionRefs') as $ref) {
                if ($ref->get('tenantId') !== $scope->tenantId->value) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
            }
        }
    }

    /** @param list<EmbeddingInput> $inputs */
    public function assertDocuments(array $inputs, SpaceRef $space): void
    {
        ValueGuard::objects($inputs, EmbeddingInput::class, 1024);
        $wire = $this->request->toWire();
        $this->assertDestination($space);
        if (!in_array($wire->get('operation'), ['ingest','reprocess'], true)
            || count($wire->get('items')) !== count($inputs)) {
            throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
        }
        foreach ($inputs as $i => $input) {
            $item = $wire->get('items')[$i];
            $ref = $input->ref->revisionRef->sourceRef;
            $this->scope->assertTenant($ref->tenantId);
            if ($item->get('category') !== 'DOCUMENT_CONTENT' || $item->get('contentDigest') !== $input->digest()->value
                || $item->get('sourceVersionRefs') != [$ref->toWire()]) {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
            }
        }
    }

    public function assertQuery(QueryInput $input, SpaceRef $space): void
    {
        $wire = $this->request->toWire(); $items = $wire->get('items');
        $this->assertDestination($space);
        if ($wire->get('operation') !== 'retrieve' || count($items) !== 1
            || $items[0]->get('category') !== 'QUERY' || $items[0]->get('contentDigest') !== $input->digest()->value
            || $items[0]->get('sourceVersionRefs') !== []) {
            throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
        }
    }

    private function assertDestination(SpaceRef $space): void
    {
        $destination = $this->request->toWire()->get('destination');
        if ($destination->get('providerId') !== $space->provider || $destination->get('modelId') !== $space->model) {
            throw new DomainFailure(Outcome::Incompatible, 'space_mismatch');
        }
    }
}
