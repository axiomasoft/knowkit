<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Documents;

use KnowKit\Domain\{DocumentId, LocalId, ResourceId, Ulid, UtcInstant};

/** Explicit adapter metadata; IR local IDs are never reinterpreted as persisted ULIDs. */
final readonly class DocumentStorage
{
    /** @param array<string,UnitStorage> $pages @param array<string,UnitStorage> $segments
     * @param array<string,Ulid> $blocks @param array<string,AssetStorage> $assets */
    public function __construct(public DocumentId $documentId, public string $logicalPath, public ResourceId $irResourceId,
        public UtcInstant $createdAt, public array $pages, public array $segments, public array $blocks, public array $assets)
    {
        if ($logicalPath === '' || str_contains($logicalPath, "\0") || preg_match('//u', $logicalPath) !== 1 || mb_strlen($logicalPath, 'UTF-8') > 512) {
            throw new \InvalidArgumentException('Bounded logical document name required');
        }
        // logicalPath is an opaque document label, never an OS/network target.
        $seen = [$documentId->value => true];
        foreach (['pages' => UnitStorage::class, 'segments' => UnitStorage::class, 'blocks' => Ulid::class, 'assets' => AssetStorage::class] as $field => $type) {
            foreach ($this->$field as $local => $value) {
                new LocalId((string) $local);
                if (!$value instanceof $type) { throw new \InvalidArgumentException('Typed storage map required'); }
                $id = $value instanceof Ulid ? $value->value : $value->id->value;
                if (isset($seen[$id])) { throw new \InvalidArgumentException('Distinct persisted object IDs required'); }
                $seen[$id] = true;
            }
        }
    }
}
