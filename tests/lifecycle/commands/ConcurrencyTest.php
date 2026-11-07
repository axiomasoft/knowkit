<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Commands;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:commands')]
final class ConcurrencyTest extends TestCase
{
    public function testPublishAndRevokeSerializeInBothOrdersWithDelayedOwner(): void
    {
        $observed = [];
        foreach (['publish','revoke'] as $first) {
            Fixture::run(function (Fixture $f) use ($first, &$observed): void {
                $f->sealSnapshot(110,14,2,3,4,10,11);
                $worker = new Worker($f, $first === 'publish' ? 'revoke' : 'publish');
                $scope = $f->scope(); $source = new D\SourceId(Fixture::id(2));
                $operation = $first === 'publish' ? D\Operation::Publish : D\Operation::LifecycleRevoke;
                $f->catalog->authority->prepare($scope,new D\ResourceRef($f->tenant,'source',$source),$operation);
                try {
                    $f->atomic(function () use ($f,$worker,$scope,$source,$operation,$first): void {
                        $f->catalog->tx->lockKb($scope->kbId,D\LockMode::Update);
                        $f->catalog->tx->lockSources([$source],D\LockMode::Update);
                        $worker->go(); self::assertSame('OWNER',$worker->line());
                        // External answer is captured before local mutation, then delayed outside its transaction.
                        $f->store->executeIdempotent($scope,$source,$operation,new D\IdempotencyKey('race-first'),new JsonObject(),new D\Decimal('0'),
                            function () use ($f,$scope,$source,$first): JsonObject {
                                if ($first === 'publish') {
                                    $f->store->activateSnapshot($scope,$f->versionRef(3),new D\SnapshotId(Fixture::id(110)),new D\Decimal('0'),new D\Decimal('0'));
                                } else { self::assertTrue($f->store->revokeIdentity($scope,new D\Revocation($source,new D\Decimal('0')))); }
                                return new JsonObject();
                            });
                        $worker->resume(); $worker->waitForLock($f);
                    });
                    $result = $worker->line();
                    self::assertSame($first === 'publish' ? 'committed' : 'denied:POLICY_CHANGED', $result);
                    self::assertSame(0,$worker->finish());
                    self::assertSame(1,$f->catalog->count('tombstones'));
                    self::assertSame(D\Decision::Deny,$f->atomic(fn () => $f->decision($f->ref('chunk',11))));
                    $kb = $f->catalog->repo->getKb($f->tenant,$scope->kbId);
                    self::assertSame($first === 'publish' ? Fixture::id(110) : null, $kb->activeSnapshotId?->value);
                    self::assertFalse($f->lifecycle->guarantee()->toWire()->get('distributedInstantAtomicityClaimed'));
                    self::assertSame(5,$f->lifecycle->guarantee()->toWire()->get('externalOwnerWindowBoundSeconds'));
                    $observed[$first === 'publish' ? 'publishThenRevoke' : 'revokeThenPublish'] = $first === 'publish'
                        ? ($f->atomic(fn () => $f->decision($f->ref('chunk',11))) === D\Decision::Deny ? 'denied_final_use' : 'unexpected')
                        : (str_starts_with($result,'denied:') ? 'denied_publication' : 'unexpected');
                } finally { $worker->close(); }
            });
        }
        $observed['ownerAtomicity'] = 'local_only';
        Fixture::assertOutcome('R30-A02',$observed);
    }

    public function testLockTimeoutIsUnavailableAndCommitsNoLifecycleEffect(): void
    {
        Fixture::run(function (Fixture $f): void {
            $events = $f->catalog->count('outbox'); $worker = new Worker($f,'timeout');
            try {
                $f->atomic(function () use ($f,$worker): void {
                    $worker->go(); self::assertSame('OWNER',$worker->line()); $worker->resume(); $worker->waitForLock($f);
                    self::assertSame('unavailable:RETRYABLE_LOCK_TIMEOUT',$worker->line());
                },D\LockMode::Share);
                self::assertSame(0,$worker->finish()); self::assertSame($events,$f->catalog->count('outbox'));
                self::assertSame(0,$f->catalog->count('tombstones'));
            } finally { $worker->close(); }
        });
    }

