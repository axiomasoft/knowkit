<?php

declare(strict_types=1);

namespace KnowKit\Answer\Port;

use DateTimeImmutable;
use KnowKit\Answer\Domain\DependencyManifest;
use KnowKit\Answer\Domain\InvocationPayloadRef;
use KnowKit\Domain\Scope;

/** Managed immutable bytes: pre-PUT reservation, digest verification, retention and lineage. */
interface InvocationPayloads
{
    public function put(Scope $scope, string $kind, string $bytes, DependencyManifest $dependencies, DateTimeImmutable $retention): InvocationPayloadRef;

    public function read(Scope $scope, InvocationPayloadRef $ref): string;

    public function dependencies(InvocationPayloadRef $ref): DependencyManifest;
}
