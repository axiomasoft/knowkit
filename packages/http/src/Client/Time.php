<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

/** Monotonic milliseconds, independent of domain UTC policy timestamps. */
interface Time
{
    public function milliseconds(): int;

    public function sleep(int $milliseconds): void;
}
