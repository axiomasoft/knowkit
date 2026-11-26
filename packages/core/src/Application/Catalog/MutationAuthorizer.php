<?php
declare(strict_types=1);

namespace KnowKit\Application\Catalog;

use KnowKit\Domain\{DecisionReceipt, DomainFailure, Operation, Outcome, ResourceRef, Scope, Sha256};
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Port\{AccessPolicy, Clock};

/** Single-use owner receipts prepared BEFORE SQL guards, checked against authoritative epochs inside. */
final class MutationAuthorizer
{
    /** @var array<string, DecisionReceipt> */
    private array $prepared = [];

    public function __construct(
        private readonly AccessPolicy $owner,
        private readonly Clock $clock,
    ) {
    }

    private function key(Scope $scope, ResourceRef $resource, Operation $operation): string
    {
        return Jcs::canonicalize(
            $scope->toWire()
                ->with('resource', $resource->toWire())
                ->with('operation', $operation->value),
        );
    }

    public function prepare(Scope $scope, ResourceRef $resource, Operation $operation): void
    {
        $receipt = $this->owner->decide($scope, $resource, $operation);
        // Replace any previous projection/receipt with this current authoritative answer.
        $this->prepared[$this->key($scope, $resource, $operation)] = $receipt;
    }

    public function requireAllow(Scope $scope, ResourceRef $resource, Operation $operation, JsonObject $binding): void
    {
        $key = $this->key($scope, $resource, $operation);
        $receipt = $this->prepared[$key] ?? null;
        unset($this->prepared[$key]);
        if ($receipt === null) {
            throw new DomainFailure(Outcome::Unknown, 'policy_unknown');
        }
        $receipt->requireAllow(
            $scope,
            $resource,
            $operation,
            $this->clock->now(),
            new Sha256(HashDomain::PolicyBinding->digest($binding)),
        );
    }
}
