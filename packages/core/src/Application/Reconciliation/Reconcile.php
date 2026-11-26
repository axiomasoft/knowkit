<?php
declare(strict_types=1);

namespace KnowKit\Application\Reconciliation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\{RecoveryEvidence, ReconciliationStore};

/** One bounded invocation. Persist-before-publish/CAS patterns adapt the pinned donor recovery code.
 * No implicit daemon, object deletion, writer termination or external receipt promotion.
 */
final readonly class Reconcile
{
    private const NEXT = [
        'journal' => 'objects',
        'objects' => 'ledger',
        'ledger' => 'manifests',
        'manifests' => 'outbox',
        'outbox' => 'canary',
        'canary' => 'ready',
    ];

    public function __construct(
        private ReconciliationStore $store,
        private RecoveryEvidence $evidence,
    ) {
    }

    public function start(D\RecoveryPlan $plan): void
    {
        $this->store->start($plan);
    }

    /** @return array<string, mixed> */
    public function step(D\RecoveryPlan $plan, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Recovery page must be 1..100');
        }
        $before = $this->store->checkpoint($plan);
        $after = $before;
        $rows = [];
        $outcomes = [];
        $stage = $before['stage'];
        if (in_array($stage, ['ready', 'blocked'], true)) {
            return $before;
        }
        if ($stage === 'journal') {
            if ($before['sequence'] === $plan->cutoff->value) {
                if (!hash_equals($plan->journalDigest->value, $before['digest'])) {
                    self::fail('recovery_journal_digest');
                }
                $after = self::next($after, $stage);
            } else {
                $rows = $this->evidence->journal($plan, new D\Decimal($before['sequence']), $limit);
                self::bounded($rows, $limit);
                if ($rows === []) {
                    self::fail('recovery_journal_gap');
                }
                foreach ($rows as $row) {
                    if ($row->get('tenantId') !== $plan->tenantId->value) {
                        self::fail('scope_mismatch', D\Outcome::Denied);
                    }
                    $seq = new D\Decimal($row->get('sequence'));
                    if ($seq->value !== (new D\Decimal($after['sequence']))->next()->value || (int) $seq->value > (int) $plan->cutoff->value) {
                        self::fail('recovery_journal_gap');
                    }
                    $after['sequence'] = $seq->value;
                    $after['digest'] = D\RecoveryDigest::add($after['digest'], $row);
                }
            }
        } elseif ($stage === 'canary') {
            if ($this->evidence->deniedCanary($plan) !== true) {
                self::fail('recovery_canary_failed');
            }
            $after = self::next($after, $stage);
        } else {
            $rows = $stage === 'objects'
                ? $this->evidence->objects($plan, $before['cursor'], $limit)
                : $this->store->scan($plan, $stage, $before['cursor'], $limit);
            self::bounded($rows, $limit);
            foreach ($rows as $row) {
                if ($row->get('tenantId') !== $plan->tenantId->value) {
                    self::fail('scope_mismatch', D\Outcome::Denied);
                }
                $key = $stage === 'objects' ? $row->get('storageId') . '/' . $row->get('storageKey') : $row->get('id');
                if (!is_string($key) || strlen($key) > 1280 || ($after['cursor'] !== null && strcmp($key, $after['cursor']) <= 0)) {
                    self::fail('recovery_cursor_conflict');
                }
                $outcome = match ($stage) {
                    'ledger' => $this->evidence->resource($plan, $row),
                    'manifests' => $this->evidence->rebuild($plan, $row) === true ? 'verified' : 'unknown',
                    'outbox' => 'verified',
                    default => 'listed',
                };
                if (!in_array($outcome, ['listed', 'verified', 'absent', 'unknown', 'unsupported', 'unavailable', 'incompatible'], true)) {
                    self::fail('recovery_probe_contract', D\Outcome::Incompatible);
                }
                $outcomes[] = $outcome;
                $after['cursor'] = $key;
                ++$after['count'];
                $after['digest'] = D\RecoveryDigest::add($after['digest'], $row);
            }
            if ($rows === []) {
                // The store records inventory discrepancies atomically with this transition.
                // Complete the other scans for diagnostics; findings keep the serving gate closed.
                $after = self::next($after, $stage);
            }
        }
        $this->store->advance($plan, $before, $after, $rows, $outcomes);
        return $this->store->checkpoint($plan);
    }

    /**
     * @param array<array-key, mixed> $rows
     */
    private static function bounded(array $rows, int $limit): void
    {
        if (!array_is_list($rows) || count($rows) > $limit) {
            self::fail('recovery_page_limit', D\Outcome::Incompatible);
        }
        foreach ($rows as $row) {
            /** @var mixed $row */
            if (!$row instanceof JsonObject) {
                self::fail('recovery_probe_contract', D\Outcome::Incompatible);
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function next(array $state, string $stage): array
    {
        $state['stage'] = self::NEXT[$stage];
        $state['cursor'] = null;
        $state['count'] = 0;
        $state['digest'] = D\RecoveryDigest::seed();
        return $state;
    }

    private static function fail(string $code, D\Outcome $outcome = D\Outcome::Unknown): never
    {
        throw new D\DomainFailure($outcome, $code);
    }
}
