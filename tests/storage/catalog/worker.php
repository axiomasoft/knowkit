<?php
declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogRepository,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Application\Catalog\{CatalogCommands,MutationAuthorizer};
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\{CatalogFixture,CurrentOwner,TestClock,TestIds};

require dirname(__DIR__,2).'/tooling/baseline/bootstrap.php';
require __DIR__.'/bootstrap.php';
try {
    [$script,$schema,$role,$mode,$label]=$argv;
    Connection::schema($schema); Connection::schema($role);
    $dsn=getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
    if (!preg_match('/dbname=[a-zA-Z0-9_]+_test(?:;|$)/',$dsn)) { throw new RuntimeException('Test database required'); }
    $pdo=new PDO($dsn,getenv('TEST_PG_USER')?:'knowkit_test',getenv('TEST_PG_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$schema);
    $db=new Connection($pdo,$schema); $tx=new PdoCatalogTransaction($db); $clock=new TestClock(); $ids=new TestIds(random_int(10000000,99999999));
    $authority=new MutationAuthorizer(new CurrentOwner($db),$clock); $events=new DomainEventFactory($clock,$ids,PortFixture::validator());
    $repo=new PdoCatalogRepository($db,$tx,$authority->requireAllow(...),$clock,$ids,$events->create(...),new PdoOutbox($db,$tx),str_repeat('test cursor authentication ',2));
    $currentScope=static function() use($repo): D\Scope {
        $tenant=new D\TenantId(CatalogFixture::id(1)); $kbId=new D\KbId(CatalogFixture::id(100));
        $kb=$repo->getKb($tenant,$kbId)??throw new RuntimeException('Test KB required');
        return new D\Scope($tenant,$kbId,new D\ActorId(CatalogFixture::id(101)),
            'catalog.manage',$kb->policyEpoch,new D\Sha256(str_repeat('a',64)));
    };
    $s=$currentScope();
    $command=new D\CreateCollection(new D\CollectionId(CatalogFixture::id(200)),null,new D\CatalogMetadata(new JsonObject(['test.label'=>$label])));
    $context=new D\MutationContext(new JsonObject(['idempotencyKey'=>'competing-key','requestId'=>CatalogFixture::id(900),'expectedRevision'=>'0','expectedEpoch'=>'0']),PortFixture::validator());
    fwrite(STDOUT,"READY\n"); fflush(STDOUT); fgets(STDIN);
    if ($mode==='crash-before') {
        $authority->prepare($s,new D\ResourceRef($s->tenantId,'knowledge_base',$s->kbId),D\Operation::CollectionCreate);
        $tx->run(function() use($tx,$repo,$s,$command,$context) {
            $tx->lockTenant($s->tenantId,D\LockMode::Update); $tx->lockKb($s->kbId,D\LockMode::Update);
            $repo->createCollection($s,$command,$context); fwrite(STDOUT,"PRECOMMIT\n"); fflush(STDOUT); fgets(STDIN);
        });
    } else {
        $commands=new CatalogCommands($repo,$tx,$authority);
        try { $result=$commands->createCollection($s,$command,$context); }
        catch (D\DomainFailure $e) {
            if ($e->errorCode!=='POLICY_CHANGED') { throw $e; }
            // A competing commit can stale the pre-lock receipt. Model one host retry with
            // a fresh scope and owner check, keeping the original command/expected state.
            $result=$commands->createCollection($currentScope(),$command,$context);
        }
        fwrite(STDOUT,json_encode(['status'=>'accepted','revision'=>$result->revision->value,'label'=>$result->metadata->toWire()->get('test.label')],JSON_THROW_ON_ERROR)."\n"); fflush(STDOUT);
        if ($mode==='crash-after') { fgets(STDIN); }
    }
} catch (D\DomainFailure $e) { fwrite(STDOUT,json_encode(['status'=>$e->errorCode],JSON_THROW_ON_ERROR)."\n"); }
catch (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n"); exit(1); }
