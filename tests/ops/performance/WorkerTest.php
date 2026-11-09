<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class WorkerTest extends TestCase
{
    public function testRealSandboxOcrBatchMeasuresMemoryCpuAndEnforcesOom(): void
    {
        $m = Fixture::measure('ocr');
        Fixture::expect('OCR-BATCH-MEMORY', [$m['documents'], $m['pagesPerDocument'], $m['oomOutcome'], $m['providerCalls']]);
        Fixture::percentiles(stats: $m['latencyMs'], count: 3);
        self::assertGreaterThan(0, $m['workerPeakBytes']);
        self::assertLessThanOrEqual(2147483648, $m['workerPeakBytes']);
        self::assertGreaterThan(0, $m['cpuMs']);
        self::assertGreaterThan(0, $m['pagesPerSecond']);
        self::assertSame(Fixture::cases()['ocrIrSha256'], $m['irSha256']);
        self::assertNotEmpty($m['parserSignature']['name']);
        self::assertNotEmpty($m['parserSignature']['version']);
    }
}
