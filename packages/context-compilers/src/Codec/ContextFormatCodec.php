<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Codec;

interface ContextFormatCodec
{
    public function formatId(): string;

    public function encode(mixed $data): string;

    public function decode(string $encoded): mixed;
}
