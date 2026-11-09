<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Load\Report;
use KnowKit\Load\Summary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class SummaryTest extends TestCase
{
    public function testSummaryRejectsChangedCandidateAndMissingMeasurement(): void
    {
        $path = Report::directory() . '/catalog.json';
        $original = is_file($path) ? file_get_contents($path) : null;
        try {
            $file = 'tests/ops/performance/fixtures/v1/cases.json';
            file_put_contents($path, json_encode(['fingerprints' => [$file => str_repeat('0', 64)]], JSON_THROW_ON_ERROR));
            self::rejectSummary('measurement candidate changed: ' . $file);
            unlink($path);
            self::rejectSummary('missing measured workload: catalog');
        } finally {
            if ($original !== null) {
                file_put_contents($path, $original);
            }
        }
    }

    private static function rejectSummary(string $message): void
    {
        try {
            Summary::collect();
            self::fail('Invalid evidence was exported');
        } catch (\RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }
}
