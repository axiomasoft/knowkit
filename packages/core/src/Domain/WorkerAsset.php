<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Constructed only from the validated worker-result inventory. */
final readonly class WorkerAsset
{
    public function __construct(
        public string $relativePath,
        public string $digest,
        public string $byteLength,
        public string $mediaType,
    ) {
    }
}
