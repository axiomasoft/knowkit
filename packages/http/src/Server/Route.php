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

    public function equals(self $other): bool
    {
        return $this->method === $other->method
            && $this->path === $other->path
            && $this->operation === $other->operation
            && $this->schema === $other->schema
            && $this->mutation === $other->mutation
            && $this->epoch === $other->epoch
            && $this->revision === $other->revision
            && $this->successStatus === $other->successStatus
            && $this->revisionAllowed === $other->revisionAllowed;
    }
}
