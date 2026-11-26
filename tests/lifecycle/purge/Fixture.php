<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Purge;

use KnowKit\Adapter\Postgres\Deletion\PdoDeletionStore;
use KnowKit\Application\Deletion\DeletionWorker;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\{Clock, DeletionTransport};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Lifecycle\Commands\Fixture as LifecycleFixture;
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__).'/commands/Fixture.php';
require_once dirname(__DIR__,2).'/storage/blobs/BlobFixture.php';

final class MutableClock implements Clock
{
    public string $value = '2026-11-06T12:00:01Z';
    public function now(): D\UtcInstant { return new D\UtcInstant($this->value); }
}

/** Contract double only: not qualification of any external provider. */
final class Transport implements DeletionTransport
{
    public int $calls = 0;
    public string $result = 'deleted';
    public bool $proof = true;
    public ?string $expiry = null;
    public ?\Closure $effect = null;
    public function __construct(private Fixture $f) {}
    public function provesExactVersion(D\DeletionTarget $target): bool { return $this->proof; }
    public function delete(D\DeletionTarget $target, D\AttemptId $attempt): D\DeletionReceipt
    {
        Assert::assertFalse($this->f->c()->db->pdo->inTransaction());
        ++$this->calls;
        if ($this->effect !== null) { ($this->effect)($target, $attempt); }
        return new D\DeletionReceipt(new JsonObject(['attemptId'=>$attempt->value, 'taskId'=>$target->taskId->value,
            'resourceId'=>$target->resourceId->value, 'inFlight'=>false, 'result'=>$this->result,
            'providerReceipt'=>in_array($this->result, ['deleted','absent'], true) ? 'synthetic-exact-version' : null,
            'knownExpiry'=>$this->expiry, 'observedAt'=>$this->f->clock->now()->value]), PortFixture::validator());
    }
}

final class Fixture
{
    public readonly MutableClock $clock;
    public readonly PdoDeletionStore $store;
    public readonly Transport $transport;
    public readonly DeletionWorker $worker;
    public ?D\DeletionTaskId $task = null;
    private static ?array $expectations = null;
    private int $sequence = 80000;
    public function __construct(public readonly LifecycleFixture $lifecycle)
    {
        $this->clock = new MutableClock(); $c = $this->c();
        $this->store = new PdoDeletionStore($c->db, $c->tx, $lifecycle->tenant, $this->clock, PortFixture::validator());
        $this->transport = new Transport($this);
        $this->worker = new DeletionWorker($this->store, $this->transport, $this->clock, PortFixture::validator());
    }
    public static function run(\Closure $test): void { LifecycleFixture::run(fn ($l) => $test(new self($l))); }
    public function c(): \KnowKit\Tests\Storage\Catalog\CatalogFixture { return $this->lifecycle->catalog; }
    public static function id(int $n): string { return LifecycleFixture::id($n); }
    public function purge(string $epoch = '0'): D\DeletionTaskId
    {
        return $this->task = $this->lifecycle->lifecycle->requestPurge($this->lifecycle->scope(),
            new D\PurgeRequest(new D\SourceId(self::id(2)), new D\Decimal($epoch)), new D\IdempotencyKey('purge-'.$epoch));
    }
    public function status(): string { return $this->store->aggregate($this->lifecycle->tenant, $this->task); }
    public function attempt(int $resource, ?int $attempt = null): D\DeletionReceipt
    { return $this->worker->attempt($this->lifecycle->tenant, $this->task, new D\ResourceId(self::id($resource)), new D\AttemptId(self::id($attempt ?? $this->sequence++))); }
    public function finishAll(): void
    {
        foreach ($this->c()->db->execute('SELECT resource_id FROM deletion_task_resources WHERE tenant_id=? AND task_id=? ORDER BY resource_id',
            [$this->lifecycle->tenant->value, $this->task->value])->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->worker->attempt($this->lifecycle->tenant, $this->task, new D\ResourceId($id), new D\AttemptId(self::id($this->sequence++)));
        }
    }
    public static function failure(\Closure $call, string $code): void
    {
        try { $call(); Assert::fail('Expected '.$code); }
        catch (D\DomainFailure $e) { Assert::assertSame($code, $e->errorCode); }
    }
    public static function expected(string $id, array $actual): void
    {
        if (self::$expectations === null) {
            $fixture = json_decode(file_get_contents(dirname(__DIR__,2).'/fixtures/deletion/v1/cases.json'), true, 64, JSON_THROW_ON_ERROR);
            self::$expectations = array_column($fixture['cases'], 'expected', 'id');
        }
        Assert::assertSame(self::$expectations[$id], $actual, $id);
    }
}
