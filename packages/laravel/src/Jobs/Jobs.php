<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class Jobs
{
    public function __construct(
        private bool $enabled,
        private Dispatcher $dispatcher,
        private JobIdentity $identity,
        private JobHandler $handler,
    ) {}

    public function dispatch(string $jobRef): void
    {
        $this->assertEnabled();
        $this->dispatcher->dispatch(new RunJob($jobRef));
    }

    public function run(string $jobRef): void
    {
        $this->assertEnabled();
        $job = new RunJob($jobRef);
        $context = AccessContext::fromHost($this->identity->forJob($job->jobRef));
        $context->assertCurrent();
        $this->handler->handle(jobRef: $job->jobRef, context: $context);
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'jobs_disabled');
        }
    }
}
