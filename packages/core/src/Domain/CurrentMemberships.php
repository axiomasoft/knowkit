<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Trusted authoritative collection memberships, scoped to the host's current eligible universe. */
final readonly class CurrentMemberships
{
    /** @param array<string, list<CollectionId>> $bySourceVersion */
    public function __construct(public Scope $scope, public array $bySourceVersion)
    {
        foreach ($bySourceVersion as $version => $collections) {
            new SourceVersionId($version);
            ValueGuard::uniqueIds($collections, CollectionId::class);
        }
    }
}
