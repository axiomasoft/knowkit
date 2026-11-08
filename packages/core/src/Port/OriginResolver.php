<?php

declare(strict_types=1);

namespace KnowKit\Port;

interface OriginResolver
{
    /** @return list<string> All A/AAAA answers, never just the first acceptable address. */
    public function addresses(string $host, ?int $deadline = null): array;
}
