<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

use KnowKit\Domain\Contract\{ContractValidator, SchemaSet, StrictJson};

/** Test setup only; the application supplies Domain its already-loaded offline validator. */
final class SchemaLoader
{
    public static function load(string $directory): ContractValidator
    {
        $schemas = [];
        foreach (glob($directory . '/*.schema.json') ?: [] as $path) {
            $schema = StrictJson::decode((string) file_get_contents($path));
            $id = $schema->get('$id');
            if (isset($schemas[$id])) { throw new \InvalidArgumentException('Duplicate schema'); }
            $schemas[$id] = $schema;
        }
        if ($schemas === []) { throw new \InvalidArgumentException('No offline schemas'); }
        return new ContractValidator(new SchemaSet($schemas));
    }
}
