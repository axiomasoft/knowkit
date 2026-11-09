<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class GraphTest extends TestCase
{
    public function testHotTenantGraphWritesMeasureRealGuardContention(): void
    {
        $m = Fixture::measure('hot-tenant');
        Fixture::expect('HOT-TENANT', [$m['tenants'], $m['writers'], $m['writes']]);
        Fixture::percentiles(stats: $m['latencyMs'], count: 64);
        Fixture::percentiles(stats: $m['lockWaitMs'], count: 64);
        self::assertGreaterThan(0, $m['throughputWritesPerSecond']);
    }

    public function testTwentyTenantsRemainIsolatedDuringConcurrentGraphWrites(): void
    {
        $m = Fixture::measure('many-tenants');
        Fixture::expect('MANY-TENANTS', [$m['tenants'], $m['writers'], $m['writes']]);
        Fixture::percentiles(stats: $m['latencyMs'], count: 320);
        Fixture::percentiles(stats: $m['lockWaitMs'], count: 320);
    }
}
