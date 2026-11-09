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
        memory_reset_peak_usage();
        self::failure(
            operation: fn () => ParserSettings::profile(format: 'text', maxSourceBytes: 4194305),
            code: 'unsupported_parser_profile',
        );
        $parser = new TextParser(PortFixture::validator());
        $raw = Catalog::text() . 'x';
        $result = $parser->extract(source: TextFixture::source($raw),
            profile: ParserSettings::profile(format: 'text', maxSourceBytes: 4194304));
        Qa::ensure($result->outcome === D\Outcome::Incompatible && $result->errorCode === 'document_too_large'
            && $result->normalization === null, 'TXT was truncated instead of rejected');
        unset($raw, $result);
        $raw = str_repeat('x', 67108864);
        Qa::ensure(TextFixture::source($raw)->byteLength() === 67108864, 'SourceBytes ceiling rejected');
        $raw .= 'x';
        self::failure(operation: fn () => TextFixture::source($raw), code: 'input_limit');
        unset($raw);
        $limits = new D\WorkerLimits(wallSeconds: 600, cpuSeconds: 480, memoryBytes: 2147483648,
            tempBytes: 4294967296, maxPages: 500, maxOutputBytes: 268435456, maxAssets: 2000);
        foreach (['maxPages' => 501, 'maxAssets' => 2001, 'memoryBytes' => 2147483649] as $name => $value) {
            $options = get_object_vars($limits);
            $options[$name] = $value;
            self::failure(operation: fn () => new D\WorkerLimits(...$options), code: 'limit_exceeded');
        }
        $peak = max(memory_get_peak_usage(true), getrusage()['ru_maxrss'] * 1024);
        Qa::ensure($peak <= 268435456, 'Oversized rejection exceeds 256 MiB');

        return Report::save('limits', ['txtMaxBytes' => 4194304, 'sourceMaxBytes' => 67108864,
            'workerMaxPages' => 500, 'workerMaxAssets' => 2000, 'peakBytes' => $peak,
            'oversizedOutcomes' => ['unsupported_parser_profile', 'document_too_large', 'input_limit', 'limit_exceeded']]);
    }
}
