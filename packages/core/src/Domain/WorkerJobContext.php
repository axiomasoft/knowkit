<?php

declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class WorkerJobContext
{
    public function __construct(
        public JobType $stage,
        public Sha256 $inputDigest,
        public Fingerprint $processingDigest,
        public bool $uncertainExternalEffect,
        public bool $completesJob,
    ) {
    }

}
