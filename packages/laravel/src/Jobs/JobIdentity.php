<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Jobs;

use KnowKit\Application\Access\HostIdentity;

interface JobIdentity
{
    /** Resolve current authorization from host-owned durable work, never serialized scope/grants. */
    public function forJob(string $jobRef): HostIdentity;
}
