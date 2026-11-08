<?php

declare(strict_types=1);

namespace KnowKit\Domain\Origins;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final class DocumentInput
{
    public static function assertSupported(#[\SensitiveParameter] string $bytes): void
    {
        if (strlen($bytes) > 67108864) {
            throw new DomainFailure(Outcome::Incompatible, 'input_limit');
        }
        foreach (["PK\x03\x04", "PK\x05\x06", "PK\x07\x08", "\x1f\x8b", "7z\xbc\xaf\x27\x1c", 'Rar!', 'BZh', "\xfd7zXZ\0"] as $magic) {
            if (str_starts_with($bytes, $magic)) {
                throw new DomainFailure(Outcome::Incompatible, 'archive_unsupported');
            }
        }
        if (substr($bytes, 257, 5) === 'ustar') {
            throw new DomainFailure(Outcome::Incompatible, 'archive_unsupported');
        }
    }

    public static function assertMime(#[\SensitiveParameter] string $bytes, string $mediaType): void
    {
        self::assertSupported($bytes);
        $mime = strtolower(trim(explode(';', $mediaType, 2)[0]));
        $valid = match ($mime) {
            'application/pdf' => str_starts_with($bytes, '%PDF-'),
            'text/plain', 'text/markdown' => preg_match('//u', $bytes) === 1 && !str_contains($bytes, "\0")
                && preg_match('/\A\s*(?:%PDF-|<|PK\x03\x04)/i', $bytes) !== 1,
            default => false,
        };
        if (!$valid) {
            throw new DomainFailure(Outcome::Incompatible, 'origin_media_type');
        }
    }
}
