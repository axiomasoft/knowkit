<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Artifacts;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{DomainFailure, LockMode, Outcome, TenantId, Ulid};
use KnowKit\Domain\Artifacts\{DerivedArtifactRef, ResolutionManifest};
use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Port\{Clock, ResolutionStore};

/** PostgreSQL 17 adapter for ResolutionManifest and R0-R2 resolution artifacts. */
final readonly class PdoResolutionStore implements ResolutionStore
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private Clock $clock,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    public function saveManifest(ResolutionManifest $manifest): void
    {
        $tenantId = $manifest->documentRevisionRef->sourceRef->tenantId;
        $revisionId = $manifest->documentRevisionRef->documentRevisionId;

        $this->inTx($tenantId, LockMode::Update, function () use ($manifest, $tenantId, $revisionId): void {
            $now = $this->clock->now()->value;
            $manifestJson = Jcs::canonicalize($manifest->toWire());

            $this->db->execute(
                'INSERT INTO ' . $this->db->table('resolution_manifests') .
                ' (tenant_id, id, document_revision_id, processing_digest, manifest_json, manifest_digest, created_at)' .
                ' VALUES (?, ?, ?, ?, ?::jsonb, ?, ?)' .
                ' ON CONFLICT DO NOTHING',
                [
                    $tenantId->value,
                    $manifest->manifestId->value,
                    $revisionId->value,
                    $manifest->processingDigest->value,
                    $manifestJson,
                    $manifest->manifestDigest->value,
                    $now,
                ],
            );

            $stored = $this->db->row(
                'SELECT id, manifest_digest FROM ' . $this->db->table('resolution_manifests') .
                ' WHERE tenant_id = ? AND document_revision_id = ? AND processing_digest = ?',
                [$tenantId->value, $revisionId->value, $manifest->processingDigest->value],
            );
            if ($stored === null
                || $stored['id'] !== $manifest->manifestId->value
                || $stored['manifest_digest'] !== $manifest->manifestDigest->value
            ) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }

            foreach ($manifest->artifacts as $artifact) {
                $this->db->execute(
                    'INSERT INTO ' . $this->db->table('resolution_artifacts') .
                    ' (tenant_id, id, manifest_id, document_revision_id, tier, blob_digest, byte_length, token_count, tokenizer_id, origin, provenance_kind, dependency_node_id, protected_atom_count, created_at)' .
                    ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' .
                    ' ON CONFLICT DO NOTHING',
                    [
                        $tenantId->value,
                        $artifact->artifactId->value,
                        $manifest->manifestId->value,
                        $revisionId->value,
                        $artifact->tier->value,
                        $artifact->blobDigest->value,
                        $artifact->byteLength,
                        $artifact->tokenCount,
                        $artifact->tokenizerId,
                        $artifact->origin,
                        $artifact->provenanceKind,
                        $artifact->dependencyNodeId->value,
                        $artifact->protectedAtomCount,
                        $now,
                    ],
                );

                $storedArt = $this->db->row(
                    'SELECT id, blob_digest, byte_length, token_count, dependency_node_id' .
                    ' FROM ' . $this->db->table('resolution_artifacts') .
                    ' WHERE tenant_id = ? AND manifest_id = ? AND tier = ?',
                    [$tenantId->value, $manifest->manifestId->value, $artifact->tier->value],
                );
                if ($storedArt === null
                    || $storedArt['id'] !== $artifact->artifactId->value
                    || $storedArt['blob_digest'] !== $artifact->blobDigest->value
                    || (int) $storedArt['byte_length'] !== $artifact->byteLength
                    || (int) $storedArt['token_count'] !== $artifact->tokenCount
                    || $storedArt['dependency_node_id'] !== $artifact->dependencyNodeId->value
                ) {
                    throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
                }
            }
        });
    }

    public function findArtifact(
        Ulid $tenantId,
        Ulid $documentRevisionId,
        ResolutionTier $tier,
    ): ?DerivedArtifactRef {
        if ($tier === ResolutionTier::R3Verbatim) {
            return null;
        }

        $tenant = $tenantId instanceof TenantId ? $tenantId : new TenantId($tenantId->value);

        /** @var ?DerivedArtifactRef */
        return $this->inTx($tenant, LockMode::Share, function () use ($tenant, $documentRevisionId, $tier): ?DerivedArtifactRef {
            $row = $this->db->row(
                'SELECT m.manifest_json' .
                ' FROM ' . $this->db->table('resolution_artifacts') . ' a' .
                ' JOIN ' . $this->db->table('resolution_manifests') . ' m' .
                '   ON m.tenant_id = a.tenant_id AND m.id = a.manifest_id' .
                ' WHERE a.tenant_id = ? AND a.document_revision_id = ? AND a.tier = ?' .
                ' ORDER BY a.created_at DESC, a.id DESC LIMIT 1',
                [$tenant->value, $documentRevisionId->value, $tier->value],
            );
            if ($row === null) {
                return null;
            }

            $wire = StrictJson::decode((string) $row['manifest_json']);
            if (!$wire instanceof JsonObject) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
            $manifest = ResolutionManifest::fromWire($wire);
            return $manifest->artifactFor($tier);
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
}
