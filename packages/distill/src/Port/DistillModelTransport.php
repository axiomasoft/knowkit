<?php

declare(strict_types=1);

namespace KnowKit\Distill\Port;

use KnowKit\Distill\Domain\{DistillOutput, DistillRequest};

/** Single-exchange model transport boundary for distillation; callers must gate via PolicyChecks::transmit. */
interface DistillModelTransport
{
    public function isFixture(): bool;

    public function send(DistillRequest $request): DistillOutput;
}
