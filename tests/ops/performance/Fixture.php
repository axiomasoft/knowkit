<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Load\Report;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Assert;

final class Fixture
{
    public static function cases(): array
    {
        static $cases;
        $cases ??= json_decode(file_get_contents(__DIR__ . '/fixtures/v1/cases.json'), true, 64, JSON_THROW_ON_ERROR);

        return $cases;
    }

    public static function expect(string $case, array $actual): void
    {
        Assert::assertSame(self::cases()['outcomes'][$case], $actual, $case);
    }

    public static function measure(string $case): array
    {
        $result = (new Qa(Report::root()))->process(argv: [PHP_BINARY, 'tools/load/run.php', $case], timeout: 240);
        Assert::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        Assert::assertSame('', $result['stderr']);
        $report = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        Assert::assertSame('capacity-report:1', $report['schemaVersion']);
        Assert::assertSame($case, $report['case']);
        Assert::assertNotEmpty($report['environmentFingerprint']);
        Assert::assertFalse($report['referenceHardwareQualified']);
        Assert::assertFileExists(Report::root() . '/.phpunit.cache/P9.4/' . $case . '.json');

        return $report['measurements'];
    }

    public static function percentiles(array $stats, int $count): void
    {
        Assert::assertSame($count, $stats['count']);
        Assert::assertGreaterThan(0, $stats['p50']);
        Assert::assertGreaterThanOrEqual($stats['p50'], $stats['p95']);
        Assert::assertGreaterThanOrEqual($stats['p95'], $stats['p99']);
    }
}
