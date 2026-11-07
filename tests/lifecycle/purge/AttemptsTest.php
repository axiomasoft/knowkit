<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Purge;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:purge')]
final class AttemptsTest extends TestCase
{
    public function testExceptionIsSafeUnknownAndReplayDoesNotRepeatIo(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $f->transport->effect = static function () { throw new \RuntimeException('secret-provider-input /private/path'); };
            $receipt = $f->attempt(3003, 81000); $f->attempt(3003, 81000);
            $a = $f->c()->db->row('SELECT * FROM deletion_attempts WHERE id=?', [Fixture::id(81000)]);
            Fixture::expected('TRANSPORT-EXCEPTION', ['receipt'=>$receipt->toWire()->get('result'), 'task'=>$f->status(),
                'error'=>$a['error_code'], 'calls'=>$f->transport->calls]);
            self::assertStringNotContainsString('secret', json_encode($a));
            $f->transport->effect = null; $f->finishAll(); self::assertSame('done', $f->status());
            self::assertSame('unknown', $f->attempt(3003, 81000)->toWire()->get('result'));
            self::assertSame('unknown', $a['result']);
        });
    }
    public function testUnknownAndUnsupportedRequiredResourcesNeverComplete(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $f->transport->result = 'unsupported'; $f->finishAll();
            Fixture::expected('UNSUPPORTED', ['task'=>$f->status()]);
            $f->transport->result = 'unknown'; $f->attempt(3003);
            Fixture::expected('UNKNOWN-RECEIPT', ['task'=>$f->status()]);
            $f->transport->result = 'deleted'; $f->attempt(3003);
            self::assertSame('partial', $f->status());
        });
    }
    public function testTimeoutAfterDeletionNeedsNewVerificationAndPreservesOldAttempt(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $exists = true;
            $f->transport->effect = static function () use (&$exists) { $exists = false; throw new \RuntimeException('timeout'); };
            $f->attempt(3003, 81000);
            Fixture::expected('TIMEOUT-AFTER-DELETE', ['exists'=>$exists, 'task'=>$f->status()]);
            $f->transport->effect = null; $f->transport->result = 'absent'; $f->attempt(3003);
            self::assertSame('partial', $f->status()); $f->finishAll(); self::assertSame('done', $f->status());
            self::assertSame('unknown', $f->attempt(3003, 81000)->toWire()->get('result'));
        });
    }
    public function testInterruptedAttemptReplayDoesNoIoAndUnresolvedFlightBlocksDone(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $t = $f->lifecycle->tenant; $r = new D\ResourceId(Fixture::id(3003)); $a = new D\AttemptId(Fixture::id(81000));
            $f->store->beginAttempt($t, $f->task, $r, $a);
            self::assertSame('unknown', $f->attempt(3003, 81000)->toWire()->get('result')); self::assertSame(0, $f->transport->calls);
            $f->finishAll(); self::assertSame('partial', $f->status());
            Fixture::failure(fn () => $f->store->interruptAttempt($t, $f->task, $r, $a, static fn () => false), 'deletion_runner_uncertain');
            $f->store->interruptAttempt($t, $f->task, $r, $a, static fn () => true);
            // The newer explicit verification supersedes uncertainty only for aggregation; history remains intact.
            self::assertSame('done', $f->status()); self::assertSame('unknown', $f->attempt(3003, 81000)->toWire()->get('result'));
        });
    }
    public function testUnqualifiedAdapterAndWrongScopeCannotClaimErasure(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $f->transport->proof = false;
            self::assertSame('unknown', $f->attempt(3003)->toWire()->get('result'));
            self::assertSame('unknown', $f->status());
            Fixture::failure(fn () => $f->store->aggregate(new D\TenantId(Fixture::id(9)), $f->task), 'scope_mismatch');
            Fixture::failure(fn () => $f->attempt(4004, 80000), 'deletion_attempt_mismatch');
            self::assertSame(1, $f->transport->calls);
            $f->transport->proof = true; $f->finishAll(); self::assertSame('done', $f->status());
        });
    }
    public function testConfigDetachWithOrphanErrorRetainsLedgerObligations(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $config = ['test'=>true]; unset($config['test']);
            $f->transport->effect = static function ($target) use (&$config) {
                if (!isset($config[$target->storageId])) { throw new \RuntimeException('orphan resource'); }
            };
            $f->finishAll();
            Fixture::expected('DT-I15', ['task'=>$f->status(), 'resources'=>$f->c()->count('resource_ledger'), 'attempts'=>$f->c()->count('deletion_attempts')]);
            self::assertSame(3, $f->c()->count('deletion_task_resources'));
        });
    }

    public function testRetryAfterLaterUnknownRequiresFreshTransportAndPreservesAttemptReplay(): void
    {
        foreach ([false,true] as $interrupted) {
            Fixture::run(function ($f) use ($interrupted) {
                $f->purge(); foreach ([4004,5011] as $id) { $f->attempt($id); }
                $tenant = $f->lifecycle->tenant; $resource = new D\ResourceId(Fixture::id(3003));
                $uncertain = new D\AttemptId(Fixture::id(81002)); $start = null;
                $f->transport->effect = function () use ($f, $tenant, $resource, $uncertain, &$start) {
                    $start = $f->store->beginAttempt($tenant, $f->task, $resource, $uncertain);
                };
                self::assertSame('deleted', $f->attempt(3003,81001)->toWire()->get('result'));
                $f->transport->effect = null;
                if ($interrupted) {
                    $f->store->interruptAttempt($tenant,$f->task,$resource,$uncertain,static fn () => true);
                } else {
                    $receipt = new D\DeletionReceipt(new JsonObject(['attemptId'=>$uncertain->value,'taskId'=>$f->task->value,
                        'resourceId'=>$resource->value,'inFlight'=>false,'result'=>'unknown','providerReceipt'=>null,
                        'knownExpiry'=>null,'observedAt'=>$f->clock->now()->value]),PortFixture::validator());
                    $f->store->finishAttempt($start->target,$uncertain,$receipt);
                }
                self::assertSame('deleted',$f->c()->db->row('SELECT state FROM resource_ledger WHERE tenant_id=? AND id=?',[$tenant->value,$resource->value])['state']);
                self::assertSame('partial',$f->status());
                $history = $f->c()->db->execute('SELECT * FROM deletion_attempts WHERE tenant_id=? AND resource_id=? ORDER BY attempt_sequence',[$tenant->value,$resource->value])->fetchAll(\PDO::FETCH_ASSOC);
                self::assertSame(['deleted','unknown'],array_column($history,'result'));
                $calls = $f->transport->calls; $f->transport->result = 'unknown';
                self::assertSame('unknown',$f->attempt(3003,81003)->toWire()->get('result'));
                self::assertSame($calls+1,$f->transport->calls);
                self::assertSame('partial',$f->status());
                self::assertSame('deleted',$f->attempt(3003,81001)->toWire()->get('result'));
                self::assertSame('unknown',$f->attempt(3003,81002)->toWire()->get('result'));
                self::assertSame('unknown',$f->attempt(3003,81003)->toWire()->get('result'));
                self::assertSame($calls+1,$f->transport->calls);
                $f->transport->result = 'absent';
                self::assertSame('absent',$f->attempt(3003,81004)->toWire()->get('result'));
                self::assertSame($calls+2,$f->transport->calls); self::assertSame('done',$f->status());
                self::assertSame($history,$f->c()->db->execute('SELECT * FROM deletion_attempts WHERE tenant_id=? AND resource_id=? AND id IN (?,?) ORDER BY attempt_sequence',
                    [$tenant->value,$resource->value,Fixture::id(81001),Fixture::id(81002)])->fetchAll(\PDO::FETCH_ASSOC));
            });
        }
    }
}
