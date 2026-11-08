<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use InvalidArgumentException;

final class Guard
{
    public static function utf8(string $value, int $maxBytes, bool $emptyAllowed = true): void
    {
        if (preg_match('//u', $value) !== 1 || strlen($value) > $maxBytes
            || (!$emptyAllowed && trim($value) === '')) {
            throw new InvalidArgumentException('Bounded UTF-8 bytes required');
        }
    }

    public static function reference(string $value): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:@-]{0,255}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Opaque reference required');
        }
    }

    /** @param array<array-key, mixed> $values */
    public static function orderedList(array $values, int $max): void
    {
        if (!array_is_list($values) || count($values) > $max) {
            throw new InvalidArgumentException('Bounded ordered list required');
        }
    }

    /** @param array<array-key, mixed> $values
     * @param class-string $type
     */
    public static function objects(array $values, string $type, int $max): void
    {
        self::orderedList($values, $max);
        foreach ($values as $value) {
            if (!$value instanceof $type) {
                throw new InvalidArgumentException('Typed ordered values required');
            }
        }
    }

    public static function sameScope(\KnowKit\Domain\Scope $expected, \KnowKit\Domain\Scope $actual): void
    {
        if ($expected != $actual) {
            throw new \KnowKit\Domain\DomainFailure(\KnowKit\Domain\Outcome::Denied, 'scope_mismatch');
        }
    }
}
