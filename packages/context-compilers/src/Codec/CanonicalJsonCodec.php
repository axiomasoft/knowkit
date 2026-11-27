<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Codec;

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;

final readonly class CanonicalJsonCodec implements ContextFormatCodec
{
    public function formatId(): string
    {
        return 'canonical_json';
    }

    public function encode(mixed $data): string
    {
        return Jcs::canonicalize($data);
    }

    public function decode(string $encoded): mixed
    {
        return StrictJson::decode($encoded);
    }
}
