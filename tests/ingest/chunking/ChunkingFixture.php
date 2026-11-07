<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Chunking;

use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{JsonObject, StrictJson};
use KnowKit\Tests\Ingest\Validation\ValidationFixture;

final class ChunkingFixture
{
    public static function root(): string { return dirname(__DIR__, 3); }
    public static function ir(string $name): D\IrDocument
    {
        return new D\IrDocument(StrictJson::decode((string) file_get_contents(self::root() . '/tests/fixtures/chunking/v1/' . $name . '.json'), 67108864), ValidationFixture::validator());
    }
    public static function profile(array $options = []): D\ChunkerProfile { return new D\ChunkerProfile('structural-default', 'structural', '1', new JsonObject($options)); }
    public static function chunker(D\IrDocument $ir, ?D\DocumentId $document = null, ?\KnowKit\Port\Tokenizer $tokenizer = null): StructuralChunker
    {
        return new StructuralChunker(ValidationFixture::input($ir)->revision, $document ?? new D\DocumentId(ValidationFixture::id(5)), $tokenizer);
    }
    public static function failure(callable $run, string $code, D\Outcome $outcome = D\Outcome::Incompatible): void
    {
        try { $run(); \PHPUnit\Framework\Assert::fail('Rejected input was accepted'); }
        catch (D\DomainFailure $error) {
            \PHPUnit\Framework\Assert::assertSame($code, $error->errorCode);
            \PHPUnit\Framework\Assert::assertSame($outcome, $error->outcome);
        }
    }
}
