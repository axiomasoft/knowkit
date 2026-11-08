<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Domain;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;

/** Receipt binds the host's role-aware provider call to this authorized query. */
final readonly class QueryVector
{
    public function __construct(
        public D\Vector $vector,
        private string $role,
        private D\Fingerprint $scopeBinding,
        private D\Sha256 $queryDigest,
    ) {}

    public static function bind(D\Vector $vector, string $role, D\AuthorizedSearchScope $scope, D\Query $query): self
    {
        return new self($vector, $role, $scope->scopeBindingDigest, self::digest($scope, $query));
    }

    public function payload(D\AuthorizedSearchScope $scope, D\Query $query, D\EmbeddingSpace $space): VectorPayload
    {
        $space->assertCompatible($this->vector->space);
        if ($this->role !== $space->queryRole) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'embedding_role_mismatch');
        }
        if (!$this->scopeBinding->equals($scope->scopeBindingDigest)
            || !$this->queryDigest->equals(self::digest($scope, $query))) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }

        return new VectorPayload($this->vector);
    }

    private static function digest(D\AuthorizedSearchScope $scope, D\Query $query): D\Sha256
    {
        $versions = $query->sourceVersionIds === null ? null : array_map(
            static fn (D\SourceVersionId $id): string => $id->value, $query->sourceVersionIds);
        $collections = $query->collectionIds === null ? null : array_map(
            static fn (D\CollectionId $id): string => $id->value, $query->collectionIds);
        $binding = new JsonObject(['scope' => $scope->toWire(), 'queryHash' => hash('sha256', $query->text()),
            'sourceVersionIds' => $versions, 'collectionIds' => $collections]);

        return new D\Sha256(hash('sha256', Jcs::canonicalize($binding)));
    }
}
