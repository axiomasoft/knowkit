<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Layers;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{
    ActorId,
    DomainFailure,
    Fingerprint,
    KbId,
    LocalId,
    LockMode,
    Outcome,
    Sha256,
    SnapshotId,
    SnapshotRef,
    SourceDigest,
    SourceId,
    SourceVersionId,
    SourceVersionRef,
    TenantId,
    Ulid,
    UtcInstant,
};
use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};
use KnowKit\Domain\Layers\{
    DeliveryMode,
    DirectiveArtifact,
    DisclosureMode,
    LayerBacking,
    LayerBudgetPolicy,
    LayerId,
    LayerNode,
    MergeStrategy,
    ResolutionTier,
    ResolvedLayerStack,
    TrustTier,
};
use KnowKit\Port\{Clock, IdGenerator, LayerStore};

/** PostgreSQL 17 adapter for DirectiveArtifact, LayerNode definitions, and ResolvedLayerStack. */
final readonly class PdoLayerStore implements LayerStore
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    public function saveDirective(DirectiveArtifact $artifact): void
    {
        $this->inTx($artifact->tenantId, LockMode::Update, function () use ($artifact): void {
            $derivedFromWire = array_map(
                static fn(SourceVersionRef $ref): JsonObject => $ref->toWire(),
                $artifact->derivedFrom,
            );
            $digest = $artifact->artifactDigest()->value;

            $this->db->execute(
                'INSERT INTO ' . $this->db->table('directive_artifacts') .
                ' (tenant_id, id, version, title, content_format, body, body_digest, locked_rules, promoted_by, promoted_at, derived_from, approval_digest, artifact_digest, dependency_node_id)' .
                ' VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?::jsonb, ?, ?, ?)' .
                ' ON CONFLICT (tenant_id, id) DO NOTHING',
                [
                    $artifact->tenantId->value,
                    $artifact->directiveId->value,
                    $artifact->version,
                    $artifact->title,
                    $artifact->contentFormat,
                    $artifact->body,
                    $artifact->bodyDigest->value,
                    Jcs::canonicalize($artifact->lockedRules),
                    $artifact->promotedBy->value,
                    $artifact->promotedAt->value,
                    Jcs::canonicalize($derivedFromWire),
                    $artifact->approvalDigest->value,
                    $digest,
                    $artifact->directiveId->value,
                ],
            );

            $stored = $this->db->row(
                'SELECT artifact_digest FROM ' . $this->db->table('directive_artifacts') . ' WHERE tenant_id = ? AND id = ?',
                [$artifact->tenantId->value, $artifact->directiveId->value],
            );
            if ($stored === null || $stored['artifact_digest'] !== $digest) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
        });
    }

    public function findDirective(Ulid $tenantId, Ulid $directiveId): ?DirectiveArtifact
    {
        $tenant = self::toTenantId($tenantId);

        /** @var ?DirectiveArtifact */
        return $this->inTx($tenant, LockMode::Share, function () use ($tenant, $directiveId): ?DirectiveArtifact {
            $row = $this->db->row(
                'SELECT version, title, content_format, body, body_digest, locked_rules, promoted_by,' .
                ' to_char(promoted_at AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS promoted_at_utc,' .
                ' derived_from, approval_digest' .
                ' FROM ' . $this->db->table('directive_artifacts') .
                ' WHERE tenant_id = ? AND id = ?',
                [$tenant->value, $directiveId->value],
            );
            if ($row === null) {
                return null;
            }

            $lockedRules = StrictJson::decode((string) $row['locked_rules']);
            if (!$lockedRules instanceof JsonObject) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }

            $derivedFromRaw = StrictJson::decode((string) $row['derived_from']);
            if (!is_array($derivedFromRaw)) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
            $derivedFrom = [];
            foreach ($derivedFromRaw as $item) {
                if (!$item instanceof JsonObject) {
                    throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
                }
                $refTenant = new TenantId((string) $item->get('tenantId'));
                if (!$refTenant->equals($tenant)) {
                    throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
                }
                $derivedFrom[] = new SourceVersionRef(
                    $refTenant,
                    new SourceId((string) $item->get('sourceId')),
                    new SourceVersionId((string) $item->get('sourceVersionId')),
                    new SourceDigest((string) $item->get('sourceDigest')),
                );
            }

            $promotedAtUtc = (string) $row['promoted_at_utc'];
            if (str_ends_with($promotedAtUtc, '.000000Z')) {
                $promotedAtUtc = substr($promotedAtUtc, 0, -8) . 'Z';
            }

            return new DirectiveArtifact(
                tenantId: $tenant,
                directiveId: $directiveId,
                version: (int) $row['version'],
                title: (string) $row['title'],
                contentFormat: (string) $row['content_format'],
                body: (string) $row['body'],
                bodyDigest: new Sha256((string) $row['body_digest']),
                lockedRules: $lockedRules,
                promotedBy: new ActorId((string) $row['promoted_by']),
                promotedAt: new UtcInstant($promotedAtUtc),
                derivedFrom: $derivedFrom,
                approvalDigest: new Sha256((string) $row['approval_digest']),
            );
        });
    }

    public function saveLayerNode(Ulid $tenantId, LayerNode $node): void
    {
        $tenant = self::toTenantId($tenantId);
        $this->inTx($tenant, LockMode::Update, function () use ($tenant, $node): void {
            $nodeDigest = $node->nodeDigest()->value;
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('layer_definitions') .
                ' (tenant_id, id, layer_key, trust_tier, delivery_mode, merge_strategy, disclosure_mode, definition_json, node_digest, created_at)' .
                ' VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)' .
                ' ON CONFLICT (tenant_id, node_digest) DO NOTHING',
                [
                    $tenant->value,
                    $this->ids->next()->value,
                    $node->layerId->value,
                    $node->trustTier->value,
                    $node->deliveryMode->value,
                    $node->mergeStrategy->value,
                    $node->disclosureMode->value,
                    Jcs::canonicalize($node->toWire()),
                    $nodeDigest,
                    $this->clock->now()->value,
                ],
            );
        });
    }

    public function saveResolvedStack(ResolvedLayerStack $stack): void
    {
        $this->inTx($stack->tenantId, LockMode::Update, function () use ($stack): void {
            $stackDigest = $stack->stackDigest->value;
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('layer_stacks') .
                ' (tenant_id, id, stack_json, static_prefix_digest, session_prefix_digest, stack_digest, created_at)' .
                ' VALUES (?, ?, ?::jsonb, ?, ?, ?, ?)' .
                ' ON CONFLICT (tenant_id, id) DO NOTHING',
                [
                    $stack->tenantId->value,
                    $stack->stackId->value,
                    Jcs::canonicalize($stack->toWire()),
                    $stack->staticPrefixDigest->value,
                    $stack->sessionPrefixDigest->value,
                    $stackDigest,
                    $this->clock->now()->value,
                ],
            );

            $stored = $this->db->row(
                'SELECT stack_digest FROM ' . $this->db->table('layer_stacks') . ' WHERE tenant_id = ? AND id = ?',
                [$stack->tenantId->value, $stack->stackId->value],
            );
            if ($stored === null || $stored['stack_digest'] !== $stackDigest) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
        });
    }

    public function findResolvedStack(Ulid $tenantId, Ulid $stackId): ?ResolvedLayerStack
    {
        $tenant = self::toTenantId($tenantId);

        /** @var ?ResolvedLayerStack */
        return $this->inTx($tenant, LockMode::Share, function () use ($tenant, $stackId): ?ResolvedLayerStack {
            $row = $this->db->row(
                'SELECT stack_json FROM ' . $this->db->table('layer_stacks') . ' WHERE tenant_id = ? AND id = ?',
                [$tenant->value, $stackId->value],
            );
            if ($row === null) {
                return null;
            }

            $wire = StrictJson::decode((string) $row['stack_json']);
            if (!$wire instanceof JsonObject) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
            if ((string) $wire->get('tenantId') !== $tenant->value) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }

            $nodes = [];
            foreach ($wire->get('nodes') as $nodeWire) {
                if (!$nodeWire instanceof JsonObject) {
                    throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
                }
                $nodes[] = self::hydrateLayerNode($nodeWire);
            }

            $lockedKeyOwners = [];
            $ownersWire = $wire->get('lockedKeyOwners');
            if ($ownersWire instanceof JsonObject) {
                foreach ($ownersWire as $key => $ownerWire) {
                    if (!$ownerWire instanceof JsonObject) {
                        throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
                    }
                    $lockedKeyOwners[$key] = [
                        'layerId' => new LayerId((string) $ownerWire->get('layerId')),
                        'valueDigest' => new Sha256((string) $ownerWire->get('valueDigest')),
                    ];
                }
            }

            $denySelectors = [];
            foreach ($wire->get('effectiveDenySelectors') as $selector) {
                $denySelectors[] = new LocalId((string) $selector);
            }

            return new ResolvedLayerStack(
                tenantId: $tenant,
                stackId: new Ulid((string) $wire->get('stackId')),
                nodes: $nodes,
                lockedKeyOwners: $lockedKeyOwners,
                effectiveDenySelectors: $denySelectors,
                staticPrefixDigest: new Fingerprint((string) $wire->get('staticPrefixDigest')),
                sessionPrefixDigest: new Fingerprint((string) $wire->get('sessionPrefixDigest')),
                stackDigest: new Fingerprint((string) $wire->get('stackDigest')),
            );
        });
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function inTx(TenantId $tenantId, LockMode $mode, \Closure $fn): mixed
    {
        if ($this->db->pdo->inTransaction()) {
            $this->tx->requireTenant($tenantId, $mode === LockMode::Update);
            return $fn();
        }
        return $this->tx->run(function () use ($tenantId, $mode, $fn): mixed {
            $this->tx->lockTenant($tenantId, $mode);
            return $fn();
        });
    }

    private static function toTenantId(Ulid $tenantId): TenantId
    {
        return $tenantId instanceof TenantId ? $tenantId : new TenantId($tenantId->value);
    }

    private static function hydrateLayerNode(JsonObject $wire): LayerNode
    {
        $dependsOn = array_values(array_map(
            static fn(mixed $id): LayerId => new LayerId((string) $id),
            $wire->get('dependsOnLayerIds'),
        ));
        $denySelectors = array_values(array_map(
            static fn(mixed $id): LocalId => new LocalId((string) $id),
            $wire->get('denySelectors'),
        ));
        /** @var list<string> $lockedKeys */
        $lockedKeys = array_values(array_map(static fn(mixed $k): string => (string) $k, $wire->get('lockedKeys')));

        $ruleValues = $wire->get('ruleValues');
        if (!$ruleValues instanceof JsonObject) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $budgetWire = $wire->get('budget');
        if (!$budgetWire instanceof JsonObject) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $backingWire = $wire->get('backing');
        if (!$backingWire instanceof JsonObject) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }

        return new LayerNode(
            layerId: new LayerId((string) $wire->get('layerId')),
            dependsOnLayerIds: $dependsOn,
            trustTier: TrustTier::from((string) $wire->get('trustTier')),
            deliveryMode: DeliveryMode::from((string) $wire->get('deliveryMode')),
            mergeStrategy: MergeStrategy::from((string) $wire->get('mergeStrategy')),
            disclosureMode: DisclosureMode::from((string) $wire->get('disclosureMode')),
            lockedKeys: $lockedKeys,
            ruleValues: $ruleValues,
            denySelectors: $denySelectors,
            budget: new LayerBudgetPolicy(
                minGuaranteedTokens: (int) $budgetWire->get('minGuaranteedTokens'),
                maxCeilingTokens: (int) $budgetWire->get('maxCeilingTokens'),
                maxBytes: (int) $budgetWire->get('maxBytes'),
                weightBps: (int) $budgetWire->get('weightBps'),
                preferredTier: ResolutionTier::from((string) $budgetWire->get('preferredTier')),
                compactFallbackTier: ResolutionTier::from((string) $budgetWire->get('compactFallbackTier')),
            ),
            backing: self::hydrateBacking($backingWire),
        );
    }

    private static function hydrateBacking(JsonObject $wire): LayerBacking
    {
        $kind = (string) $wire->get('kind');
        return match ($kind) {
            'directive' => LayerBacking::directive(
                new Ulid((string) $wire->get('directiveId')),
                new Sha256((string) $wire->get('directiveDigest')),
            ),
            'snapshot' => self::hydrateSnapshotBacking($wire->get('snapshotRef')),
            'catalog_mount' => LayerBacking::catalogMount(
                new Ulid((string) $wire->get('catalogMountId')),
                new TenantId((string) $wire->get('catalogTenantId')),
                new Sha256((string) $wire->get('mountGrantDigest')),
            ),
            'tool_catalog' => LayerBacking::toolCatalog(
                array_values(array_map(static fn(mixed $id): LocalId => new LocalId((string) $id), $wire->get('serverIds'))),
            ),
            'overlay' => LayerBacking::overlay(new LocalId((string) $wire->get('overlayKey'))),
            default => throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict'),
        };
    }

    private static function hydrateSnapshotBacking(mixed $ref): LayerBacking
    {
        if (!$ref instanceof JsonObject) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        return LayerBacking::snapshot(new SnapshotRef(
            new TenantId((string) $ref->get('tenantId')),
            new KbId((string) $ref->get('kbId')),
            new SnapshotId((string) $ref->get('snapshotId')),
            new Fingerprint((string) $ref->get('manifestDigest')),
        ));
    }
}
