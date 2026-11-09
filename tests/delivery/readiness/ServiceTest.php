<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Readiness;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:readiness')]
final class ServiceTest extends TestCase
{
    public function testInstalledLaravelAndHttpWorkWithoutWorker(): void
    {
        $result = Fixture::process(argv: [PHP_BINARY, 'tests/delivery/readiness/service.php'], env: ['PATH' => '/nonexistent']);
        self::assertSame(0, $result['exit'], $result['stderr']);
        $wire = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('success', $wire['outcome']);
        self::assertSame('NOT_SELECTED', $wire['worker']);
        self::assertSame('NOT_SELECTED/PENDING', $wire['bridge']);
    }
}
