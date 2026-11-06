<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use KnowKit\Domain\{Coverage,ResourceId,UtcInstant,DomainFailure,Outcome};

/** Supplied by ingestion after offline validation/preload, never fabricated from an exact ref. */
final readonly class RevisionMetadata
{
    public function __construct(public ResourceId $irResourceId,public string $irSchema,
        public Coverage $coverage,public UtcInstant $createdAt)
    { if ($irSchema!=='ir-1.0') { throw new DomainFailure(Outcome::Incompatible,'unsupported_schema_version'); } }
}
