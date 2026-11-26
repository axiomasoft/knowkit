<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Reconciliation;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};
use KnowKit\Port\{IdGenerator, ReconciliationStore};

/** Tenant guard and checkpoint CAS adapt the pinned PostgresGraph; P3.7-reuse.json. */
final readonly class PdoReconciliationStore implements ReconciliationStore
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private D\TenantId $tenant,
        private IdGenerator $ids,
    ) {
        if ($tx->db !== $db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    private function scoped(D\RecoveryPlan $plan): void
    {
        if (!$this->tenant->equals($plan->tenantId)) {
            self::fail('scope_mismatch', D\Outcome::Denied);
        }
    }

    private function guarded(D\RecoveryPlan $plan, \Closure $call): mixed
    {
        $this->scoped($plan);
        return $this->tx->run(function () use ($plan, $call) {
            $this->tx->lockTenant($this->tenant, D\LockMode::Update);
            $this->db->execute("SELECT set_config('knowkit.recovery_run',?,true)", [$plan->id->value]);
            return $call();
        });
    }

    public function start(D\RecoveryPlan $plan): void
    {
        $this->guarded($plan, function () use ($plan): void {
            $t = $this->db->row('SELECT * FROM ' . $this->db->table('tenants') . ' WHERE id=?', [$this->tenant->value]);
            if ($t === null) {
                self::fail('scope_mismatch', D\Outcome::Denied);
            }
            $existing = $this->db->row(
                'SELECT fingerprint FROM ' . $this->db->table('reconciliation_runs') . ' WHERE tenant_id=? AND id=?',
                [$this->tenant->value, $plan->id->value],
            );
            if ($existing !== null) {
                if ($existing['fingerprint'] !== $plan->fingerprint() || $t['reconciliation_run_id'] !== $plan->id->value) {
                    self::fail('recovery_identity_conflict', D\Outcome::Incompatible);
                }
                return;
            }
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('reconciliation_runs') .
                " (tenant_id,id,fingerprint,plan_json,stage,digest,recovery_sequence) VALUES (?,?,?,?::jsonb,'journal',?,?)",
                [
                    $this->tenant->value,
                    $plan->id->value,
                    $plan->fingerprint(),
                    Jcs::canonicalize($plan->wire()),
                    $plan->backupJournalDigest->value,
                    $plan->backupSequence->value,
                ],
            );
            $this->db->execute(
                'UPDATE ' . $this->db->table('tenants') . " SET serving_state='blocked',restore_receipt_id=NULL,reconciliation_run_id=? WHERE id=?",
                [$plan->id->value, $this->tenant->value],
            );
        });
    }

    /** @phpstan-impure */
    public function checkpoint(D\RecoveryPlan $plan): array
    {
        $this->scoped($plan);
        $r = $this->db->row(
            'SELECT r.* FROM ' . $this->db->table('reconciliation_runs') . ' r JOIN ' . $this->db->table('tenants') .
            ' t ON t.id=r.tenant_id AND t.reconciliation_run_id=r.id WHERE r.tenant_id=? AND r.id=? AND r.fingerprint=?',
            [$this->tenant->value, $plan->id->value, $plan->fingerprint()],
        );
        if ($r === null) {
            self::fail('recovery_identity_conflict', D\Outcome::Incompatible);
        }
        return [
            'stage' => $r['stage'],
            'cursor' => $r['cursor'],
            'count' => (int) $r['scanned'],
            'digest' => $r['digest'],
            'sequence' => (string) $r['recovery_sequence'],
            'revision' => (string) $r['revision'],
        ];
    }

    public function scan(D\RecoveryPlan $plan, string $stage, ?string $after, int $limit): array
    {
        $this->scoped($plan);
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Bounded scan required');
        }
        if ($after !== null) {
            new D\Ulid($after);
        }
        $table = match ($stage) {
            'ledger' => 'resource_ledger',
            'manifests' => 'index_generations',
            'outbox' => 'outbox',
            default => throw new \InvalidArgumentException('Known scan required'),
        };
        $columns = match ($stage) {
            'ledger' => 'id,source_id,owner_kind,storage_id,storage_key,object_version,staging_id,digest,byte_length::text,state,resource_class,retention_hold,retention_until',
            'manifests' => 'id,kb_id,profile_id,state,manifest_digest,manifest_json::text,embedding_space_digest,chunk_count::text',
            'outbox' => 'id,event_json::text,payload_digest,aggregate_version::text',
        };
        $filter = $stage === 'ledger'
            ? ' AND NOT EXISTS (SELECT 1 FROM ' . $this->db->table('reconciliation_findings') .
              ' f WHERE f.tenant_id=r.tenant_id AND f.run_id=? AND f.resource_id=r.id AND f.registered_resource)'
            : '';
        $values = [$this->tenant->value, $after, $after];
        if ($stage === 'ledger') {
            $values[] = $plan->id->value;
        }
        $rows = $this->db->execute(
            'SELECT ' . $columns . ' FROM ' . $this->db->table($table) . ' r' .
            ' WHERE r.tenant_id=? AND (CAST(? AS text) IS NULL OR r.id>?)' . $filter . ' ORDER BY r.id LIMIT ' . $limit,
            $values,
        )->fetchAll(\PDO::FETCH_ASSOC);
        return array_values(array_map(function ($r) use ($stage): JsonObject {
            $json = match ($stage) {
                'manifests' => 'manifest_json',
                'outbox' => 'event_json',
                default => null,
            };
            if ($json !== null && $r[$json] !== null) {
                $r[$json] = Jcs::canonicalize(StrictJson::decode($r[$json]));
            }
            if ($stage === 'ledger' && $r['retention_until'] !== null) {
                $r['retention_until'] = (new \DateTimeImmutable($r['retention_until']))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.u\Z');
            }
            return new JsonObject(['tenantId' => $this->tenant->value, ...$r]);
        }, $rows));
    }

    public function advance(D\RecoveryPlan $plan, array $before, array $after, array $rows, array $outcomes): void
    {
        $this->guarded($plan, function () use ($plan, $before, $after, $rows, $outcomes): void {
            if ($this->checkpoint($plan) !== $before) {
                self::fail('recovery_checkpoint_conflict', D\Outcome::Incompatible);
            }
            if ($before['stage'] === 'journal') {
                $sources = [];
                foreach ($rows as $row) {
                    $id = new D\SourceId($row->get('sourceId'));
                    if ($this->db->row('SELECT id FROM ' . $this->db->table('sources') . ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $id->value]) !== null) {
                        $sources[$id->value] = $id;
                    }
                }
                ksort($sources, SORT_STRING);
                $this->tx->lockSources(array_values($sources), D\LockMode::Update);
                foreach ($rows as $row) {
                    $this->replay($row);
                }
            } elseif ($before['stage'] === 'objects') {
                foreach ($rows as $row) {
                    $this->object($plan, $row);
                }
            } elseif (in_array($before['stage'], ['ledger', 'manifests', 'outbox'], true)) {
                // Metadata is frozen between scan, external verification, and this commit.
                $actual = $this->scan($plan, $before['stage'], $before['cursor'], max(1, count($rows)));
                if (Jcs::canonicalize($actual) !== Jcs::canonicalize($rows)) {
                    self::fail('recovery_inventory_changed');
                }
                foreach ($rows as $i => $row) {
                    $result = $outcomes[$i];
                    if ($before['stage'] === 'ledger') {
                        $open = $this->db->row(
                            'SELECT id FROM ' . $this->db->table('resource_writers') . " WHERE tenant_id=? AND resource_id=? AND state<>'closed' LIMIT 1",
                            [$this->tenant->value, $row->get('id')],
                        );
                        if ($open !== null || in_array($row->get('state'), ['reserved', 'uploaded', 'unknown', 'deleting'], true)) {
                            $result = 'unknown';
                        }
                        if ($result === 'absent' && $row->get('state') !== 'deleted') {
                            $result = 'missing_object';
                        }
                        if ($result === 'verified' && $row->get('state') === 'deleted') {
                            $result = 'unexpected_object';
                        }
                    }
                    if (!in_array($result, ['verified', 'absent'], true)) {
                        $this->finding($plan, $before['stage'], $row, $result);
                    }
                }
            }
            if (in_array($before['stage'], ['objects', 'ledger', 'manifests', 'outbox'], true) && $rows === [] && $after['stage'] !== $before['stage']) {
                $expected = $plan->inventories[$before['stage']];
                if ($before['count'] !== $expected->get('count') || !hash_equals($expected->get('digest'), $before['digest'])) {
                    // Inventory-level evidence binds the run itself, not an invented resource identity.
                    $this->finding(
                        $plan,
                        $before['stage'],
                        new JsonObject([
                            'tenantId' => $this->tenant->value,
                            'id' => $plan->id->value,
                            'count' => $before['count'],
                            'digest' => $before['digest'],
                            'expectedCount' => $expected->get('count'),
                            'expectedDigest' => $expected->get('digest'),
                        ]),
                        'inventory_incomplete',
                    );
                }
            }
            if ($after['stage'] === 'ready') {
                if ($this->db->row('SELECT id FROM ' . $this->db->table('jobs') . ' WHERE tenant_id=? AND uncertain_external_effect LIMIT 1', [$this->tenant->value]) !== null) {
                    self::fail('recovery_writer_uncertain');
                }
                if ($this->db->row('SELECT resource_id FROM ' . $this->db->table('reconciliation_findings') . ' WHERE tenant_id=? AND run_id=? LIMIT 1', [$this->tenant->value, $plan->id->value]) !== null) {
                    $after['stage'] = 'blocked';
                }
                // Replayed source floors/tombstones are checked again immediately before opening the gate.
                if ($this->db->row(
                    'SELECT s.id FROM ' . $this->db->table('sources') . ' s JOIN ' . $this->db->table('tombstones') .
                    " t ON t.tenant_id=s.tenant_id AND t.source_id=s.id WHERE s.tenant_id=? AND s.lifecycle_state='active' LIMIT 1",
                    [$this->tenant->value],
                ) !== null) {
                    self::fail('recovery_lifecycle_conflict');
                }
            }
            $this->db->execute(
                'UPDATE ' . $this->db->table('reconciliation_runs') .
                ' SET stage=?,cursor=?,scanned=?,digest=?,recovery_sequence=?,revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?',
                [$after['stage'], $after['cursor'], $after['count'], $after['digest'], $after['sequence'], $this->tenant->value, $plan->id->value, $before['revision']],
            );
            if ($after['stage'] === 'ready') {
                $this->db->execute(
                    'INSERT INTO ' . $this->db->table('restore_receipts') . " VALUES (?,?,?,true,?,?,true,'ready')",
                    [$this->tenant->value, $plan->id->value, $plan->cutoff->value, $after['sequence'], $plan->journalDigest->value],
                );
                $this->db->execute(
                    'UPDATE ' . $this->db->table('tenants') . " SET serving_state='ready',restore_receipt_id=? WHERE id=?",
                    [$plan->id->value, $this->tenant->value],
                );
            }
        });
    }

    private function replay(JsonObject $row): void
    {
        if ($row->get('tenantId') !== $this->tenant->value) {
            self::fail('scope_mismatch', D\Outcome::Denied);
        }
        $id = new D\SourceId($row->get('sourceId'));
        $epoch = new D\Decimal($row->get('lifecycleEpoch'));
        $policy = new D\Decimal($row->get('sourcePolicyEpoch'));
        $state = D\SourceLifecycleState::from($row->get('lifecycleState'));
        $hold = $row->get('retentionHold');
        if (!is_bool($hold)) {
            self::fail('recovery_journal_invalid', D\Outcome::Incompatible);
        }
        $old = $this->db->row('SELECT * FROM ' . $this->db->table('sources') . ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $id->value]);
        $floor = $this->db->row('SELECT * FROM ' . $this->db->table('recovery_watermarks') . ' WHERE tenant_id=? AND source_id=?', [$this->tenant->value, $id->value]);
        foreach ([$old, $floor] as $existing) {
            if ($existing === null) {
                continue;
            }
            $existingLifecycle = new D\Decimal((string) $existing['lifecycle_epoch']);
            $existingPolicy = new D\Decimal((string) $existing['source_policy_epoch']);
            if (
                self::compare($existingLifecycle, $epoch) > 0
                || self::compare($existingPolicy, $policy) > 0
                || ($existing['lifecycle_state'] !== 'active' && $state === D\SourceLifecycleState::Active)
                || ((bool) $existing['retention_hold'] && !$hold && self::compare($epoch, $existingLifecycle) <= 0)
            ) {
                self::fail('recovery_lifecycle_regression');
            }
        }
        $tombstone = $row->get('tombstone');
        if ($tombstone !== null) {
            if (!$tombstone instanceof JsonObject || $state === D\SourceLifecycleState::Active) {
                self::fail('recovery_journal_invalid', D\Outcome::Incompatible);
            }
            $t = new D\Tombstone(
                $this->tenant,
                $id,
                $tombstone->get('reason'),
                new D\EventId($tombstone->get('eventId')),
                new D\Decimal($tombstone->get('lifecycleEpoch')),
            );
            $existing = $this->db->row('SELECT * FROM ' . $this->db->table('tombstones') . ' WHERE tenant_id=? AND source_id=?', [$this->tenant->value, $id->value]);
            if (
                self::compare($t->lifecycleEpoch, $epoch) > 0
                || ($existing !== null && ($existing['event_id'] !== $t->eventId->value || $existing['reason'] !== $t->reason || (string) $existing['lifecycle_epoch'] !== $t->lifecycleEpoch->value))
            ) {
                self::fail('recovery_tombstone_conflict');
            }
            if ($existing === null) {
                $this->db->execute(
                    'INSERT INTO ' . $this->db->table('tombstones') . " VALUES (?,?,?,?,?,?,'future_versions')",
                    [$this->tenant->value, $t->eventId->value, $id->value, $t->reason, $t->eventId->value, $t->lifecycleEpoch->value],
                );
            }
        } elseif (
            $state !== D\SourceLifecycleState::Active
            || $this->db->row('SELECT id FROM ' . $this->db->table('tombstones') . ' WHERE tenant_id=? AND source_id=?', [$this->tenant->value, $id->value]) !== null
        ) {
            self::fail('recovery_tombstone_missing');
        }
        $this->db->execute(
            'INSERT INTO ' . $this->db->table('recovery_watermarks') .
            ' VALUES (?,?,?,?,?,?) ON CONFLICT (tenant_id,source_id) DO UPDATE SET lifecycle_epoch=EXCLUDED.lifecycle_epoch,source_policy_epoch=EXCLUDED.source_policy_epoch,lifecycle_state=EXCLUDED.lifecycle_state,retention_hold=EXCLUDED.retention_hold',
            [$this->tenant->value, $id->value, $epoch->value, $policy->value, $state->value, $hold],
        );
        if ($old !== null) {
            $this->db->execute(
                'UPDATE ' . $this->db->table('sources') . ' SET lifecycle_epoch=?,source_policy_epoch=?,lifecycle_state=?,retention_hold=?,revision=revision+1 WHERE tenant_id=? AND id=?',
                [$epoch->value, $policy->value, $state->value, $hold, $this->tenant->value, $id->value],
            );
        }
        $versions = $row->get('versions');
        if (!is_array($versions) || !array_is_list($versions) || count($versions) > 256) {
            self::fail('recovery_version_marks_missing');
        }
        foreach ($versions as $v) {
            if (!$v instanceof JsonObject) {
                self::fail('recovery_version_marks_missing');
            }
            $version = new D\SourceVersionId($v->get('sourceVersionId'));
            $usability = D\Usability::from($v->get('usability'));
            $storedVersion = $this->db->row('SELECT source_id FROM ' . $this->db->table('source_versions') . ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $version->value]);
            if ($storedVersion !== null && $storedVersion['source_id'] !== $id->value) {
                self::fail('recovery_version_source_mismatch');
            }
            if ($usability === D\Usability::Usable) {
                self::fail('recovery_version_regression');
            }
            $event = new D\EventId($v->get('eventId'));
            $replacement = $v->get('correctedByVersionId');
            if ($replacement !== null) {
                new D\SourceVersionId($replacement);
            }
            try {
                new D\SourceVersionState(
                    $this->tenant,
                    $version,
                    $usability,
                    $replacement === null ? null : new D\SourceVersionId($replacement),
                    $event,
                );
            } catch (\InvalidArgumentException) {
                self::fail('recovery_version_mark_invalid', D\Outcome::Incompatible);
            }
            $replacementRow = $replacement === null
                ? null
                : $this->db->row('SELECT source_id FROM ' . $this->db->table('source_versions') . ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $replacement]);
            if ($replacementRow !== null && $replacementRow['source_id'] !== $id->value) {
                self::fail('recovery_version_source_mismatch');
            }
            $eventRow = $this->db->row('SELECT source_id,source_version_id FROM ' . $this->db->table('lifecycle_events') . ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $event->value]);
            if ($eventRow !== null && ($eventRow['source_id'] !== $id->value || ($eventRow['source_version_id'] !== null && $eventRow['source_version_id'] !== $version->value))) {
                self::fail('recovery_version_conflict');
            }
            $mark = $this->db->row('SELECT * FROM ' . $this->db->table('recovery_version_marks') . ' WHERE tenant_id=? AND version_id=?', [$this->tenant->value, $version->value]);
            if (
                $mark !== null
                && ($mark['source_id'] !== $id->value || $mark['usability'] !== $usability->value || $mark['event_id'] !== $event->value || $mark['corrected_by_version_id'] !== $replacement)
            ) {
                self::fail('recovery_version_conflict');
            }
            if ($mark === null) {
                $this->db->execute(
                    'INSERT INTO ' . $this->db->table('recovery_version_marks') . ' VALUES (?,?,?,?,?,?)',
                    [$this->tenant->value, $id->value, $version->value, $usability->value, $event->value, $replacement],
                );
            }
            // Marks retain the exact authoritative identities even when their post-backup FK targets
            // have not been restored. Never synthesize an event or a replacement version to satisfy a FK.
            $this->db->execute(
                'UPDATE ' . $this->db->table('source_version_state') . ' st SET usability=?,event_id=?,corrected_by_version_id=? FROM ' . $this->db->table('source_versions') . ' v WHERE st.tenant_id=v.tenant_id AND st.id=v.id AND v.tenant_id=? AND v.source_id=? AND v.id=?',
                [$usability->value, $eventRow === null ? null : $event->value, $replacementRow === null ? null : $replacement, $this->tenant->value, $id->value, $version->value],
            );
        }
    }

    private function object(D\RecoveryPlan $plan, JsonObject $row): void
    {
        if ($row->get('tenantId') !== $this->tenant->value) {
            self::fail('scope_mismatch', D\Outcome::Denied);
        }
        $key = $row->get('storageKey');
        if (!is_string($key) || !str_starts_with($key, $this->tenant->value . '/') || strlen($key) > 1024) {
            self::fail('scope_mismatch', D\Outcome::Denied);
        }
        if ($row->get('sourceId') === null) {
            $this->finding($plan, 'objects', $row, 'unknown_object');
            return;
        }
        $source = new D\SourceId($row->get('sourceId'));
        if (!str_starts_with($key, $this->tenant->value . '/' . $source->value . '/')) {
            self::fail('scope_mismatch', D\Outcome::Denied);
        }
        $r = $this->db->row(
            'SELECT * FROM ' . $this->db->table('resource_ledger') . ' WHERE tenant_id=? AND source_id=? AND storage_id=? AND storage_key=?',
            [$this->tenant->value, $source->value, $row->get('storageId'), $key],
        );
        if (
            $r !== null
            && $r['state'] !== 'deleted'
            && $r['state'] !== 'unknown'
            && (($r['staging_id'] !== null && $r['staging_id'] === $row->get('stagingId')) || $r['object_version'] === $row->get('objectVersion'))
        ) {
            return;
        }
        $resource = $this->finding($plan, 'objects', $r === null ? $row : $row->with('id', $r['id']), 'unknown_object');
        if ($r === null && $this->db->row('SELECT id FROM ' . $this->db->table('sources') . ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $source->value]) !== null) {
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('resource_ledger') . " (tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,state,retention_hold) VALUES (?,?,?,'owned',?,?,?,'unknown',true) ON CONFLICT (tenant_id,id) DO NOTHING",
                [$this->tenant->value, $resource, $source->value, $row->get('storageId'), $key, $row->get('objectVersion')],
            );
            $this->db->execute(
                'UPDATE ' . $this->db->table('reconciliation_findings') . ' SET registered_resource=true WHERE tenant_id=? AND run_id=? AND resource_id=? AND stage=?',
                [$this->tenant->value, $plan->id->value, $resource, 'objects'],
            );
        }
    }

    private function finding(D\RecoveryPlan $plan, string $stage, JsonObject $row, string $outcome): string
    {
        $digest = hash('sha256', $stage . '\n' . Jcs::canonicalize($row));
        $id = $row->get('id') ?? $this->ids->next()->value;
        $this->db->execute(
            'INSERT INTO ' . $this->db->table('reconciliation_findings') .
            ' (tenant_id,run_id,identity_digest,resource_id,source_id,stage,outcome) VALUES (?,?,?,?,?,?,?) ON CONFLICT DO NOTHING',
            [$this->tenant->value, $plan->id->value, $digest, $id, $row->get('sourceId') ?? $row->get('source_id'), $stage, $outcome],
        );
        $stored = $this->db->row(
            'SELECT resource_id FROM ' . $this->db->table('reconciliation_findings') . ' WHERE tenant_id=? AND run_id=? AND identity_digest=?',
            [$this->tenant->value, $plan->id->value, $digest],
        );
        if ($stored === null) {
            self::fail('storage_unavailable', D\Outcome::Unavailable);
        }
        return (string) $stored['resource_id'];
    }

    private static function compare(D\Decimal $a, D\Decimal $b): int
    {
        return strlen($a->value) <=> strlen($b->value) ?: strcmp($a->value, $b->value);
    }

    private static function fail(string $code, D\Outcome $outcome = D\Outcome::Unknown): never
    {
        throw new D\DomainFailure($outcome, $code);
    }
}
