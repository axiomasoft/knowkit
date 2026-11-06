<?php
declare(strict_types=1);

namespace KnowKit\Tests\Storage\Catalog;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

/** Concurrent connection / SIGKILL patterns adapted from pinned AgenTools DurableTest. */
#[Group('storage:catalog')]
final class DurabilityTest extends TestCase
{
    private function worker(CatalogFixture $f,string $mode,string $label='same'): array
    {
        $role=$f->db->pdo->query('SELECT current_user')->fetchColumn(); $pipes=[];
        $process=proc_open([PHP_BINARY,__DIR__.'/worker.php',$f->sandbox->schema,$role,$mode,$label],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,CatalogFixture::root());
        self::assertIsResource($process); stream_set_timeout($pipes[1],10); stream_set_timeout($pipes[2],10);
        $w=[$process,$pipes]; self::assertSame('READY',$this->line($w)); return $w;
    }
    private function line(array $worker): string
    { $line=fgets($worker[1][1]); self::assertNotFalse($line,'Worker failed or timed out'); return trim($line); }
    private function go(array $worker): void { fwrite($worker[1][0],"GO\n"); fflush($worker[1][0]); }
    private function finish(array $worker,bool $kill=false): void
    {
        if ($kill) { proc_terminate($worker[0],9); }
        fclose($worker[1][0]); $error=stream_get_contents($worker[1][2]); fclose($worker[1][2]); fclose($worker[1][1]);
        $exit=proc_close($worker[0]); self::assertSame('',$error); if (!$kill) { self::assertSame(0,$exit); }
    }
    public function testCompetingConnectionsReplaySameAcceptedResult(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $a=$this->worker($f,'write'); $b=$this->worker($f,'write');
            try {
                $this->go($a); $this->go($b); $left=json_decode($this->line($a),true,512,JSON_THROW_ON_ERROR); $right=json_decode($this->line($b),true,512,JSON_THROW_ON_ERROR);
                self::assertSame('accepted',$left['status']); self::assertSame($left,$right);
            } finally { $this->finish($a); $this->finish($b); }
            self::assertSame(1,$f->count('collections')); self::assertSame(2,$f->count('idempotency_receipts'));
        });
    }
    public function testCompetingConnectionsRejectDifferentPayload(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $a=$this->worker($f,'write','left'); $b=$this->worker($f,'write','right');
            try {
                $this->go($a); $this->go($b); $statuses=[json_decode($this->line($a),true)['status'],json_decode($this->line($b),true)['status']]; sort($statuses);
                self::assertSame(['accepted','idempotency_conflict'],$statuses);
            } finally { $this->finish($a); $this->finish($b); }
            self::assertSame(1,$f->count('collections')); self::assertSame(2,$f->count('idempotency_receipts'));
        });
    }
    public function testCrashBeforeCommitLeavesNoSuccessReceiptAndRestartCommitsOnce(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $worker=$this->worker($f,'crash-before');
            try { $this->go($worker); self::assertSame('PRECOMMIT',$this->line($worker)); }
            finally { $this->finish($worker,true); }
            self::assertSame(0,$f->count('collections')); self::assertSame(1,$f->count('idempotency_receipts'));
            $retry=$this->worker($f,'write'); try { $this->go($retry); self::assertSame('accepted',json_decode($this->line($retry),true)['status']); } finally { $this->finish($retry); }
            self::assertSame(1,$f->count('collections')); self::assertSame(2,$f->count('idempotency_receipts'));
        });
    }
    public function testCrashAfterCommitRestartReturnsOriginalReceipt(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $worker=$this->worker($f,'crash-after');
            try { $this->go($worker); $accepted=$this->line($worker); self::assertSame('accepted',json_decode($accepted,true)['status']); }
            finally { $this->finish($worker,true); }
            $retry=$this->worker($f,'write'); try { $this->go($retry); self::assertSame($accepted,$this->line($retry)); } finally { $this->finish($retry); }
            self::assertSame(1,$f->count('collections')); self::assertSame(2,$f->count('idempotency_receipts'));
        });
    }
}
