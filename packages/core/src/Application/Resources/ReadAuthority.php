<?php
declare(strict_types=1);
namespace KnowKit\Application\Resources;

use KnowKit\Domain\{BlobRef,Operation,ResourceRef,Scope,Sha256};
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Port\{AccessPolicy,Clock};

/** Bound host scope; every buffer consumes a fresh current-owner answer, never a cached allow. */
final readonly class ReadAuthority
{
    public function __construct(private Scope $scope,private AccessPolicy $owner,private Clock $clock,
        private \Closure $currentBinding) {}
    public function requireAllow(BlobRef $ref): void
    {
        $this->scope->assertTenant($ref->tenantId);
        $resource=new ResourceRef($ref->tenantId,'source',$ref->sourceId);
        $receipt=$this->owner->decide($this->scope,$resource,Operation::Disclose);
        $binding=($this->currentBinding)($this->scope,$ref);
        $receipt->requireAllow($this->scope,$resource,Operation::Disclose,$this->clock->now(),
            new Sha256(HashDomain::PolicyBinding->digest($binding)));
    }
}
