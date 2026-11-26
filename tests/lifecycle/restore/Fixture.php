<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Restore;

use KnowKit\Adapter\LocalBlob\RecoveryEvidence;
use KnowKit\Adapter\Postgres\Reconciliation\PdoReconciliationStore;
use KnowKit\Application\Reconciliation\Reconcile;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__,2).'/storage/catalog/bootstrap.php';
require_once dirname(__DIR__,2).'/storage/blobs/BlobFixture.php';

final class Fixture
{
    public readonly D\TenantId $tenant;
    public readonly D\BlobRef $ref;
    public readonly PdoReconciliationStore $store;
    public array $marks = [];
    public ?\Closure $canary = null;
    public ?\Closure $external = null;
    public ?\Closure $rebuild = null;
    public function __construct(public readonly BlobFixture $blobs)
    {
        $c = $blobs->catalog; $this->tenant = $c->scope()->tenantId; $this->ref = $blobs->put();
        $this->store = new PdoReconciliationStore($c->db,$c->tx,$this->tenant,$c->ids);
    }
    public static function run(\Closure $test): void { BlobFixture::run(fn($b) => $test(new self($b))); }
    public static function id(int $n): string { return BlobFixture::id($n); }
    public function evidence(): RecoveryEvidence
    {
        return new RecoveryEvidence($this->tenant,$this->blobs->root,'local-v1',1048576,
            function($p,$after,$limit): array {
                Assert::assertFalse($this->blobs->catalog->db->pdo->inTransaction());
                return array_slice(array_values(array_filter($this->marks,fn($m)=>(int)$m->get('sequence')>(int)$after->value)),0,$limit);
            },
            fn($plan,$generation)=>$this->rebuild === null ? throw new \LogicException('No manifest in the blob-only fixture') : ($this->rebuild)($plan,$generation),
            function(): bool {
                Assert::assertFalse($this->blobs->catalog->db->pdo->inTransaction());
                if ($this->canary !== null) { return ($this->canary)(); }
                try { $this->blobs->store->open($this->ref); return false; }
                catch (D\DomainFailure $e) { return $e->outcome === D\Outcome::Denied; }
            },$this->external);
    }
    public function runner(): Reconcile { return new Reconcile($this->store,$this->evidence()); }
    public function mark(int $source=2, bool $hold=false): JsonObject
    {
        $mark = self::data()->get('deleteMark')->with('sourceId',self::id($source))->with('retentionHold',$hold);
        $this->marks = [$mark]; return $mark;
    }
    /** Capture header before introducing missing-object/metadata faults. */
    public function plan(int $id=90000, ?D\Decimal $cutoff=null): D\RecoveryPlan
    {
        $seed = new D\Sha256(D\RecoveryDigest::seed());
        $dummy = new D\RecoveryPlan($this->tenant,new D\Ulid(self::id($id)),new D\Decimal('0'),$cutoff??new D\Decimal((string)count($this->marks)),$seed,$seed,
            array_fill_keys(['objects','ledger','manifests','outbox'],new JsonObject(['count'=>0,'digest'=>$seed->value])));
        $inventories = [];
        foreach (['objects','ledger','manifests','outbox'] as $stage) {
            $rows = $stage==='objects' ? $this->evidence()->objects($dummy,null,100) : $this->store->scan($dummy,$stage,null,100);
            $inventories[$stage] = self::inventory($rows);
        }
        $digest = D\RecoveryDigest::seed(); foreach ($this->marks as $mark) { $digest = D\RecoveryDigest::add($digest,$mark); }
        return new D\RecoveryPlan($this->tenant,$dummy->id,$dummy->backupSequence,$dummy->cutoff,$seed,new D\Sha256($digest),$inventories);
    }
    public static function inventory(array $rows): JsonObject
    {
        $digest = D\RecoveryDigest::seed(); foreach ($rows as $row) { $digest = D\RecoveryDigest::add($digest,$row); }
        return new JsonObject(['count'=>count($rows),'digest'=>$digest]);
    }
    public static function fromWire(JsonObject $w): D\RecoveryPlan
    {
        $inventories = []; foreach ($w->get('inventories') as $k=>$v) { $inventories[$k]=$v; }
        // Canonical JSON sorts keys; restore the contract's stage order.
        $inventories = array_replace(array_fill_keys(['objects','ledger','manifests','outbox'],null),$inventories);
        return new D\RecoveryPlan(new D\TenantId($w->get('tenantId')),new D\Ulid($w->get('id')),new D\Decimal($w->get('backupSequence')),
            new D\Decimal($w->get('cutoff')),new D\Sha256($w->get('backupJournalDigest')),new D\Sha256($w->get('journalDigest')),$inventories);
    }
    public function finish(D\RecoveryPlan $plan, int $limit=1): array
    {
        $runner = $this->runner();
        for ($i=0;$i<100;$i++) {
            $state = $runner->step($plan,$limit);
            if (in_array($state['stage'],['ready','blocked'],true)) { return $state; }
        }
        Assert::fail('Recovery failed to terminate within fixture bound');
    }
    public function serving(): string { return $this->blobs->catalog->db->execute('SELECT serving_state FROM tenants WHERE id=?',[$this->tenant->value])->fetchColumn(); }
    public static function failure(\Closure $call, string $code, D\Outcome $outcome=D\Outcome::Unknown): void
    {
        try { $call(); Assert::fail('Expected '.$code); }
        catch (D\DomainFailure $e) { Assert::assertSame($code,$e->errorCode); Assert::assertSame($outcome,$e->outcome); }
    }
    public static function data(): JsonObject
    {
        static $data;
        return $data ??= StrictJson::decode(file_get_contents(__DIR__.'/fixtures/v1/cases.json'));
    }
    public static function expected(string $id, array $actual): void
    {
        static $expected;
        if ($expected === null) {
            $expected = [];
            foreach (self::data()->get('cases') as $case) {
                $expected[$case->get('id')] = json_decode(Jcs::canonicalize($case->get('expected')), true);
            }
        }
        if (!array_key_exists($id, $expected)) { Assert::fail('Unknown fixture ID '.$id); }
        ksort($actual, SORT_STRING);
        Assert::assertSame($expected[$id], $actual, $id);
    }
}
