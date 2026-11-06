<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{UtcInstant};

interface Clock
{
    public function now(): UtcInstant;
}
