<?php
declare(strict_types=1);

namespace KnowKit\Tests\Storage\Catalog;

use KnowKit\Adapter\Postgres\Catalog\{Connection,Migrate,PdoCatalogRepository,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Application\Catalog\{CatalogCommands,MutationAuthorizer};
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain,JsonObject,StrictJson};
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Tooling\DatabaseSandbox;

final class TestClock implements P\Clock
{
    public function now(): D\UtcInstant { return new D\UtcInstant('2026-11-06T12:00:01Z'); }
}
final class TestIds implements P\IdGenerator
{
    public function __construct(private int $next=10000) {}
    public function next(): D\Ulid { return new D\Ulid(CatalogFixture::id($this->next++)); }
}
final class CurrentOwner implements P\AccessPolicy
{
    public D\Decision $decision=D\Decision::Allow;
    public bool $staleLocalAllow=true;
    public int $calls=0;
    public array $decisions=[];
    public function __construct(private Connection $db) {}
    public function authorizeQuery(D\Scope $scope): D\QueryGrant { throw new \LogicException('Not a query authority fake'); }
    public function decide(D\Scope $scope,D\ResourceRef $resource,D\Operation $operation): D\DecisionReceipt
    {
        if ($this->db->pdo->inTransaction()) { throw new \LogicException('Policy callback inside transaction'); }
        $this->calls++;
        $t=$this->db->row('SELECT tenant_policy_epoch FROM '.$this->db->table('tenants').' WHERE id=?',[$scope->tenantId->value]);
        $k=$this->db->row('SELECT kb_policy_epoch FROM '.$this->db->table('knowledge_bases').' WHERE tenant_id=? AND id=?',[$scope->tenantId->value,$scope->kbId->value]);
        $sources=[];
        if ($resource->kind==='source') {
            $s=$this->db->row('SELECT lifecycle_epoch,source_policy_epoch FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?',[$scope->tenantId->value,$resource->id->value]);
            if ($s!==null) { $sources[]=new JsonObject(['sourceId'=>$resource->id->value,'lifecycleEpoch'=>(string)$s['lifecycle_epoch'],'sourcePolicyEpoch'=>(string)$s['source_policy_epoch']]); }
        }
        $decision=$this->decisions[$resource->kind.':'.$resource->id->value]??$this->decision;
        $wire=new JsonObject(['decision'=>$decision->value,'operation'=>$operation->value,'resourceRefs'=>[$resource->toWire()],
            'binding'=>new JsonObject(['tenantPolicyEpoch'=>(string)($t['tenant_policy_epoch']??0),'kbPolicyEpoch'=>(string)($k['kb_policy_epoch']??0),'sources'=>$sources]),
            'ownerRevision'=>'owner-current','checkedAt'=>'2026-11-06T12:00:00Z','expiresAt'=>'2026-11-06T12:00:05Z','hostScopeHash'=>$scope->hostScopeHash->value]);
        return new D\DecisionReceipt($wire->with('receiptDigest',HashDomain::DecisionReceipt->digest($wire)),PortFixture::validator());
    }
}
final class CatalogFixture
{
    public readonly Connection $db;
    public readonly PdoCatalogTransaction $tx;
    public readonly PdoCatalogRepository $repo;
    public readonly PdoOutbox $outbox;
    public readonly CurrentOwner $owner;
    public readonly MutationAuthorizer $authority;
    public readonly CatalogCommands $commands;
    public readonly DomainEventFactory $events;
    public readonly TestClock $clock;
    public readonly TestIds $ids;
    public static function id(int $id): string { return PortFixture::id($id); }
    public static function root(): string { return dirname(__DIR__,3); }
    public function __construct(public readonly DatabaseSandbox $sandbox)
    {
        $this->db=new Connection($sandbox->connection,$sandbox->schema); $this->tx=new PdoCatalogTransaction($this->db);
        $this->clock=new TestClock(); $this->ids=new TestIds(); $this->owner=new CurrentOwner($this->db);
        $this->authority=new MutationAuthorizer($this->owner,$this->clock);
        $this->outbox=new PdoOutbox($this->db,$this->tx); $this->events=new DomainEventFactory($this->clock,$this->ids,PortFixture::validator());
        $this->repo=new PdoCatalogRepository($this->db,$this->tx,$this->authority->requireAllow(...),$this->clock,$this->ids,$this->events->create(...),$this->outbox,str_repeat('test cursor authentication ',2));
        $this->commands=new CatalogCommands($this->repo,$this->tx,$this->authority);
    }
    public static function run(callable $test): mixed
    {
        $sandbox=new DatabaseSandbox();
        return $sandbox->run(function($sandbox) use($test) {
            (new Migrate($sandbox->connection,$sandbox->schema))->apply(self::root().'/packages/core/migrations/postgres/001_metadata.sql');
            $role='knowkit_test_'.bin2hex(random_bytes(8));
            $sandbox->connection->exec('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER');
            try {
                $sandbox->connection->exec('GRANT USAGE ON SCHEMA '.$sandbox->schema.' TO '.$role);
                $sandbox->connection->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA '.$sandbox->schema.' TO '.$role);
                $sandbox->connection->exec('SET ROLE '.$role);
                return $test(new self($sandbox));
            } finally {
                if ($sandbox->connection->inTransaction()) { $sandbox->connection->rollBack(); }
                $sandbox->connection->exec('RESET ROLE'); $sandbox->connection->exec('DROP OWNED BY '.$role);
                $sandbox->connection->exec('DROP ROLE '.$role);
            }
        });
    }
    public function scope(int $tenant=1): D\Scope
    {
        $row=$this->db->row('SELECT kb_policy_epoch FROM '.$this->db->table('knowledge_bases').' WHERE tenant_id=? AND id=?',[self::id($tenant),self::id(100)]);
        return new D\Scope(new D\TenantId(self::id($tenant)),new D\KbId(self::id(100)),new D\ActorId(self::id(101)),'catalog.manage',new D\Decimal((string)($row['kb_policy_epoch']??0)),new D\Sha256(str_repeat('a',64)));
    }
    public function context(string $key='create-kb',?string $revision=null,?string $epoch='current'): D\MutationContext
    {
        if ($epoch==='current') { $epoch=$this->scope()->policyEpoch->value; }
        $w=new JsonObject(['idempotencyKey'=>$key,'requestId'=>self::id(900)]);
        if ($revision!==null) { $w=$w->with('expectedRevision',$revision); } if ($epoch!==null) { $w=$w->with('expectedEpoch',$epoch); }
        return new D\MutationContext($w,PortFixture::validator());
    }
    public function tenant(int $id=1): void
    {
        $t=new D\Tenant(new D\TenantId(self::id($id)),'ready');
        $this->tx->run(function() use($t) { $this->tx->lockTenant($t->tenantId,D\LockMode::Update); $this->repo->insertTenant($t->tenantId,$t); });
    }
    public function kb(int $tenant=1): D\KnowledgeBase
    {
        $s=$this->scope($tenant); return $this->commands->createKnowledgeBase($s,new D\CreateKB($s->kbId,'Same local name','owner:policy',new D\ProfileId('postgres-fts-simple-v1'),new D\CoveragePolicy()),$this->context());
    }
    public function source(int $id=2,int $tenant=1): D\Source
    {
        $s=new D\Source($this->scope($tenant)->tenantId,new D\SourceId(self::id($id)),D\SourceLifecycleState::Active,new D\Decimal('0'),new D\Decimal('0'),false,'owner:library','host:upload');
        $this->tx->run(function() use($s) { $this->tx->lockTenant($s->tenantId,D\LockMode::Update); $this->repo->insertSource($s->tenantId,$s); }); return $s;
    }
    public function resource(int $id=300,int $source=2,int $tenant=1,string $owner='owned',?string $digest=null): void
    {
        $digest??=hash('sha256','synthetic bytes');
        $this->db->execute('INSERT INTO '.$this->db->table('resource_ledger').'(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state)
            VALUES (?,?,?,?,?,?,?,?,?,\'accepted\')',[self::id($tenant),self::id($id),self::id($source),$owner,'test','key-'.$id,'object-'.$id,$digest,'15']);
    }
    public function count(string $table): int { return (int)$this->db->execute('SELECT count(*) FROM '.$this->db->table($table))->fetchColumn(); }
    public static function fixture(): array { return json_decode(file_get_contents(__DIR__.'/fixtures/v1/cases.json'),true,512,JSON_THROW_ON_ERROR); }
    public static function sqlReject(\PDO $pdo,string $sql,array $values=[],array $states=['23503','23514','23505']): void
    {
        $own=!$pdo->inTransaction(); if ($own) { $pdo->beginTransaction(); } $pdo->exec('SAVEPOINT negative_case');
        try { $s=$pdo->prepare($sql); $s->execute($values); throw new \RuntimeException('SQL invariant accepted forbidden input'); }
        catch (\PDOException $e) { if (!in_array($e->getCode(),$states,true)) { throw $e; } }
        finally { $pdo->exec('ROLLBACK TO SAVEPOINT negative_case'); if ($own) { $pdo->rollBack(); } }
    }
}
