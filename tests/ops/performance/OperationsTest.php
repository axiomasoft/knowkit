<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class OperationsTest extends TestCase
{
    public function testHealthyOutboxDrainsWithDuplicateEffectsFencedAndLagMeasured(): void
    {
        $m = Fixture::measure('outbox');
        Fixture::expect('OUTBOX', [$m['events'], $m['backlogBefore'], $m['backlogAfter']]);
        Fixture::percentiles(stats: $m['lagMs'], count: 64);
        Fixture::percentiles(stats: $m['deliveryLatencyMs'], count: 64);
        self::assertSame($m['lagMs']['p95'] <= 5000, $m['targetMet']);
    }

    public function testPurgeDeletesRealExactLocalObjectsAndDrainsResourceBacklog(): void
    {
        $m = Fixture::measure('purge');
        Fixture::expect('PURGE', [$m['resources'], $m['backlogBefore'], $m['backlogAfter'], $m['aggregate']]);
        Fixture::percentiles(stats: $m['latencyMs'], count: 32);
    }
}
