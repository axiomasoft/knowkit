<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class IrDocument extends SchemaValue
{
    protected const SCHEMA = 'document-ir';

    /** @return list<JsonObject> */
    public function pages(): array
    {
        return $this->toWire()->get('pages');
    }

    /** @return list<JsonObject> */
    public function segments(): array
    {
        return $this->toWire()->get('segments');
    }

    /** @return list<JsonObject> */
    public function assets(): array
    {
        return $this->toWire()->get('assets');
    }

    public function ocrMetadata(LocalId $id): OcrMetadata
    {
        return OcrMetadata::fromDocument($this, $id);
    }

    public function block(LocalId $id): JsonObject
    {
        foreach ($this->toWire()->get('blocks') as $block) {
            if ($block->get('blockId') === $id->value) {
                return $block;
            }
        }

        throw new DomainFailure(Outcome::Unknown, 'block_unknown');
    }
}
