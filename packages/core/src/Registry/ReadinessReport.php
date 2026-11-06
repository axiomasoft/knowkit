<?php
declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\Contract\JsonObject;

/** Adapted value-free readiness pattern; no probes or implicit verification. */
final readonly class ReadinessReport
{
    public bool $ready;
    public function __construct(public bool $installed, public bool $enabled, public bool $configured,
        public bool $verified, public bool $failed, public bool $unknown)
    {
        $this->ready = $installed && $enabled && $configured && $verified && !$failed && !$unknown;
    }

    public function toWire(): JsonObject { return new JsonObject(get_object_vars($this)); }
}
