<?php

declare(strict_types=1);

namespace KnowKit\Domain\Origins;

final readonly class RemoteResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        #[\SensitiveParameter] private string $bytes,
    ) {}

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'byteLength' => strlen($this->bytes)];
    }
}
