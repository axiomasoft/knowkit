<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use Closure;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\TestCase;

abstract class HardeningCase extends TestCase
{
    protected string $temporary;

    protected function setUp(): void
    {
        $this->temporary = Qa::temporary();
    }

    protected function tearDown(): void
    {
        Qa::remove($this->temporary);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function failure(Closure $call, string $code, Outcome $outcome = Outcome::Denied): void
    {
        try {
            $call();
            self::fail('Forbidden operation succeeded: ' . $code);
        } catch (DomainFailure $failure) {
            self::assertSame($code, $failure->errorCode);
            self::assertSame($outcome, $failure->outcome);
        }
    }

    protected function cases(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/v1/cases.json'), true, 64, JSON_THROW_ON_ERROR);
    }
}
