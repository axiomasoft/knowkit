<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Ulid};

interface IdGenerator
{
    public function next(): Ulid;
}
