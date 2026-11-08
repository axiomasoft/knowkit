<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter;

use KnowKit\Port\ReadableStream;

/** Bounded in-memory input, passed only after the durable ResourceLedger reservation. */
final class PayloadBytes implements ReadableStream
{
    private int $offset = 0;
    private bool $closed = false;

    public function __construct(
        private readonly string $bytes,
    ) {
    }

    public function read(int $maxBytes): string
    {
        if ($this->closed || $maxBytes < 1) {
            throw new \LogicException('Open bounded stream required');
        }
        $chunk = substr($this->bytes, $this->offset, $maxBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->bytes);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function __debugInfo(): array
    {
        return ['byteLength' => strlen($this->bytes), 'closed' => $this->closed];
    }
}
