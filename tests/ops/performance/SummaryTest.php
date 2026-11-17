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
    public function testRuntimeBuildInputsAreRecordedAndChangedInputsInvalidateMeasurements(): void
    {
        $path = Report::directory() . '/catalog.json';
        $original = is_file($path) ? file_get_contents($path) : null;
        $case = 'runtime-inputs-' . bin2hex(random_bytes(8));
        try {
            $report = Report::save($case, []);
            foreach (['deploy/worker/build_runtime.py', 'python/knowkit-worker/build_pdf_runtime.py',
                'python/knowkit-worker/build_ocr_runtime.py', 'python/knowkit-worker/uv.lock'] as $input) {
                self::assertSame(hash_file('sha256', Report::root() . '/' . $input), $report['fingerprints'][$input]);
                $stale = $report;
                $stale['fingerprints'][$input] = str_repeat('0', 64);
                file_put_contents($path, json_encode($stale, JSON_THROW_ON_ERROR));
                self::rejectSummary('measurement candidate changed: ' . $input);
            }
        } finally {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (is_file($path)) {
                unlink($path);
            }
            if (is_file(Report::directory() . '/' . $case . '.json')) {
                unlink(Report::directory() . '/' . $case . '.json');
            }
        }
    }

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
