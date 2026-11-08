<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\WorkerOutput;

/** Verified extraction only. Import, current authority, conditional acceptance and publication are separate. */
final readonly class WorkerBundle extends WorkerOutput
{
}
