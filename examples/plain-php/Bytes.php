<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Port\ReadableStream;

final class Bytes implements ReadableStream
{
    private int $offset = 0;

    public function __construct(
        private string $bytes,
    ) {}

    public function read(int $maxBytes): string
    {
        $part = substr($this->bytes, $this->offset, $maxBytes);
        $this->offset += strlen($part);

        return $part;
    }

    public function eof(): bool
    {
        return $this->offset === strlen($this->bytes);
    }

    public function close(): void
    {
        $this->bytes = '';
        $this->offset = 0;
    }
}
