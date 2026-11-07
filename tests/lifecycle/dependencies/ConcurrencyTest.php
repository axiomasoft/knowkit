<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Dependencies;

use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

/** Adapted from pinned AgenTools Invalidation/ConcurrencyTest, using the shared tenant barrier. */
#[Group('lifecycle:dependencies')]
final class ConcurrencyTest extends TestCase
{
    public function testWaitingSourceAndGraphRegistrationCannotBypassTombstone(): void
    {
        foreach (['source','node'] as $mode) {
            Fixture::run(function($f) use($mode) {
                $worker=new Worker($f,$mode);
                try {
                    $f->atomic(function() use($f,$worker,$mode) {
                        $worker->go(); $worker->waitForLock($f);
                        $event=$f->event(source:$mode==='source'?77:2);
                        $f->graph->invalidate($event);
                    });
                    self::assertSame($mode==='source'?'incompatible:storage_conflict':'denied:ancestor_unusable',$worker->line());
                    self::assertSame(0,$worker->finish()); self::assertSame(0,$f->catalog->count('dependency_nodes'));
                    self::assertSame(1,$f->catalog->count('tombstones'));
                    self::assertSame(0,(int)$f->catalog->db->execute('SELECT count(*) FROM sources WHERE tenant_id=? AND id=?',[Fixture::id(1),Fixture::id(77)])->fetchColumn());
                } finally { $worker->close(); }
            });
        }
    }
    public function testSemanticLockTimeoutIsUnavailableAndLeavesNoNode(): void
    {
        Fixture::run(function($f) {
            $worker=new Worker($f,'timeout');
            try {
                $f->atomic(function() use($f,$worker) {
                    $worker->go(); $worker->waitForLock($f); self::assertSame('unavailable:RETRYABLE_LOCK_TIMEOUT',$worker->line());
                },D\LockMode::Share);
                self::assertSame(0,$worker->finish()); self::assertSame(0,$f->catalog->count('dependency_nodes'));
            } finally { $worker->close(); }
        });
    }
}

final class Worker
{
    private mixed $process;
    private array $pipes=[];
    private int $pid;
    public function __construct(Fixture $f,string $mode)
    {
        $role=$f->catalog->db->execute('SELECT current_user')->fetchColumn();
        $this->process=proc_open([PHP_BINARY,__DIR__.'/worker.php',$f->catalog->db->schema,$role,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,dirname(__DIR__,3),getenv());
        if (!is_resource($this->process)) { throw new \RuntimeException('Worker unavailable'); }
        stream_set_timeout($this->pipes[1],10);
        $ready=$this->line(); if (!preg_match('/^READY ([0-9]+)$/D',$ready,$m)) { throw new \RuntimeException('Worker not ready'); }
        $this->pid=(int)$m[1];
    }
    public function go(): void { fwrite($this->pipes[0],"GO\n"); fflush($this->pipes[0]); }
    public function line(): string { $line=fgets($this->pipes[1]); if ($line===false) { throw new \RuntimeException('Worker missing output'); } return trim($line); }
    public function waitForLock(Fixture $f): void
    {
        for($i=0;$i<100;$i++) {
            if ($f->catalog->db->execute('SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid=? AND NOT granted)',[$this->pid])->fetchColumn()) { return; }
            usleep(1000);
        }
        throw new \RuntimeException('Worker did not wait on semantic lock');
    }
    public function finish(): int
    {
        fclose($this->pipes[0]); fclose($this->pipes[1]); $err=stream_get_contents($this->pipes[2]); fclose($this->pipes[2]); $this->pipes=[];
        $exit=proc_close($this->process); $this->process=null;
        if ($err!=='') { throw new \RuntimeException('Worker wrote stderr'); } return $exit;
    }
    public function close(): void
    {
        if (is_resource($this->process)) { proc_terminate($this->process,9); foreach($this->pipes as $pipe) { if(is_resource($pipe)) { fclose($pipe); } } proc_close($this->process); $this->process=null; }
    }
}
