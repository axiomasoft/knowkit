<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class SearchTest extends TestCase
{
    public function testReferenceCorpusTwentyClientsAndLargePermissionPrefilter(): void
    {
        $m = Fixture::measure('lexical');
        Fixture::expect('LEXICAL-20-CLIENTS', [$m['sources'], $m['chunks'], $m['clients'], $m['prefilterSourcesPerClient']]);
        Fixture::percentiles(stats: $m['coldLatencyMs'], count: 20);
        self::assertGreaterThan(1048576, $m['manifestBytes']);
        self::assertLessThanOrEqual(16777216, $m['manifestBytes']);
        Fixture::percentiles(stats: $m['warmLatencyMs'], count: 100);
        self::assertSame($m['warmLatencyMs']['p95'] <= 500, $m['targetMet']);
        Fixture::expect('LARGE-PERMISSION-PREFILTER', [$m['largePrefilter']['sources'], $m['largePrefilter']['chunks']]);
        Fixture::percentiles(stats: $m['largePrefilter']['latencyMs'], count: 1);
        self::assertTrue($m['largePrefilter']['oversizedGrantRejected']);
        self::assertCount(2, $m['immutableLookupPlans']);
        foreach ($m['immutableLookupPlans'] as $plan) {
            self::assertSame(0, $plan['indexedFilteredRows']);
            self::assertGreaterThanOrEqual(9000, $plan['linearFilteredRows']);
        }
    }

    public function testHybridUsesRealPgvectorAndPreservesPrefilterAndPositiveRanks(): void
    {
        $m = Fixture::measure('hybrid');
        Fixture::expect('HYBRID', [$m['sources'], $m['allowedSources'], $m['deniedReturned'], $m['providerCalls']]);
        Fixture::percentiles(stats: $m['latencyMs'], count: 20);
        self::assertNotEmpty($m['vectorVersion']);
    }

    public function testBoundedDisclosureObjectOutageAndCommittedRevokeStayDistinct(): void
    {
        $m = Fixture::measure('disclosure');
        Fixture::expect('OBJECT-STORE-OUTAGE', [$m['objectOutage'], $m['afterCommittedRevoke'], $m['usageCount']]);
        Fixture::percentiles(stats: $m['warmLatencyMs'], count: 19);
        self::assertSame($m['warmLatencyMs']['p95'] <= 200, $m['targetMet']);
    }

    public function testConcurrentReindexPreservesMembershipsAndRefusesSealedMutation(): void
    {
        $m = Fixture::measure('reindex');
        Fixture::expect('CONCURRENT-REINDEX', [$m['writers'], $m['replaysPerWriter'], $m['sealedMutation']]);
        self::assertSame($m['membershipsBefore'], $m['membershipsAfter']);
        Fixture::percentiles(stats: $m['latencyMs'], count: 32);
    }
}
