<?php
declare(strict_types=1);
namespace KnowKit\Tests\Publication\Activate;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Outbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('publication:activate')]
final class ConcurrencyTest extends TestCase
{
    public function testTwoIngestsWithTheSameExpectedPointerHaveOneWinner(): void
    {
        foreach ([0,1] as $first) {
            Fixture::run(function(Fixture $f) use ($first): void {
                $one = $f->request(); $f->seal($one); [$set,$lease] = $f->second();
                $two = $f->request(502,'0','publish-two',$set,$lease); $f->seal($two);
                $requests = [$one,$two]; $winner = $requests[$first]; $loser = $requests[1-$first];
                $worker = new Worker($f,$loser,'activate');
                try {
                    $worker->prepare(); $events = $f->manifest->jobs->catalog->count('outbox');
                    $barrier = $this->barrier($f,$worker);
                    $receipt = $f->services($winner,$barrier)->publisher->publish($winner);
                    self::assertSame('incompatible:POINTER_CONFLICT',$worker->line()); self::assertSame(0,$worker->finish());
                    self::assertSame($receipt->toWire()->get('snapshotId'),$f->manifest->pointer());
                    self::assertSame(1,$f->manifest->jobs->catalog->count('publication_snapshots'));
                    self::assertSame($events+1,$f->manifest->jobs->catalog->count('outbox'));
                    self::assertSame('1',(string)$f->db()->execute('SELECT pointer_version FROM knowledge_bases')->fetchColumn());
                    // Explicit replan/re-authorization, preserving the sealed loser manifest unchanged.
                    $retry = new D\ActivationRequest($loser->lease,$loser->candidate,new D\Decimal('1'),new D\IdempotencyKey('replanned'));
                    $f->services($retry)->publisher->publish($retry);
                    self::assertSame($receipt->canonical(),$f->services($winner)->publisher->publish($winner)->canonical());
                    self::assertSame('2',(string)$f->db()->execute('SELECT pointer_version FROM knowledge_bases')->fetchColumn());
                    Fixture::outcome('TWO-INGESTS',['initialSuccesses'=>1,'loser'=>'POINTER_CONFLICT','replannedPointer'=>'2']);
                } finally { $worker->close(); }
            });
        }
    }

    public function testPublicationAndRevokeSerializeInBothCommitOrdersWithDelayedOwner(): void
    {
        foreach (['activate','revoke'] as $first) {
            Fixture::run(function(Fixture $f) use ($first): void {
                $r = $f->request(); $f->seal($r);
                $worker = new Worker($f,$r,$first === 'activate' ? 'revoke' : 'activate');
                try {
                    $worker->prepare(); // Owner Allow captured while the source is still active.
                    $barrier = $this->barrier($f,$worker); $service = $f->services($r,$barrier);
                    if ($first === 'activate') { $service->publisher->publish($r); }
                    else { $service->lifecycle($r->lease->scope,null,fn() => $worker->resumeAndWaitForLock($f))->revoke($r->lease->scope,new D\Revocation($r->lease->sourceRef->sourceId,new D\Decimal('0')),new D\IdempotencyKey('revoke-first')); }
                    self::assertSame($first === 'activate' ? 'committed' : 'denied:source_changed',$worker->line());
                    self::assertSame(0,$worker->finish());
                    self::assertSame(1,$f->manifest->jobs->catalog->count('tombstones'));
                    self::assertSame($first === 'activate' ? 1 : 0,$f->manifest->jobs->catalog->count('publication_snapshots'));
                    self::assertSame('revoked',$f->db()->execute('SELECT lifecycle_state FROM sources')->fetchColumn());
                    self::assertSame($first === 'activate', $f->manifest->pointer() !== null);
                    self::assertFalse($service->lifecycle($r->lease->scope)->guarantee()->toWire()->get('distributedInstantAtomicityClaimed'));
                    Fixture::outcome($first === 'activate' ? 'PUBLISH-THEN-REVOKE' : 'REVOKE-THEN-PUBLISH',
                        ['snapshotCommitted'=>$first === 'activate','finalUsability'=>'denied']);
                } finally { $worker->close(); }
            });
        }
        Fixture::outcome('R30-A02',['serialization'=>'local_postgres','ownerAtomicity'=>'local_only']);
    }

