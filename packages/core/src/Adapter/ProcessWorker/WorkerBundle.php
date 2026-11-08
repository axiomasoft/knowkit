<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\IrDocument;
use KnowKit\Domain\WorkerResult;

/** Verified extraction only. Import, current authority, conditional acceptance and publication are separate. */
final readonly class WorkerBundle
{
    /** @param array<string, string> $assets Verified private bytes keyed by relative path. */
    public function __construct(
        public WorkerResult $result,
        public IrDocument $ir,
        public array $assets,
    ) {
    }
}
