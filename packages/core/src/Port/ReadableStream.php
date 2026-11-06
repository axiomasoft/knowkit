<?php
declare(strict_types=1);

namespace KnowKit\Port;

/** Bounded stream capability. No caller-controlled filesystem path crosses this interface. */
interface ReadableStream
{
    public function read(int $maxBytes): string;
    public function eof(): bool;
    public function close(): void;
}
