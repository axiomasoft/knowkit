<?php
declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Domain as D;
use KnowKit\Port\Outbox;
use KnowKit\Tests\Events\Outbox\Fixture;
use KnowKit\Tests\Publication\Activate\Services;

require dirname(__DIR__,3).'/vendor/autoload.php';
require __DIR__.'/Fixture.php';
require dirname(__DIR__,2).'/publication/activate/Fixture.php';
[$script,$schema,$role,$mode,$directory] = $argv;
Connection::schema($schema); Connection::schema($role);
$pdo = new PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER') ?: 'knowkit_test',getenv('TEST_PG_PASSWORD') ?: null);
$pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$schema);
$db = new Connection($pdo,$schema); $tx = new PdoCatalogTransaction($db);
$pause = static function(string $phase): void { echo $phase."\n"; flush(); fgets(STDIN); };
if ($mode === 'delivery-before-send') {
    $delivery = new \KnowKit\Adapter\Postgres\Outbox\PdoDelivery($db,$tx,new D\TenantId(Fixture::id(1)),\KnowKit\Tests\Contract\Ports\PortFixture::validator());
    if (count($delivery->leaseDelivery(1)) !== 1 || $pdo->inTransaction()) { exit(2); }
    $pause('LEASED');
} else {
    $request = unserialize(base64_decode(trim(fgets(STDIN)),true));
    if (!$request instanceof D\ActivationRequest) { exit(2); }
    $outbox = new class(new PdoOutbox($db,$tx),$mode,$pause) implements Outbox {
        public function __construct(private Outbox $inner, private string $mode, private Closure $pause) {}
        public function append(D\DomainEvent $e): void
        { $this->inner->append($e); if ($this->mode === 'publication-before') { ($this->pause)('PRECOMMIT'); } }
    };
    $service = new Services($db,$tx,$request,$directory,$outbox,null,null,8800000);
    if (str_starts_with($mode,'lifecycle-')) {
        $after = $mode === 'lifecycle-before' ? static fn() => $pause('PRECOMMIT') : null;
        $service->lifecycle($request->lease->scope,null,$after)->revoke($request->lease->scope,
            new D\Revocation($request->lease->sourceRef->sourceId,new D\Decimal('0')),new D\IdempotencyKey('crash-revoke'));
    } else { $service->publisher->publish($request); }
    $pause('COMMITTED');
}
