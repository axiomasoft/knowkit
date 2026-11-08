<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use KnowKit\Domain\JobId;

final readonly class RunJob implements ShouldQueue
{
    public function __construct(
        public string $jobRef,
    ) {
        new JobId($jobRef);
    }

    public function handle(Jobs $jobs): void
    {
        $jobs->run($this->jobRef);
    }
}
