<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\LocalBlob\RecoveryEvidence;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Adapter\Postgres\Reconciliation\PdoReconciliationStore;
use KnowKit\Application\Reconciliation\Reconcile;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Assert;

final readonly class Recovery
{
    public function __construct(
        private Runtime $runtime,
    ) {}

    public function execute(): array
    {
        $r = $this->runtime;
        $plan = $r->options['plan'];
        $store = new PdoReconciliationStore(db: $r->db, tx: $r->tx, tenant: $r->tenant, ids: $r->ids);
        $ledger = new PdoResourceLedger(db: $r->db, tx: $r->tx, tenant: $r->tenant, storageId: 'local-v1', clock: $r->clock);
        $evidence = new RecoveryEvidence(
            tenant: $r->tenant,
            root: $r->options['root'],
            storageId: 'local-v1',
            maxBytes: 1048576,
            journal: static function (D\RecoveryPlan $plan, D\Decimal $after, int $limit) use ($r): array {
                Assert::assertFalse($r->db->pdo->inTransaction());

                return array_slice(array_values(array_filter(
                    $r->options['marks'],
                    static fn (JsonObject $mark): bool => (int) $mark->get('sequence') > (int) $after->value,
                )), 0, $limit);
            },
            rebuild: static fn (): bool => throw new \LogicException('Unexpected projection in blob-only restore fixture'),
            canary: static function () use ($ledger, $r): bool {
                Assert::assertFalse($r->db->pdo->inTransaction());
                if ($r->options['badCanary'] ?? false) {
                    return false;
                }
                try {
                    $ledger->requireAccepted($r->options['ref']);
                } catch (D\DomainFailure $error) {
                    return $error->outcome === D\Outcome::Denied;
                }

                return false;
            },
        );
        $runner = new Reconcile(store: $store, evidence: $evidence);
        $runner->start($plan);
        if ($r->options['restoreBarriers'] ?? false) {
            Process::barrier('RESTORE_STARTED');
            $runner->step(plan: $plan, limit: 1);
            Process::barrier('REPLAYED');
        }
        for ($step = 0; $step < 100; ++$step) {
            $state = $runner->step(plan: $plan, limit: 1);
            if (in_array($state['stage'], ['ready', 'blocked'], true)) {
                return ['stage' => $state['stage'], 'sequence' => $state['sequence']];
            }
        }

        throw new \RuntimeException('Restore did not reach a terminal checkpoint');
    }
}
