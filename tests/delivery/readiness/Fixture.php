<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Readiness;

use KnowKit\Tooling\Qa;

final class Fixture
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function json(string $path): array
    {
        return json_decode(file_get_contents(self::root() . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function process(array $argv, array $env = []): array
    {
        return (new Qa(self::root()))->process(argv: $argv, env: $env);
    }

    public static function manifest(): array
    {
        return self::json('tests/fixtures/check-groups/delivery-readiness.json');
    }
}
