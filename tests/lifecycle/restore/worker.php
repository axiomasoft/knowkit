<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Restore;

use KnowKit\Adapter\LocalBlob\RecoveryEvidence;
use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Reconciliation\PdoReconciliationStore;
use KnowKit\Application\Reconciliation\Reconcile;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{JsonObject,StrictJson};
use KnowKit\Port\RecoveryEvidence as Evidence;
use KnowKit\Tests\Storage\Catalog\TestIds;
require_once dirname(__DIR__,2).'/tooling/baseline/bootstrap.php';
require_once __DIR__.'/Fixture.php';

$config=json_decode(fgets(STDIN,32768),true,64,JSON_THROW_ON_ERROR);
foreach (['schema','role'] as $k) { if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D',$config[$k])!==1) { throw new \InvalidArgumentException('Safe worker scope required'); } }
$pdo=new \PDO(getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER')?:'knowkit_test',getenv('TEST_PG_PASSWORD')?:null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET search_path TO '.$config['schema']); $pdo->exec('SET ROLE '.$config['role']);
$plan=Fixture::fromWire(StrictJson::decode(json_encode($config['plan'],JSON_THROW_ON_ERROR)));
$db=new Connection($pdo,$config['schema']); $tx=new PdoCatalogTransaction($db);
$local=new RecoveryEvidence($plan->tenantId,$config['root'],'local-v1',1048576,static fn()=>[],static fn()=>false,static fn()=>false);
$evidence=new class($local,$pdo) implements Evidence {
    public function __construct(private Evidence $local,private \PDO $pdo) {}
    public function journal(D\RecoveryPlan $p,D\Decimal $a,int $n): array { return $this->local->journal($p,$a,$n); }
    public function objects(D\RecoveryPlan $p,?string $a,int $n): array { return $this->local->objects($p,$a,$n); }
    public function resource(D\RecoveryPlan $p,JsonObject $r): string {
        $result=$this->local->resource($p,$r);
        if ($result!=='verified' || $this->pdo->inTransaction()) { throw new \RuntimeException('Expected verified external I/O outside SQL'); }
        echo "VERIFIED\n"; fflush(STDOUT); fgets(STDIN); return $result;
    }
    public function rebuild(D\RecoveryPlan $p,JsonObject $r): bool { return false; }
    public function deniedCanary(D\RecoveryPlan $p): bool { return false; }
};
(new Reconcile(new PdoReconciliationStore($db,$tx,$plan->tenantId,new TestIds(95000)),$evidence))->step($plan,1);
