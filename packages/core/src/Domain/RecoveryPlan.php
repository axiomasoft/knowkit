<?php
declare(strict_types=1);
namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{Jcs, JsonObject};

/** Trusted backup header plus independently obtained authoritative journal cutoff.
 * Inventory digests describe the complete isolated tree, not merely the rows a scanner found.
 */
final readonly class RecoveryPlan
{
    /** @param array<string,mixed> $inventories Untrusted header values; each must contain count and digest. */
    public function __construct(public TenantId $tenantId, public Ulid $id,
        public Decimal $backupSequence, public Decimal $cutoff, public Sha256 $backupJournalDigest,
        public Sha256 $journalDigest, public array $inventories)
    {
        if ((int)$backupSequence->value > (int)$cutoff->value || count($inventories)!==4
            || array_diff(['objects','ledger','manifests','outbox'],array_keys($inventories)) !== []) {
            throw new \InvalidArgumentException('Complete ordered recovery inventories required');
        }
        foreach ($inventories as $v) {
            if (!$v instanceof JsonObject || !is_int($v->get('count')) || $v->get('count') < 0) {
                throw new \InvalidArgumentException('Bounded inventory count required');
            }
            new Sha256($v->get('digest'));
        }
    }
    public function wire(): JsonObject
    {
        return new JsonObject(['version'=>'recovery:1','tenantId'=>$this->tenantId->value,'id'=>$this->id->value,
            'backupSequence'=>$this->backupSequence->value,'cutoff'=>$this->cutoff->value,
            'backupJournalDigest'=>$this->backupJournalDigest->value,'journalDigest'=>$this->journalDigest->value,
            'inventories'=>new JsonObject($this->inventories)]);
    }
    public function fingerprint(): string { return hash('sha256', Jcs::canonicalize($this->wire())); }
}