    private function barrier(Fixture $f, Worker $worker): Outbox
    {
        return new class($f->manifest->jobs->catalog->outbox,fn() => $worker->resumeAndWaitForLock($f)) implements Outbox {
            private bool $called = false;
            public function __construct(private Outbox $inner, private \Closure $barrier) {}
            public function append(D\DomainEvent $event): void
            {
                $this->inner->append($event);
                if (!$this->called) { $this->called = true; ($this->barrier)(); }
            }
        };
    }

    public function testInterruptedStagingCannotBecomeActiveAndRemainsRecoverable(): void
    {
        Fixture::run(function(Fixture $f): void {
            $r = $f->request(); $service = $f->services($r); $service->staging->stage($r->lease,$r->candidate);
            $db = $f->db(); $role = $db->execute('SELECT current_user')->fetchColumn();
            $process = proc_open([PHP_BINARY,dirname(__DIR__).'/manifest/interrupted.php',$db->schema,$role],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,3));
            self::assertIsResource($process);
            try {
                stream_set_timeout($pipes[1],10); self::assertSame("PARTIAL\n",fgets($pipes[1]));
                self::assertTrue(proc_terminate($process,9));
            } finally { foreach ($pipes as $p) { fclose($p); } proc_close($process); }
            Fixture::failure(fn() => $f->services($r)->publisher->publish($r),'index_unreadable',D\Outcome::Unavailable);
            self::assertNull($f->manifest->pointer()); self::assertSame('building',$f->manifest->state());
            self::assertSame($r->candidate->digest->value,$db->execute('SELECT manifest_digest FROM generation_checks')->fetchColumn());
            $f->seal($r); $f->services($r)->publisher->publish($r);
            Fixture::outcome('R11-A01',['partialActive'=>false,'stagingInventory'=>'durable','retry'=>'published']);
        });
    }
}

/** Pipes establish phase barriers; pg_locks proves actual contention, never a sleep-based ordering. */
final class Worker
{
    private mixed $process;
    private array $pipes = [];
    private int $pid;
    public function __construct(Fixture $f, D\ActivationRequest $r, string $mode)
    {
        $db = $f->db(); $role = $db->execute('SELECT current_user')->fetchColumn();
        $this->process = proc_open([PHP_BINARY,__DIR__.'/worker.php',$db->schema,$role,$mode,$f->manifest->jobs->directory],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,dirname(__DIR__,3),getenv());
        if (!is_resource($this->process)) { throw new \RuntimeException('Worker unavailable'); }
        stream_set_timeout($this->pipes[1],10);
        stream_set_blocking($this->pipes[2],false);
        // Transient synthetic lease capability travels only over this private test process pipe.
        $this->write(base64_encode(serialize($r)));
        if (!preg_match('/^READY ([0-9]+)$/D',$this->line(),$m)) { throw new \RuntimeException('Worker not ready'); }
        $this->pid = (int)$m[1];
    }
    private function write(string $line): void { fwrite($this->pipes[0],$line."\n"); fflush($this->pipes[0]); }
    public function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) { throw new \RuntimeException('Missing worker output: '.stream_get_contents($this->pipes[2])); }
        return trim($line);
    }
    public function prepare(): void { $this->write('START'); \PHPUnit\Framework\Assert::assertSame('PREPARED',$this->line()); }
    public function resumeAndWaitForLock(Fixture $f): void
    {
        $this->write('GO');
        for ($i=0;$i<350;$i++) {
            if ($f->db()->execute('SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid=? AND NOT granted)',[$this->pid])->fetchColumn()) { return; }
            usleep(1000);
        }
        throw new \RuntimeException('Worker did not block on PostgreSQL semantic guard');
    }
    public function finish(): int
    {
        fclose($this->pipes[0]); fclose($this->pipes[1]); $error = stream_get_contents($this->pipes[2]); fclose($this->pipes[2]); $this->pipes=[];
        $exit = proc_close($this->process); $this->process = null;
        if ($error !== '') { throw new \RuntimeException($error); } return $exit;
    }
    public function close(): void
    {
        if (is_resource($this->process)) { proc_terminate($this->process,9); foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($this->process); $this->process = null; }
    }
}

