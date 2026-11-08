<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\JobEnvelope;
use KnowKit\Domain\WorkerOutput;

interface WorkerRunner
{
    /** Negotiate the exact profile in the sandbox before parsing. No unsandboxed fallback. */
    public function run(JobEnvelope $job, #[\SensitiveParameter] string $source, ?\Closure $cancel = null): WorkerOutput;
}
