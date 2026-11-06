<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{TenantId, KbId, KnowledgeBase, SourceId, Source, SourceVersionId, SourceVersion, Membership, DocumentRevisionRef, ChunkSet, Generation, PublicationSnapshot};
use KnowKit\Domain\{Scope, CreateKB, CreateCollection, AddMembership, MutationContext, Tenant, Collection, CollectionId,
    SourceMembership, CollectionFilter, PageCursor, SourcePage, SourceVersionRef, SnapshotSelector, ProfileId};

/**
 * Every read/write checks tenant identity, including returned records, joins and FKs.
 * Mutations run inside the caller transaction under the approved ordered locks and fresh access/lifecycle checks.
 * Check MutationContext expectedRevision/expectedEpoch against authoritative rows before writing.
 * Idempotency is unique (tenant,key): same command digest returns the original accepted metadata;
 * a different digest conflicts, rollback records no success. Replay never grants disclosure authority.
 * Low-level inserts retain their signatures; they reject mutation of an existing exact identity.
 */
interface CatalogRepository
{
    public function createKnowledgeBase(Scope $access, CreateKB $command, MutationContext $context): KnowledgeBase;
    public function createCollection(Scope $access, CreateCollection $command, MutationContext $context): Collection;
    public function addMembership(Scope $access, AddMembership $command, MutationContext $context): SourceMembership;
    public function getTenant(TenantId $tenantId): ?Tenant;
    public function findKnowledgeBase(TenantId $tenantId, KbId $kbId): ?KnowledgeBase;
    public function getCollection(TenantId $tenantId, KbId $kbId, CollectionId $collectionId): ?Collection;
    /** Cursor must match tenant/KB/filter/trusted security scope; stable sourceId order, maximum 100. */
    public function listSources(TenantId $tenantId, KbId $kbId, CollectionFilter $filter, PageCursor $cursor): SourcePage;
    /** Validate the entire exact ref, never resolve this to another version or digest. */
    public function getVersion(TenantId $tenantId, SourceVersionRef $ref): ?SourceVersion;
    /** Explicit selector; one KB pointer, exact history and exact profile, no latest/profile fallback. */
    public function resolveSnapshot(TenantId $tenantId, KbId $kbId, SnapshotSelector $selector, ProfileId $profileId): ?PublicationSnapshot;
    public function getKb(TenantId $tenantId, KbId $kbId): ?KnowledgeBase;
    public function getSource(TenantId $tenantId, SourceId $sourceId): ?Source;
    public function getSourceVersion(TenantId $tenantId, SourceVersionId $sourceVersionId): ?SourceVersion;
    /** @return list<Membership> */
    public function listMemberships(TenantId $tenantId, SourceId $sourceId): array;
    public function insertTenant(TenantId $tenantId, Tenant $tenant): void;
    public function insertKb(TenantId $tenantId, KnowledgeBase $kb): void;
    /** Validate the complete authoritative parent chain; cross-tenant/KB parents and cycles reject. */
    public function insertCollection(TenantId $tenantId, Collection $collection): void;
    public function insertSource(TenantId $tenantId, Source $source): void;
    public function insertSourceVersion(TenantId $tenantId, SourceVersion $version): void;
    public function insertMembership(TenantId $tenantId, Membership $membership): void;
    public function insertDocumentRevision(TenantId $tenantId, DocumentRevisionRef $revision): void;
    public function insertChunkSet(TenantId $tenantId, ChunkSet $chunkSet): void;
    public function insertGeneration(TenantId $tenantId, Generation $generation): void;
    public function insertSnapshot(TenantId $tenantId, PublicationSnapshot $snapshot): void;
}
