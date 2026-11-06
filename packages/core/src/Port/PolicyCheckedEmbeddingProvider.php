<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{DomainFailure, EmbeddingAccess, EmbeddingInput, EmbeddingBatch, Operation, Outcome, QueryInput, ResourceRef, Result, Sha256, SpaceRef, Vector};
use KnowKit\Domain\Contract\HashDomain;

/** Optional template for adapters: final entry points recheck policy on every actual transmission. */
abstract class PolicyCheckedEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private readonly AccessPolicy $policy, private readonly EgressPolicy $egress,
        private readonly Clock $clock) {}

    final public function embedDocuments(EmbeddingAccess $access, array $inputs, SpaceRef $space): Result
    {
        try {
            $access->assertDocuments($inputs, $space);
            $resources = array_map(static fn(EmbeddingInput $input): ResourceRef => new ResourceRef(
                $access->scope->tenantId, 'source_version', $input->ref->revisionRef->sourceRef->sourceVersionId), $inputs);
            $this->authorize($access, $resources);
            $result = $this->performDocuments($access, $inputs, $space);
            if ($result->outcome === Outcome::Success) {
                if (!$result->data instanceof EmbeddingBatch || count($result->data->vectors) !== count($inputs)) {
                    throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
                }
                $space->assertCompatible($result->data->space);
            }
            return $result;
        } catch (DomainFailure $failure) {
            return Result::failure($failure);
        }
    }

    final public function embedQuery(EmbeddingAccess $access, QueryInput $input, SpaceRef $space): Result
    {
        try {
            $access->assertQuery($input, $space);
            $this->authorize($access, [new ResourceRef($access->scope->tenantId, 'knowledge_base', $access->scope->kbId)]);
            $result = $this->performQuery($access, $input, $space);
            if ($result->outcome === Outcome::Success) {
                if (!$result->data instanceof Vector) { throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation'); }
                $space->assertCompatible($result->data->space);
            }
            return $result;
        } catch (DomainFailure $failure) {
            return Result::failure($failure);
        }
    }

    /** @param list<ResourceRef> $resources */
    private function authorize(EmbeddingAccess $access, array $resources): void
    {
        $operation = Operation::from($access->request->toWire()->get('operation'));
        $binding = null;
        foreach ($resources as $resource) {
            $receipt = $this->policy->decide($access->scope, $resource, $operation);
            $current = new Sha256(HashDomain::PolicyBinding->digest($receipt->toWire()->get('binding')));
            $receipt->requireAllow($access->scope, $resource, $operation, $this->clock->now(), $binding ?? $current);
            $binding = $current;
        }
        // Last external authorization call is immediately before the protected provider method.
        $egress = $this->egress->mayEgress($access->scope, $access->request);
        foreach ($resources as $resource) {
            $egress->requireAllow($access->scope, $resource, $operation, $this->clock->now(), $binding);
        }
    }

    /** @param list<EmbeddingInput> $inputs */
    abstract protected function performDocuments(EmbeddingAccess $access, array $inputs, SpaceRef $space): Result;
    abstract protected function performQuery(EmbeddingAccess $access, QueryInput $input, SpaceRef $space): Result;
}
