<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

final readonly class SystemTime implements Time
{
    public function milliseconds(): int
    {
        return (int) floor(hrtime(true) / 1000000);
    }

    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
