<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Load\Metrics;
use KnowKit\Load\Qualification;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class MetricsTest extends TestCase
{
    public function testNearestRankPercentilesHaveGoldenValuesAndRejectInvalidSamples(): void
    {
        self::assertSame(['count' => 100, 'p50' => 50.0, 'p95' => 95.0, 'p99' => 99.0], Metrics::percentiles(array_reverse(range(1, 100))));
        self::assertSame(['count' => 1, 'p50' => 1.25, 'p95' => 1.25, 'p99' => 1.25], Metrics::percentiles([1.25]));
        foreach ([[], [-1], [NAN], [INF], ['unknown']] as $invalid) {
            try {
                Metrics::percentiles($invalid);
                self::fail('Invalid samples accepted');
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function testQualificationRejectsMissingHardwareChangedWorkloadAndFailedThresholds(): void
    {
        self::assertNotEmpty(Qualification::failures([]));
        $reports = [];
        foreach (Qualification::CASES as $case) {
            $reports[$case] = ['schemaVersion' => 'capacity-report:1', 'case' => $case, 'referenceHardwareQualified' => true,
                'measurements' => ['targetMet' => true, 'totalBytes' => 67108864, 'peakBytes' => 134217728,
                    'sources' => 10000, 'chunks' => 100000, 'clients' => 20, 'referenceScaleQualified' => true, 'workerPeakBytes' => 536870912]];
            $reports[$case]['measurements']['warmLatencyMs'] = ['count' => $case === 'disclosure' ? 19 : 100, 'p95' => 100.0];
            $reports[$case]['measurements']['lagMs'] = ['count' => 64, 'p95' => 100.0];
        }
        self::assertSame([], Qualification::failures($reports));
        foreach (['hardware', 'slo', 'forged', 'memory', 'workload', 'hybrid', 'ocr', 'missing'] as $fault) {
            $invalid = $reports;
            match ($fault) {
                'hardware' => $invalid['lexical']['referenceHardwareQualified'] = false,
                'slo' => $invalid['lexical']['measurements']['targetMet'] = false,
                'forged' => $invalid['lexical']['measurements']['warmLatencyMs']['p95'] = 501.0,
                'memory' => $invalid['catalog']['measurements']['peakBytes'] = 268435457,
                'workload' => $invalid['lexical']['measurements']['clients'] = 19,
                'hybrid' => $invalid['hybrid']['measurements']['referenceScaleQualified'] = false,
                'ocr' => $invalid['ocr']['measurements']['workerPeakBytes'] = 2147483649,
                'missing' => $invalid['ocr'] = null,
            };
            self::assertNotEmpty(Qualification::failures($invalid), $fault);
        }
    }

    public function testIndexManifestStillRejectsAboveItsSchemaCeilingBeforeJsonParsing(): void
    {
        try {
            PortFixture::validator()->decodeValid(bytes: str_repeat(' ', 16777217), schemaId: 'urn:knowkit:schema:index-manifest:1.0');
            self::fail('Oversized manifest accepted');
        } catch (ContractViolation $failure) {
            self::assertSame('document_too_large', $failure->errorCode);
        }
    }
}
