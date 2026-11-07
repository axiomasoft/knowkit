<?php
declare(strict_types=1);

namespace KnowKit\Tests\Security\Policy;

use KnowKit\Application\Access as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;

final class Host implements A\HostIdentity
{
    public function __construct(public ?PolicyContext $identity) {}
    public function current(): ?PolicyContext { return $this->identity; }
}

final class Clock implements P\Clock
{
    public function __construct(public string $value = '2026-11-06T12:00:00Z') {}
    public function now(): D\UtcInstant { return new D\UtcInstant($this->value); }
}

/** Test-only owner double. No production StaticAllowList or second authoritative ACL engine. */
final class StaticAllowList implements P\AccessPolicy, P\EgressPolicy
{
    public D\Decision $decision = D\Decision::Deny;
    public D\Decision $egressDecision = D\Decision::Deny;
    public array $rules = [];
    public int $calls = 0;
    public int $egressCalls = 0;
    public bool $fail = false;
    public ?D\DecisionReceipt $cachedReceipt = null;
    public ?\Closure $beforeCall = null;
    public function __construct(public JsonObject $binding, private P\Clock $clock) {}

    private function before(): void
    {
        if ($this->beforeCall !== null) { ($this->beforeCall)(); }
        $this->calls++;
        if ($this->fail) { throw new \RuntimeException('synthetic raw owner input must never escape'); }
    }
    private function decision(D\Scope $scope, D\ResourceRef $ref, D\Operation $operation): D\Decision
    {
        return $this->rules[$scope->purpose.':'.$operation->value.':'.$ref->kind.':'.$ref->id->value]
            ?? $this->rules[$ref->kind.':'.$ref->id->value] ?? $this->rules['purpose:'.$scope->purpose] ?? $this->decision;
    }
    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $this->before();
        return $this->cachedReceipt ?? (new A\ReceiptFactory(PortFixture::validator(),$this->clock))->issue(
            $this->decision($scope,$resource,$operation),$scope,[$resource],$operation,$this->binding,'owner:revision-1');
    }
    public function authorizeQuery(D\Scope $scope): D\QueryGrant
    {
        $this->before();
        $source = PolicyFixture::source();
        $allowed = $this->decision($scope,new D\ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId),D\Operation::Retrieve) === D\Decision::Allow
            && $this->decision($scope,new D\ResourceRef($scope->tenantId,'collection',new D\CollectionId(PortFixture::id(200))),D\Operation::Retrieve) === D\Decision::Allow
            && $this->decision($scope,new D\ResourceRef($scope->tenantId,'source',$source->sourceId),D\Operation::Retrieve) === D\Decision::Allow;
        return new D\QueryGrant(new JsonObject(['allowedCollectionIds'=>$allowed?[PortFixture::id(200)]:[],
            'allowedSourceVersionIds'=>$allowed?[$source->sourceVersionId->value]:[], 'grantEpoch'=>$scope->policyEpoch->value,
            'policyBindingDigest'=>HashDomain::PolicyBinding->digest($this->binding),
            'expiresAt'=>$this->clock->now()->plusSeconds(5)->value]),PortFixture::validator());
    }
    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        $this->before(); $this->egressCalls++; $refs = [];
        foreach ($request->toWire()->get('items') as $item) {
            foreach ($item->get('sourceVersionRefs') as $ref) {
                $refs[$ref->get('sourceVersionId')] = new D\ResourceRef(new D\TenantId($ref->get('tenantId')),'source_version',new D\SourceVersionId($ref->get('sourceVersionId')));
            }
        }
        if ($refs === []) { $refs[] = new D\ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId); }
        return (new A\ReceiptFactory(PortFixture::validator(),$this->clock))->issue($this->egressDecision,$scope,array_values($refs),
            D\Operation::from($request->toWire()->get('operation')),$this->binding,'owner:egress-1');
    }
}

final class PolicyFixture
{
    public static function root(): string { return dirname(__DIR__,3); }
    public static function scope(string $purpose = 'lesson.read', string $epoch = '42', int $tenant = 1, int $actor = 101, string $hash = 'a'): D\Scope
    {
        return new D\Scope(new D\TenantId(PortFixture::id($tenant)),new D\KbId(PortFixture::id(100)),new D\ActorId(PortFixture::id($actor)),
            $purpose,new D\Decimal($epoch),new D\Sha256(str_repeat($hash,64)));
    }
    public static function host(?D\Scope $scope = null, string $audience = 'students'): Host
    {
        return new Host(new PolicyContext($scope ?? self::scope(),$audience,['read'],['provider:test']));
    }
    public static function binding(?D\Scope $scope = null): JsonObject { return PortFixture::binding($scope ?? self::scope()); }
    public static function source(): D\SourceVersionRef { return PortFixture::source(); }
    public static function resources(): array
    {
        $scope = self::scope();
        return [new D\ResourceRef($scope->tenantId,'collection',new D\CollectionId(PortFixture::id(200))),
            new D\ResourceRef($scope->tenantId,'source',self::source()->sourceId),
            new D\ResourceRef($scope->tenantId,'source_version',self::source()->sourceVersionId)];
    }
    public static function owner(P\Clock $clock, ?JsonObject $binding = null): StaticAllowList
    {
        $owner = new StaticAllowList($binding ?? self::binding(),$clock);
        $owner->decision = $owner->egressDecision = D\Decision::Allow;
        return $owner;
    }
    public static function request(): D\EgressRequest { return PortFixture::egress(true)->request; }
    public static function cases(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/v1/cases.json'),true,512,JSON_THROW_ON_ERROR);
    }
}
