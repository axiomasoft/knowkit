<?php
declare(strict_types=1);
use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs,JsonObject,StrictJson};
use KnowKit\Tests\Jobs\Leases\Fixture;
require dirname(__DIR__,2).'/tooling/baseline/bootstrap.php';
require __DIR__.'/Fixture.php';
require __DIR__.'/PublicationFixture.php';

try {
    [$script,$schema,$role,$directory,$mode]=$argv;
    Connection::schema($schema); Connection::schema($role);
    $dsn=getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
    if (!preg_match('/dbname=[a-zA-Z0-9_]+_test(?:;|$)/',$dsn)) { throw new RuntimeException('Test database required'); }
    $pdo=new PDO($dsn,getenv('TEST_PG_USER')?:'knowkit_test',getenv('TEST_PG_PASSWORD')?:null);
    $pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$schema);
    $db=new Connection($pdo,$schema); $tx=new PdoCatalogTransaction($db);
    $first=$db->row('SELECT scope_json FROM jobs ORDER BY id LIMIT 1');
    $scope=Fixture::scopeValue(StrictJson::decode($first['scope_json']));
    $queue=Fixture::makeQueue($db,$scope,$directory,$tx);
    fwrite(STDOUT,"READY\n"); fflush(STDOUT); $input=trim(fgets(STDIN));
    if ($mode==='acquire') {
        $lease=$queue->lease(new D\JobType('extract'),new D\WorkerId(Fixture::id(random_int(900,999))));
        fwrite(STDOUT,($lease===null?'NONE':'LEASE '.base64_encode(serialize($lease)))."\n"); fflush(STDOUT);
    } elseif ($mode==='hold-job') {
        $pdo->beginTransaction(); $db->row('SELECT id FROM jobs ORDER BY id LIMIT 1 FOR UPDATE');
        fwrite(STDOUT,"LOCKED\n"); fflush(STDOUT); fgets(STDIN); $pdo->rollBack();
    } else {
        // Private test IPC only; the lease capability never enters an evidence file or a log.
        $lease=unserialize(base64_decode($input,true));
        if (!$lease instanceof D\Lease) { throw new RuntimeException('Expected lease IPC'); }
        if ($mode==='staged-kill') {
            Fixture::durableFile($directory.'/resource-'.Fixture::id(350),'staged-output');
            $db->execute("INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,staging_id,digest,byte_length,state)
                VALUES (?,?,?,'owned','fixture','staging-output',?,?,13,'uploaded')",
                [Fixture::id(1),Fixture::id(350),Fixture::id(2),Fixture::id(351),hash('sha256','staged-output')]);
            $db->execute("INSERT INTO resource_writers VALUES (?,?,?,?, 'active',?,clock_timestamp(),NULL)",
                [Fixture::id(1),Fixture::id(352),Fixture::id(350),$lease->fence->value,$lease->jobId->value]);
            $db->execute('UPDATE index_generations SET chunk_count=1 WHERE id=?',[Fixture::id(501)]);
            fwrite(STDOUT,"STAGED\n"); fflush(STDOUT); fgets(STDIN);
        } elseif ($mode==='extracting') {
            fwrite(STDOUT,"EXTRACTING\n"); fflush(STDOUT); fgets(STDIN);
            fwrite(STDOUT,($queue->complete($lease,new D\JobResult(D\Outcome::Success,new D\Sha256(hash('sha256','result'))))?'ACCEPTED':'STALE')."\n"); fflush(STDOUT);
        } elseif ($mode==='complete-before' || $mode==='complete-after') {
            $effect=\KnowKit\Tests\Jobs\Leases\PublicationFixture::effect($db,$tx,$scope);
            $result=new D\JobResult(D\Outcome::Success,new D\Sha256(hash('sha256','result')));
            $ok=$queue->accept($lease,$result,function($guard) use($effect,$mode): void {
                $effect($guard);
                if ($mode==='complete-before') { fwrite(STDOUT,"PRECOMMIT\n"); fflush(STDOUT); fgets(STDIN); }
            });
            fwrite(STDOUT,($ok?'COMMITTED':'STALE')."\n"); fflush(STDOUT);
            if ($mode==='complete-after') { fgets(STDIN); }
        } else { throw new RuntimeException('Unknown worker mode'); }
    }
} catch (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n"); exit(1); }
