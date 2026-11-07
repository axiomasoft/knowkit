<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Text;

use KnowKit\Application\Ingest\Text\ParserSettings;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, JsonObject, StrictJson};
use KnowKit\Tests\Contract\Schemas\ContractHarness;
use PHPUnit\Framework\TestCase;

final class TextFixture
{
    public static function root(): string { return dirname(__DIR__, 3); }
    public static function validator(): ContractValidator
    {
        static $validator;
        return $validator ??= (new ContractHarness(self::root()))->validator;
    }
    public static function source(string $raw, string $media = 'text/plain', string $tenant = '01JC0000000000000000000001',
        string $version = '01JC000000000000000000000Z'): D\SourceBytes
    {
        return new D\SourceBytes(new D\SourceVersionRef(new D\TenantId($tenant),
            new D\SourceId('01JC0000000000000000000002'), new D\SourceVersionId($version),
            new D\SourceDigest(hash('sha256', $raw))), $raw, $media);
    }
    public static function manifest(string $format): array
    {
        return json_decode(file_get_contents(self::root() . '/tests/fixtures/documents/' . $format . '/manifest.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public static function profile(string $format, array $options = []): D\ParserProfile
    {
        return ParserSettings::profile($format, ...$options);
    }
    public static function read(string $path): string { return file_get_contents(self::root() . '/' . $path); }
    public static function failure(callable $operation, D\Outcome $outcome, string $code): void
    {
        try { $operation(); TestCase::fail('Expected typed failure: ' . $code); }
        catch (D\DomainFailure $failure) {
            TestCase::assertSame($outcome, $failure->outcome);
            TestCase::assertSame($code, $failure->errorCode);
        }
    }
    public static function json(array $value): JsonObject
    {
        return StrictJson::decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
