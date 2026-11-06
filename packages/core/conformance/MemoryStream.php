<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

/** Bounded synthetic input stream, for host conformance only. */
final class MemoryStream implements \KnowKit\Port\ReadableStream
{
    private int $offset = 0;
    private bool $closed = false;
    public function __construct(private readonly string $bytes) {}
    public function read(int $maxBytes): string
    {
        if ($this->closed || $maxBytes < 1) { throw new \InvalidArgumentException('Open stream and positive bound required'); }
        $part = substr($this->bytes, $this->offset, $maxBytes); $this->offset += strlen($part); return $part;
    }
    public function eof(): bool { return $this->closed || $this->offset >= strlen($this->bytes); }
    public function close(): void { $this->closed = true; }
    public function __debugInfo(): array { return ['byteLength'=>strlen($this->bytes)]; }
}
