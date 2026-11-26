<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use KnowKit\Adapter\LocalBlob\LocalBlobStore;
use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Tests\Storage\Blobs\{BlobFixture,Bytes};
use KnowKit\Tests\Storage\Catalog\{TestClock,TestIds};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Domain as D;

[$script,$schema,$role,$root,$writer]=$argv;
if (!preg_match('/\Aknowkit_qa_[a-f0-9]+\z/D',$schema) || !preg_match('/\Aknowkit_test_[a-f0-9]+\z/D',$role)) { exit(2); }
try {
    $dsn=getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
    if (!preg_match('/dbname=[a-zA-Z0-9_]+_test(?:;|$)/',$dsn)) { throw new \RuntimeException('Test database required'); }
    $pdo=new PDO($dsn,getenv('TEST_PG_USER')?:'knowkit_test',getenv('TEST_PG_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$schema);
    $db=new Connection($pdo,$schema); $tx=new PdoCatalogTransaction($db); $clock=new TestClock();
    $tenant=new D\TenantId(BlobFixture::id(1)); $ledger=new PdoResourceLedger($db,$tx,$tenant,'local-v1',$clock);
    $r=new D\BlobWriteReservation(new D\ResourceRef($tenant,'resource',new D\ResourceId(BlobFixture::id(300))),
        new D\SourceId(BlobFixture::id(2)),new D\WriterId(BlobFixture::id((int)$writer)),new D\Decimal('0'),new D\Decimal('0'),null,null,new D\Ulid(BlobFixture::id(1300)));
    $ledger->reserveWriter($r); $callbacks=[];
    foreach (['inspectWriter','finalizeIdentity','closeWriter','markUncertain','requireAccepted','listUnreferenced','quarantine','recoveryRef','reconcileWriter'] as $name) { $callbacks[$name]=$ledger->$name(...); }
    $store=new LocalBlobStore($tenant,$root,'local-v1',1048576,$callbacks,static function() {},$ledger->deletionBinding(...),new TestIds(),$clock,PortFixture::validator());
    echo "READY\n"; flush(); if (trim((string)fgets(STDIN))!=='GO') { exit(3); }
    $ref=$store->putImmutable($r,new Bytes('abc')); echo json_encode(['pid'=>getmypid(),'digest'=>$ref->digest->value,'version'=>$ref->objectVersion]),"\n";
} catch (Throwable $e) { echo json_encode(['error'=>$e instanceof D\DomainFailure?$e->errorCode:'worker_failed']),"\n"; exit(1); }
