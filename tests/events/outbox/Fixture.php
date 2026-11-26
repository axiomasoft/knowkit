<?php
declare(strict_types=1);
namespace KnowKit\Tests\Events\Outbox;

use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Outbox\{PdoDelivery,PdoEventAuthority,PdoInbox};
use KnowKit\Application\Events\Consumer;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain,JsonObject,StrictJson};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\{CatalogFixture,TestIds};
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__,2).'/storage/catalog/bootstrap.php';
require_once dirname(__DIR__,2).'/jobs/leases/Fixture.php';

final class Fixture
{
    public function __construct(public CatalogFixture $catalog) {}
    public static function id(int $n): string { return PortFixture::id($n); }
    public static function root(): string { return dirname(__DIR__,3); }
    public static function run(callable $run): void
    { \KnowKit\Tests\Jobs\Leases\MigratedSchema::run(function(CatalogFixture $c) use ($run): void { $c->tenant(); $run(new self($c)); }); }
    public static function data(): JsonObject
    { static $data=null; return $data ??= StrictJson::decode((string)file_get_contents(__DIR__.'/fixtures/v1/cases.json')); }
    public static function event(int $id = 500, string $version = '1', ?JsonObject $payload = null, string $type = 'source.revoked', int $tenant = 1): D\DomainEvent
    {
        $payload ??= new JsonObject(['sourceId'=>self::id(2),'reason'=>'source_revoked','lifecycleEpoch'=>$version,'appliesTo'=>'future_versions']);
        return new D\DomainEvent(new JsonObject(['eventId'=>self::id($id),'schemaVersion'=>'event-1.0','tenantId'=>self::id($tenant),
            'aggregateType'=>'source','aggregateId'=>self::id(2),'aggregateVersion'=>$version,'type'=>$type,'occurredAt'=>'2026-11-06T12:00:00Z',
            'causationId'=>null,'correlationId'=>null,'payload'=>$payload,'payloadDigest'=>HashDomain::DomainEventPayload->digest($payload)]),PortFixture::validator());
    }
    public function append(D\DomainEvent $event): void
    {
        $tx = $this->catalog->tx;
        $tx->run(function() use ($tx,$event): void { $tx->lockTenant(new D\TenantId($event->toWire()->get('tenantId')),D\LockMode::Update); $this->catalog->outbox->append($event); });
    }
    public function delivery(?Connection $db = null, int $tenant = 1): PdoDelivery
    { $db ??= $this->catalog->db; return new PdoDelivery($db,new PdoCatalogTransaction($db),new D\TenantId(self::id($tenant)),PortFixture::validator()); }
    public function inbox(string $consumer = 'invalidate', ?Connection $db = null, int $tenant = 1): PdoInbox
    { $db ??= $this->catalog->db; return new PdoInbox($db,new PdoCatalogTransaction($db),new D\TenantId(self::id($tenant)),$consumer,new TestIds(random_int(1000000,9000000))); }
    public function consumer(PdoInbox $inbox, ?Connection $authority = null): Consumer
    { return new Consumer(new D\TenantId(self::id(1)),PortFixture::validator(),$inbox,new PdoEventAuthority($authority ?? $this->catalog->db,new D\TenantId(self::id(1)))); }
    public function connection(): Connection
    {
        $db = $this->catalog->db; $role = $db->execute('SELECT current_user')->fetchColumn(); Connection::schema($role);
        $pdo = new \PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER') ?: 'knowkit_test',getenv('TEST_PG_PASSWORD') ?: null);
        $pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$db->schema);
        return new Connection($pdo,$db->schema);
    }
    public static function failure(callable $run, string $code, D\Outcome $outcome = D\Outcome::Incompatible): void
    {
        try { $run(); Assert::fail('Expected '.$code); }
        catch (D\DomainFailure $error) { Assert::assertSame($code,$error->errorCode); Assert::assertSame($outcome,$error->outcome); }
    }
    public static function outcome(string $id, array $actual): void
    { Assert::assertSame(\KnowKit\Domain\Contract\Jcs::canonicalize(self::data()->get('outcomes')->get($id)),\KnowKit\Domain\Contract\Jcs::canonicalize(new JsonObject($actual)),$id); }
}

/** Real process and pipe barrier: killed only after the database phase is known. */
final class Worker
{
    private mixed $process;
    private array $pipes = [];
    public function __construct(Connection $db, string $mode, string $directory = '', ?D\ActivationRequest $request = null)
    {
        $role = $db->execute('SELECT current_user')->fetchColumn();
        $this->process = proc_open([PHP_BINARY,__DIR__.'/worker.php',$db->schema,$role,$mode,$directory],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,Fixture::root(),getenv());
        if (!is_resource($this->process)) { throw new \RuntimeException('Crash actor unavailable'); }
        stream_set_timeout($this->pipes[1],10);
        if ($request !== null) { fwrite($this->pipes[0],base64_encode(serialize($request))."\n"); fflush($this->pipes[0]); }
    }
    public function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) { throw new \RuntimeException('Missing actor barrier'); }
        return trim($line);
    }
    public function kill(): void
    {
        Assert::assertTrue(proc_terminate($this->process,9));
        foreach ($this->pipes as $pipe) { fclose($pipe); } $this->pipes = [];
        proc_close($this->process); $this->process = null;
    }
    public function close(): void { if (is_resource($this->process)) { $this->kill(); } }
}
