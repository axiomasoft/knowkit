<?php
declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Dependencies\{NodeMetadata, PdoDependencyStore};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Tests\Lifecycle\Dependencies\Fixture;
use KnowKit\Tests\Storage\Catalog\TestIds;

require dirname(__DIR__,2).'/tooling/baseline/bootstrap.php';
require __DIR__.'/Fixture.php';
[$script,$schema,$role,$mode]=$argv;
Connection::schema($schema); Connection::schema($role);
$pdo=new PDO(getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER')?:'knowkit_test',getenv('TEST_PG_PASSWORD')?:null);
$pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$schema);
$db=new Connection($pdo,$schema); $tx=new PdoCatalogTransaction($db); $tenant=new D\TenantId(Fixture::id(1));
$graph=new PdoDependencyStore($db,$tx,$tenant,new TestIds(900000));
fwrite(STDOUT,'READY '.$db->execute('SELECT pg_backend_pid()')->fetchColumn()."\n"); fflush(STDOUT);
if (trim(fgets(STDIN))!=='GO') { exit(2); }
try {
    $tx->run(function() use($db,$tx,$tenant,$graph,$mode): void {
        $db->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->execute($mode==='timeout'?"SET LOCAL lock_timeout='150ms'":"SET LOCAL lock_timeout='2s'");
        $tx->lockTenant($tenant,D\LockMode::Update);
        if ($mode==='source') {
            $db->execute("INSERT INTO sources(tenant_id,id,owner_ref,origin,lifecycle_state) VALUES (?,?,'owner:test','host:upload','active')",[$tenant->value,Fixture::id(77)]);
        } else {
            $ref=new D\ResourceRef($tenant,'source_version',new D\SourceVersionId(Fixture::id(3)));
            $m=(new NodeMetadata($db,$tx))->resolve($ref);
            $graph->register(new D\Node($ref,new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($m['identity']))),new D\Parents($tenant,[]));
        }
    });
    fwrite(STDOUT,"committed\n");
} catch (D\DomainFailure $e) { fwrite(STDOUT,$e->outcome->value.':'.$e->errorCode."\n"); }
