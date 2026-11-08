<?php

declare(strict_types=1);

namespace KnowKit\Application\Ingest\Worker;

use KnowKit\Port\ReadableStream;

/** Bounded private bytes passed to the existing immutable upload use case. */
final class WorkerBytes implements ReadableStream
{
    private int $position = 0;

    public function __construct(#[\SensitiveParameter] private string $bytes)
    {
    }

    public function read(int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('Positive read size required');
        }
        $bytes = substr($this->bytes, $this->position, $maxBytes);
        $this->position += strlen($bytes);
        return $bytes;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->bytes);
    }

    public function close(): void
    {
        $this->bytes = '';
    }

    public function __debugInfo(): array
    {
        return [];
    }
}
