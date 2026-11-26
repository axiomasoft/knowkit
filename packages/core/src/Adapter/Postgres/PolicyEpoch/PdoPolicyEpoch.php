<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\PolicyEpoch;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{Decimal, DomainFailure, LockMode, Outcome, Scope, SourceId, SourceVersionRef, TenantId, UtcInstant, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

/** Tenant-bound epochs/lifecycle evidence only. Host AccessPolicy remains the authority. */
final readonly class PdoPolicyEpoch
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $transaction,
        private TenantId $tenant,
    ) {
    }

    private function scoped(Scope $scope): void
    {
        $scope->assertTenant($this->tenant);
    }

    /** @param list<SourceVersionRef> $versions */
    public function read(Scope $scope, array $versions = []): JsonObject
    {
        return $this->underSharedGuards($scope, $versions, static fn(JsonObject $binding): JsonObject => $binding);
    }

    /**
     * No host callback or buffer I/O in $check. A later disclosure workflow commits Usage before bytes.
     * @param list<SourceVersionRef> $versions
     */
    public function underSharedGuards(Scope $scope, array $versions, \Closure $check): mixed
    {
        $this->scoped($scope);
        $sources = [];
        $seen = [];
        ValueGuard::objects($versions, SourceVersionRef::class);
        foreach ($versions as $ref) {
            $scope->assertTenant($ref->tenantId);
            $key = $ref->sourceVersionId->value;
            if (isset($seen[$key])) {
                throw new DomainFailure(Outcome::Incompatible, 'duplicate_reference');
            }
            $seen[$key] = true;
            $sources[$ref->sourceId->value] = $ref->sourceId;
        }
        ksort($sources, SORT_STRING);
        return $this->transaction->run(function () use ($scope, $versions, $sources, $check): mixed {
            $this->transaction->lockTenant($this->tenant, LockMode::Share);
            $this->transaction->lockKb($scope->kbId, LockMode::Share);
            $this->transaction->lockSources(array_values($sources), LockMode::Share);
            $tenant = $this->db->row(
                'SELECT tenant_policy_epoch,serving_state FROM ' . $this->db->table('tenants') . ' WHERE id=?',
                [$this->tenant->value],
            );
            $kb = $this->db->row(
                'SELECT kb_policy_epoch,removed_at FROM ' . $this->db->table('knowledge_bases') . ' WHERE tenant_id=? AND id=?',
                [$this->tenant->value, $scope->kbId->value],
            );
            if ($tenant === null || $tenant['serving_state'] !== 'ready') {
                throw new DomainFailure(Outcome::Unavailable, 'tenant_blocked');
            }
            if ($kb === null || $kb['removed_at'] !== null) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }
            $epochs = [];
            $sourceRows = $this->rowsById(
                'SELECT s.id,s.source_policy_epoch,s.lifecycle_epoch FROM ' . $this->db->table('sources') . ' s WHERE s.tenant_id=? AND s.lifecycle_state=\'active\'
                AND s.id IN (SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))
                AND EXISTS (SELECT 1 FROM ' . $this->db->table('source_memberships') . ' m WHERE m.tenant_id=s.tenant_id AND m.source_id=s.id AND m.kb_id=?)
                AND NOT EXISTS (SELECT 1 FROM ' . $this->db->table('tombstones') . ' t WHERE t.tenant_id=s.tenant_id AND t.source_id=s.id)',
                array_keys($sources),
                [$this->tenant->value, $scope->kbId->value],
            );
            foreach ($sources as $source) {
                $row = $sourceRows[$source->value] ?? null;
                if ($row === null) {
                    throw new DomainFailure(Outcome::Denied, 'source_unusable');
                }
                $epochs[] = new JsonObject([
                    'sourceId' => $source->value,
                    'sourcePolicyEpoch' => (string) $row['source_policy_epoch'],
                    'lifecycleEpoch' => (string) $row['lifecycle_epoch'],
                ]);
            }
            $versionRows = $this->rowsById(
                'SELECT v.id,v.source_id,v.source_digest,st.usability FROM ' . $this->db->table('source_versions') . ' v JOIN ' . $this->db->table('source_version_state') .
                ' st ON st.tenant_id=v.tenant_id AND st.id=v.id WHERE v.tenant_id=? AND v.id IN (SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))',
                array_map(static fn (SourceVersionRef $ref): string => $ref->sourceVersionId->value, $versions),
                [$this->tenant->value],
            );
            foreach ($versions as $ref) {
                $row = $versionRows[$ref->sourceVersionId->value] ?? null;
                if ($row === null || $row['source_id'] !== $ref->sourceId->value || $row['source_digest'] !== $ref->sourceDigest->value) {
                    throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
                }
                if ($row['usability'] !== 'usable') {
                    throw new DomainFailure(Outcome::Denied, 'source_unusable');
                }
            }
            $binding = new JsonObject([
                'tenantPolicyEpoch' => (string) $tenant['tenant_policy_epoch'],
                'kbPolicyEpoch' => (string) $kb['kb_policy_epoch'],
                'sources' => $epochs,
            ]);
            $now = new UtcInstant(
                (string) $this->db->execute("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')")->fetchColumn(),
            );
            return $check($binding, $now);
        });
    }

    public function advanceTenant(TenantId $tenant, Decimal $expected): Decimal
    {
        if (!$tenant->equals($this->tenant)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        return $this->transaction->run(function () use ($expected): Decimal {
            $this->transaction->lockTenant($this->tenant, LockMode::Update);
            $next = $expected->next();
            $this->requireEpoch(
                $this->db->execute(
                    'UPDATE ' . $this->db->table('tenants') . ' SET tenant_policy_epoch=? WHERE id=? AND tenant_policy_epoch=?',
                    [$next->value, $this->tenant->value, $expected->value],
                )->rowCount(),
            );
            return $next;
        });
    }

    /** Host calls in response to KB ACL, collection ACL or membership-policy changes. */
    public function advanceKb(Scope $scope): Decimal
    {
        $this->scoped($scope);
        return $this->transaction->run(function () use ($scope): Decimal {
            $this->transaction->lockTenant($this->tenant, LockMode::Update);
            $this->transaction->lockKb($scope->kbId, LockMode::Update);
            $next = $scope->policyEpoch->next();
            $this->requireEpoch(
                $this->db->execute(
                    'UPDATE ' . $this->db->table('knowledge_bases') . ' SET kb_policy_epoch=? WHERE tenant_id=? AND id=? AND kb_policy_epoch=? AND removed_at IS NULL',
                    [$next->value, $this->tenant->value, $scope->kbId->value, $scope->policyEpoch->value],
                )->rowCount(),
            );
            return $next;
        });
    }

    public function advanceSource(Scope $scope, SourceId $source, Decimal $expected): Decimal
    {
        $this->scoped($scope);
        return $this->transaction->run(function () use ($scope, $source, $expected): Decimal {
            $this->transaction->lockTenant($this->tenant, LockMode::Update);
            $this->transaction->lockKb($scope->kbId, LockMode::Share);
            $this->transaction->lockSources([$source], LockMode::Update);
            $next = $expected->next();
            $this->requireEpoch(
                $this->db->execute(
                    'UPDATE ' . $this->db->table('sources') . ' SET source_policy_epoch=? WHERE tenant_id=? AND id=? AND source_policy_epoch=?',
                    [$next->value, $this->tenant->value, $source->value, $expected->value],
                )->rowCount(),
            );
            return $next;
        });
    }

    private function requireEpoch(int $count): void
    {
        if ($count !== 1) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
    }

    /**
     * @param list<string> $ids
     * @param list<string> $params Parameters before the id list, then any that follow it.
     * @return array<string, array<string, mixed>>
     */
    private function rowsById(string $sql, array $ids, array $params): array
    {
        if ($ids === []) {
            return [];
        }
        $bound = $params;
        array_splice($bound, 1, 0, [json_encode(array_values($ids), JSON_THROW_ON_ERROR)]);
        $rows = [];
        foreach ($this->db->execute($sql, $bound)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[$row['id']] = $row;
        }
        return $rows;
    }
}
