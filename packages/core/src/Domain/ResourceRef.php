<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** TenantScopedRef wire identity. Physical blob coordinates belong to BlobRef. */
final readonly class ResourceRef
{
    private const KINDS = ['knowledge_base', 'collection', 'source', 'source_version', 'document_revision',
        'chunk_set', 'chunk', 'generation', 'snapshot', 'job', 'resource', 'selection', 'usage', 'deletion_task'];
    public function __construct(public TenantId $tenantId, public string $kind, public Ulid $id)
    {
        if (!in_array($kind, self::KINDS, true)) { throw new \InvalidArgumentException('Resource kind required'); }
        $type = match ($kind) {
            'knowledge_base'=>KbId::class, 'collection'=>CollectionId::class, 'source'=>SourceId::class,
            'source_version'=>SourceVersionId::class, 'document_revision'=>DocumentRevisionId::class,
            'chunk_set'=>ChunkSetId::class, 'chunk'=>ChunkId::class, 'generation'=>GenerationId::class,
            'snapshot'=>SnapshotId::class, 'job'=>JobId::class, 'resource'=>ResourceId::class,
            'selection'=>SelectionId::class, 'usage'=>UsageId::class, 'deletion_task'=>DeletionTaskId::class,
        };
        if (!$id instanceof $type) { throw new \InvalidArgumentException('Resource kind and ID type must agree'); }
    }
    public function toWire(): JsonObject
    {
        return new JsonObject(['tenantId' => $this->tenantId->value, 'kind' => $this->kind, 'id' => $this->id->value]);
    }
}
