<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Checkpoint
{
    public function __construct(public Sha256 $digest, public bool $uncertainExternalEffect) {}
}
