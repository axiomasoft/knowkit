<?php
declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Port\AccessPolicy;
use KnowKit\Tests\Publication\Activate\Services;
use KnowKit\Tests\Storage\Catalog\CurrentOwner;

require dirname(__DIR__,3).'/vendor/autoload.php';
require __DIR__.'/Fixture.php';
[$script,$schema,$role,$mode,$directory] = $argv;
Connection::schema($schema); Connection::schema($role);
$pdo = new PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER') ?: 'knowkit_test',getenv('TEST_PG_PASSWORD') ?: null);
$pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$schema);
$db = new Connection($pdo,$schema); $tx = new PdoCatalogTransaction($db);
$r = unserialize(base64_decode(trim(fgets(STDIN)),true));
if (!$r instanceof D\ActivationRequest) { exit(2); }
$pause = static function(): void {
    fwrite(STDOUT,"PREPARED\n"); fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'GO') { throw new RuntimeException('Interrupted test barrier'); }
};
$owner = $mode === 'activate' ? null : new class(new CurrentOwner($db),$pause) implements AccessPolicy {
    public function __construct(private AccessPolicy $delegate, private Closure $pause) {}
    public function authorizeQuery(D\Scope $scope): D\QueryGrant { throw new LogicException('Not used'); }
    public function decide(D\Scope $s,D\ResourceRef $ref,D\Operation $op): D\DecisionReceipt { $receipt = $this->delegate->decide($s,$ref,$op); ($this->pause)(); return $receipt; }
};
$service = new Services($db,$tx,$r,$directory,null,$owner,$mode === 'activate' ? $pause : null,8000000);
fwrite(STDOUT,'READY '.$db->execute('SELECT pg_backend_pid()')->fetchColumn()."\n"); fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'START') { exit(2); }
try {
    if ($mode === 'activate') { $service->publisher->publish($r); }
    else { $service->lifecycle($r->lease->scope)->revoke($r->lease->scope,new D\Revocation($r->lease->sourceRef->sourceId,new D\Decimal('0')),new D\IdempotencyKey('worker-revoke')); }
    fwrite(STDOUT,"committed\n");
} catch (D\DomainFailure $error) { fwrite(STDOUT,$error->outcome->value.':'.$error->errorCode."\n"); }
