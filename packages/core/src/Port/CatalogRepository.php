<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{TenantId, KbId, KnowledgeBase, SourceId, Source, SourceVersionId, SourceVersion, Membership, DocumentRevisionRef, ChunkSet, Generation, PublicationSnapshot};

/** Every read/write checks tenant identity; inserts reject mutation of an existing exact identity. */
interface CatalogRepository
{
    public function getKb(TenantId $tenantId, KbId $kbId): ?KnowledgeBase;
    public function getSource(TenantId $tenantId, SourceId $sourceId): ?Source;
    public function getSourceVersion(TenantId $tenantId, SourceVersionId $sourceVersionId): ?SourceVersion;
    /** @return list<Membership> */
    public function listMemberships(TenantId $tenantId, SourceId $sourceId): array;
    public function insertKb(TenantId $tenantId, KnowledgeBase $kb): void;
    public function insertSource(TenantId $tenantId, Source $source): void;
    public function insertSourceVersion(TenantId $tenantId, SourceVersion $version): void;
    public function insertMembership(TenantId $tenantId, Membership $membership): void;
    public function insertDocumentRevision(TenantId $tenantId, DocumentRevisionRef $revision): void;
    public function insertChunkSet(TenantId $tenantId, ChunkSet $chunkSet): void;
    public function insertGeneration(TenantId $tenantId, Generation $generation): void;
    public function insertSnapshot(TenantId $tenantId, PublicationSnapshot $snapshot): void;
}
