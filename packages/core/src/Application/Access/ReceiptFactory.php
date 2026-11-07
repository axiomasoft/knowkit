<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{Decision, DecisionReceipt, Operation, ResourceRef, Scope};
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject};
use KnowKit\Port\Clock;

/** Local policies only. External receipts retain the owner's original revision and times. */
final readonly class ReceiptFactory
{
    public function __construct(public ContractValidator $validator, public Clock $clock) {}

    /** @param non-empty-list<ResourceRef> $resources */
    public function issue(Decision $decision, Scope $scope, array $resources, Operation $operation,
        JsonObject $binding, string $revision): DecisionReceipt
    {
        foreach ($resources as $resource) { $scope->assertTenant($resource->tenantId); }
        $now = $this->clock->now();
        $wire = new JsonObject(['decision'=>$decision->value, 'operation'=>$operation->value,
            'resourceRefs'=>array_map(static fn(ResourceRef $r): JsonObject => $r->toWire(), $resources),
            'binding'=>$binding, 'ownerRevision'=>$revision, 'checkedAt'=>$now->value,
            'expiresAt'=>$now->plusSeconds(5)->value, 'hostScopeHash'=>$scope->hostScopeHash->value]);
        return new DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), $this->validator);
    }
}
