<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** common.schema.json#/$defs/metadata, without filesystem or validator construction. */
final readonly class CatalogMetadata
{
    private FrozenJson $value;

    public function __construct(JsonObject $value = new JsonObject())
    {
        if (count($value) > 32) { throw new \InvalidArgumentException('Bounded metadata required'); }
        foreach ($value as $key => $item) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){1,3}$/D', $key) !== 1
                || (!is_string($item) && !is_int($item) && !is_bool($item))
                || (is_string($item) && (preg_match('//u', $item) !== 1 || mb_strlen($item, 'UTF-8') > 256))) {
                throw new \InvalidArgumentException('Namespaced scalar metadata required');
            }
        }
        $this->value = new FrozenJson($value);
    }

    public function toWire(): JsonObject { return $this->value->toWire(); }
    public function __debugInfo(): array { return ['members'=>count($this->toWire())]; }
}
