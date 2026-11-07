<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{Decision, DecisionReceipt, EgressRequest, Operation, QueryGrant, ResourceRef, Scope, SourceVersionId};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Port\{AccessPolicy, EgressPolicy};

/** Safe built-in default. This policy never infers rights from membership or eligibility. */
final readonly class DenyAll implements AccessPolicy, EgressPolicy
{
    public function __construct(private ReceiptFactory $receipts) {}

    private function binding(Scope $scope): JsonObject
    {
        return new JsonObject(['tenantPolicyEpoch'=>'0', 'kbPolicyEpoch'=>$scope->policyEpoch->value, 'sources'=>[]]);
    }

    public function authorizeQuery(Scope $scope): QueryGrant
    {
        return new QueryGrant(new JsonObject(['allowedCollectionIds'=>[], 'allowedSourceVersionIds'=>[],
            'grantEpoch'=>$scope->policyEpoch->value, 'policyBindingDigest'=>HashDomain::PolicyBinding->digest($this->binding($scope)),
            'expiresAt'=>$this->receipts->clock->now()->plusSeconds(5)->value]), $this->receipts->validator);
    }

    public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt
    {
        return $this->receipts->issue(Decision::Deny, $scope, [$resource], $operation, $this->binding($scope), 'builtin:deny-all:v1');
    }

    public function mayEgress(Scope $scope, EgressRequest $request): DecisionReceipt
    {
        $resources = [];
        foreach ($request->toWire()->get('items') as $item) {
            foreach ($item->get('sourceVersionRefs') as $ref) {
                $scope->assertTenant(new \KnowKit\Domain\TenantId($ref->get('tenantId')));
                $resources[$ref->get('sourceVersionId')] = new ResourceRef($scope->tenantId, 'source_version', new SourceVersionId($ref->get('sourceVersionId')));
            }
        }
        if ($resources === []) { $resources[] = new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId); }
        return $this->receipts->issue(Decision::Deny, $scope, array_values($resources),
            Operation::from($request->toWire()->get('operation')), $this->binding($scope), 'builtin:deny-all:v1');
    }
}