    public function testUnseenSourceTombstoneWinsAgainstDelayedRegistration(): void
    {
        Fixture::run(function (Fixture $f): void {
            $worker = new Worker($f,'register'); $source = new D\SourceId(Fixture::id(77)); $scope = $f->scope();
            $f->catalog->authority->prepare($scope,new D\ResourceRef($f->tenant,'source',$source),D\Operation::LifecycleRevoke);
            try {
                $f->atomic(function () use ($f,$worker,$scope,$source): void {
                    $f->catalog->tx->lockKb($scope->kbId,D\LockMode::Update);
                    $worker->go(); self::assertSame('OWNER',$worker->line());
                    $f->store->executeIdempotent($scope,$source,D\Operation::LifecycleRevoke,new D\IdempotencyKey('unseen'),new JsonObject(),new D\Decimal('0'),
                        function () use ($f,$scope,$source): JsonObject {
                            self::assertTrue($f->store->revokeIdentity($scope,new D\Revocation($source,new D\Decimal('0')))); return new JsonObject();
                        });
                    $worker->resume(); $worker->waitForLock($f);
                });
                self::assertSame('incompatible:storage_conflict',$worker->line()); self::assertSame(0,$worker->finish());
                self::assertNull($f->catalog->repo->getSource($f->tenant,$source));
                $tombstone = $f->atomic(fn () => $f->store->getTombstone($scope,$source));
                self::assertSame('1',$tombstone->lifecycleEpoch->value); self::assertSame('future_versions',$tombstone->appliesTo);
                Fixture::assertOutcome('R30-A01', ['lateRegistration'=>'rejected', 'sourceExists'=>$f->catalog->repo->getSource($f->tenant,$source) !== null,
                    'appliesTo'=>$tombstone->appliesTo]);
            } finally { $worker->close(); }
        });
    }
}

/** Reuses donor real-connection contention assertions; no sleep determines the serialization outcome. */
final class Worker
{
    private mixed $process;
    private array $pipes = [];
    private int $pid;
    public function __construct(Fixture $f, string $mode)
    {
        $role = $f->catalog->db->execute('SELECT current_user')->fetchColumn();
        $this->process = proc_open([PHP_BINARY,__DIR__.'/worker.php',$f->catalog->db->schema,$role,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,dirname(__DIR__,3),getenv());
        if (!is_resource($this->process)) { throw new \RuntimeException('Worker unavailable'); }
        stream_set_timeout($this->pipes[1],10);
        if (!preg_match('/^READY ([0-9]+)$/D',$this->line(),$match)) { throw new \RuntimeException('Worker not ready'); }
        $this->pid = (int)$match[1];
    }
    public function go(): void { $this->write('GO'); }
    public function resume(): void { $this->write('CONTINUE'); }
    private function write(string $line): void { fwrite($this->pipes[0],$line."\n"); fflush($this->pipes[0]); }
    public function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) { throw new \RuntimeException('Worker missing output: '.stream_get_contents($this->pipes[2])); }
        return trim($line);
    }
    public function waitForLock(Fixture $f): void
    {
        for ($i=0;$i<300;$i++) {
            if ($f->catalog->db->execute('SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid=? AND NOT granted)',[$this->pid])->fetchColumn()) { return; }
            usleep(1000);
        }
        throw new \RuntimeException('Worker did not wait on semantic guard');
    }
    public function finish(): int
    {
        fclose($this->pipes[0]); fclose($this->pipes[1]); $error = stream_get_contents($this->pipes[2]); fclose($this->pipes[2]); $this->pipes=[];
        $exit = proc_close($this->process); $this->process=null;
        if ($error !== '') { throw new \RuntimeException($error); }
        return $exit;
    }
    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process,9);
            foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($this->process); $this->process=null;
        }
    }
}
