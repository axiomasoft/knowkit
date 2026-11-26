<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Application\Ingest\Text\ParserSettings;
use KnowKit\Application\Ingest\Text\TextParser;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Ingest\Text\TextFixture;
use KnowKit\Tooling\Qa;

final class Limits
{
    public static function failure(callable $operation, string $code): void
    {
        try {
            $operation();
        } catch (D\DomainFailure $failure) {
            Qa::ensure($failure->outcome === D\Outcome::Incompatible && $failure->errorCode === $code, 'wrong limit outcome');

            return;
        }
        throw new \RuntimeException('Oversized input accepted: ' . $code);
    }

    public static function run(): array
    {
        $quotas = Report::profile()['quotas'];
        $txtMax = Report::intField($quotas, 'txtDocumentBytes');
        $sourceMax = Report::intField($quotas, 'sourceBytes');
        $maxPages = Report::intField($quotas, 'workerMaxPages');
        $maxAssets = Report::intField($quotas, 'workerMaxAssets');
        $memoryBytes = Report::intField($quotas, 'workerMemoryBytes');
        Qa::ensure($memoryBytes < PHP_INT_MAX && $txtMax < PHP_INT_MAX && $sourceMax < PHP_INT_MAX
            && $maxPages < PHP_INT_MAX && $maxAssets < PHP_INT_MAX, 'capacity quota is not a bounded integer');
        memory_reset_peak_usage();
        self::failure(
            operation: fn () => ParserSettings::profile(format: 'text', maxSourceBytes: $txtMax + 1),
            code: 'unsupported_parser_profile',
        );
        $parser = new TextParser(PortFixture::validator());
        $raw = Catalog::text() . 'x';
        $result = $parser->extract(source: TextFixture::source($raw),
            profile: ParserSettings::profile(format: 'text', maxSourceBytes: $txtMax));
        Qa::ensure($result->outcome === D\Outcome::Incompatible && $result->errorCode === 'document_too_large'
            && $result->normalization === null, 'TXT was truncated instead of rejected');
        unset($raw, $result);
        $raw = str_repeat('x', $sourceMax);
        Qa::ensure(TextFixture::source($raw)->byteLength() === $sourceMax, 'SourceBytes ceiling rejected');
        $raw .= 'x';
        self::failure(operation: fn () => TextFixture::source($raw), code: 'input_limit');
        unset($raw);
        $limits = new D\WorkerLimits(wallSeconds: 600, cpuSeconds: 480, memoryBytes: $memoryBytes,
            tempBytes: 4294967296, maxPages: $maxPages, maxOutputBytes: 268435456, maxAssets: $maxAssets);
        foreach (['maxPages' => $maxPages + 1, 'maxAssets' => $maxAssets + 1, 'memoryBytes' => $memoryBytes + 1] as $name => $value) {
            $options = get_object_vars($limits);
            $options[$name] = $value;
            self::failure(operation: fn () => new D\WorkerLimits(...$options), code: 'limit_exceeded');
        }
        $peak = Metrics::peakBytes();
        Qa::ensure($peak <= Report::intField(Report::profile()['reference'], 'txtIngestPeakBytes'), 'Oversized rejection exceeds approved peak');

        return Report::save('limits', ['txtMaxBytes' => $txtMax, 'sourceMaxBytes' => $sourceMax,
            'workerMaxPages' => $maxPages, 'workerMaxAssets' => $maxAssets, 'peakBytes' => $peak,
            'oversizedOutcomes' => ['unsupported_parser_profile', 'document_too_large', 'input_limit', 'limit_exceeded']]);
    }
}
