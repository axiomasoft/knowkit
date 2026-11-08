<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Jobs;

use KnowKit\Application\Access\AccessContext;

interface JobHandler
{
    /** Delegate durable work to application services, retaining their lease/disclosure checks. */
    public function handle(string $jobRef, AccessContext $context): void;
}
