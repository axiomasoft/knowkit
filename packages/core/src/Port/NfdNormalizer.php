<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Navigation\Projection;

interface NfdNormalizer
{
    public function readiness(): string;

    public function fingerprint(): Fingerprint;

    public function project(string $text): Projection;
}
