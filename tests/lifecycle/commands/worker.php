<?php
declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogRepository, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Application\Catalog\{MutationAuthorizer, SourceCommands};
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Lifecycle\LifecycleCommands;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Lifecycle\Commands\Fixture;
use KnowKit\Tests\Storage\Catalog\{CurrentOwner, TestClock, TestIds};

require dirname(__DIR__,2).'/tooling/baseline/bootstrap.php';
require __DIR__.'/Fixture.php';
[$script,$schema,$role,$mode] = $argv;
Connection::schema($schema); Connection::schema($role);
$pdo = new PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test', getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null);
$pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$schema);
$db = new Connection($pdo, $schema); $tx = new PdoCatalogTransaction($db);
$clock = new TestClock(); $ids = new TestIds(900000); $tenant = new D\TenantId(Fixture::id(1));
$scope = new D\Scope($tenant, new D\KbId(Fixture::id(100)), new D\ActorId(Fixture::id(101)), 'catalog.manage', new D\Decimal('1'), new D\Sha256(str_repeat('a',64)));
$owner = new class(new CurrentOwner($db)) implements \KnowKit\Port\AccessPolicy {
    public function __construct(private CurrentOwner $delegate) {}
    public function authorizeQuery(D\Scope $scope): D\QueryGrant { throw new \LogicException('Not used'); }
    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $receipt = $this->delegate->decide($scope, $resource, $operation);
        fwrite(STDOUT,"OWNER\n"); fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'CONTINUE') { throw new \RuntimeException('Owner response interrupted'); }
        return $receipt;
    }
};
$authority = new MutationAuthorizer($owner, $clock); $outbox = new PdoOutbox($db,$tx);
$events = new DomainEventFactory($clock,$ids,PortFixture::validator());
$repo = new PdoCatalogRepository($db,$tx,$authority->requireAllow(...),$clock,$ids,$events->create(...),$outbox,str_repeat('test cursor authentication ',2));
$store = new PdoLifecycleStore($db,$tx,$tenant,$clock,$ids,PortFixture::validator(),$events->create(...),null,$outbox,$authority->requireAllow(...));
$commands = new LifecycleCommands($store,$repo,$tx,$authority,$repo->acceptSourceVersion(...));
fwrite(STDOUT,'READY '.$db->execute('SELECT pg_backend_pid()')->fetchColumn()."\n"); fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'GO') { exit(2); }
try {
    if ($mode === 'publish') {
        $commands->publishSnapshot($scope, new D\SourceVersionRef($tenant,new D\SourceId(Fixture::id(2)),new D\SourceVersionId(Fixture::id(3)),new D\SourceDigest(hash('sha256','version-3'))),
            new D\SnapshotId(Fixture::id(110)),new D\Decimal('0'),new D\Decimal('0'),new D\IdempotencyKey('race-publish'));
    } elseif ($mode === 'register') {
        (new SourceCommands($repo->registerSourceMetadata(...),$repo->acceptSourceVersion(...),$tx,$authority))->register($scope,
            new D\RegisterSource(new D\SourceId(Fixture::id(77)), 'owner:test', 'host:upload'),
            new D\MutationContext(new \KnowKit\Domain\Contract\JsonObject(['idempotencyKey'=>'late-register','requestId'=>Fixture::id(901),'expectedRevision'=>'0','expectedEpoch'=>'0']), PortFixture::validator()));
    } else {
        $commands->revoke($scope,new D\Revocation(new D\SourceId(Fixture::id(2)),new D\Decimal('0')),new D\IdempotencyKey('race-revoke'));
    }
    fwrite(STDOUT,"committed\n");
} catch (D\DomainFailure $error) { fwrite(STDOUT,$error->outcome->value.':'.$error->errorCode."\n"); }
