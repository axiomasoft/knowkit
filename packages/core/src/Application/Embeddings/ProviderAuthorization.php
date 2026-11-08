<?php

declare(strict_types=1);

namespace KnowKit\Application\Embeddings;

use KnowKit\Domain\EmbeddingAccess;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Port\AccessPolicy;
use KnowKit\Port\Clock;
use KnowKit\Port\EgressPolicy;

final readonly class ProviderAuthorization
{
    public function __construct(
        private AccessPolicy $policy,
        private EgressPolicy $egress,
        private Clock $clock,
    ) {}

    public function processing(EmbeddingAccess $access): Sha256
    {
        return $this->decide($access, $access->operation());
    }

    public function egress(EmbeddingAccess $access, Sha256 $binding): void
    {
        $receipt = $this->egress->mayEgress($access->scope, $access->request);
        $operation = $access->operation();
        foreach ($access->egressResources() as $resource) {
            $receipt->requireAllow($access->scope, $resource, $operation, $this->clock->now(), $binding);
        }
    }

    public function acceptance(EmbeddingAccess $access, Sha256 $binding): void
    {
        $operation = $access->operation() === Operation::Retrieve
            ? Operation::Retrieve : Operation::Publish;
        $this->decide($access, $operation, $binding);
    }

    private function decide(EmbeddingAccess $access, Operation $operation, ?Sha256 $binding = null): Sha256
    {
        foreach ($access->resources() as $resource) {
            $receipt = $this->policy->decide($access->scope, $resource, $operation);
            $binding ??= new Sha256(HashDomain::PolicyBinding->digest($receipt->toWire()->get('binding')));
            $receipt->requireAllow($access->scope, $resource, $operation, $this->clock->now(), $binding);
        }

        return $binding;
    }

}
