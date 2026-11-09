<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class CatalogTest extends TestCase
{
    public function testSequential64MiBBatchAndSingleMaxDocumentStayWithin256MiB(): void
    {
        $m = Fixture::measure('catalog');
        Fixture::expect('CATALOG-64M', [$m['documents'], $m['documentBytes'], $m['totalBytes'], $m['concurrent']]);
        self::assertLessThanOrEqual(268435456, $m['peakBytes']);
        self::assertSame(Fixture::cases()['txtSha256'], $m['textSha256']);
        Fixture::percentiles(stats: $m['latencyMs'], count: 16);
        self::assertGreaterThan(0, $m['cpuMs']);
        self::assertGreaterThan(0, $m['firstDocumentMs']);
    }

    public function testOversizedTextSourcePagesAssetsAndMemoryRejectWithoutContractChanges(): void
    {
        $m = Fixture::measure('limits');
        Fixture::expect('MAX-BYTES-PAGES-ASSETS', [$m['txtMaxBytes'], $m['sourceMaxBytes'], $m['workerMaxPages'],
            $m['workerMaxAssets'], $m['oversizedOutcomes']]);
        self::assertLessThanOrEqual(268435456, $m['peakBytes']);
    }
}
