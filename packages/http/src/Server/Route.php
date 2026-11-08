<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

final readonly class Route
{
    public function __construct(
        public string $method,
        public string $path,
        public string $operation,
        public string $schema,
        public bool $mutation,
        public bool $epoch,
        public bool $revision,
        public int $successStatus,
        public bool $revisionAllowed = false,
    ) {}
}
