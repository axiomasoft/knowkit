<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Structure;

use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\IrDocument;
use KnowKit\Tests\Worker\Protocol\Fixture;

final class StructureFixture
{
    public static function path(string $name): string
    {
        return Fixture::root() . '/tests/fixtures/documents/structure/v1/' . $name;
    }

    public static function ir(string $name = 'structured'): IrDocument
    {
        return new IrDocument(
            StrictJson::decode(file_get_contents(self::path("{$name}.ir.json"))),
            Fixture::validator(),
        );
    }
}
