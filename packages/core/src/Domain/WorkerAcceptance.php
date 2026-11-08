<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Extraction acceptance only. Quality and external uncertainty remain explicit. */
final readonly class WorkerAcceptance
{
    public function __construct(
        public DocumentRevisionRef $revision,
        public WorkerOutput $output,
        public QualityDecision $quality,
        public Checkpoint $checkpoint,
        public bool $uncertainExternalEffect,
        public bool $jobCompleted,
    ) {
    }
}
