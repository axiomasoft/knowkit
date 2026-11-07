<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Q1=a: revision/unit usage, independent of a retrieval Selection or a chunk citation. */
final readonly class NavigationUsage
{
    /** @param list<Unit> $units */
    public function __construct(
        public D\Scope $scope,
        public D\DocumentRevisionRef $revision,
        public D\DocumentId $documentId,
        public D\UsageId $id,
        public D\Ulid $attemptId,
        public D\UtcInstant $checkedAt,
        public D\Sha256 $policyBindingDigest,
        private array $units,
        private D\FrozenJson $coverage,
    ) {
        $scope->assertTenant($revision->sourceRef->tenantId);
        D\ValueGuard::objects($units, Unit::class);
        if ($units === [] || count($units) > 24) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
    }

    public function payload(): JsonObject
    {
        return new JsonObject([
            'kind' => 'navigation',
            'usageId' => $this->id->value,
            'disclosureAttemptId' => $this->attemptId->value,
            'kbId' => $this->scope->kbId->value,
            'actorId' => $this->scope->actorId->value,
            'purpose' => $this->scope->purpose,
            'hostScopeHash' => $this->scope->hostScopeHash->value,
            'revisionRef' => $this->revision->toWire(),
            'documentId' => $this->documentId->value,
            'unitRefs' => array_map(static fn (Unit $unit): JsonObject => $unit->reference(), $this->units),
            'checkedAt' => $this->checkedAt->value,
            'policyBindingDigest' => $this->policyBindingDigest->value,
        ]);
    }

    public function disclosure(): UnitDisclosure
    {
        return new UnitDisclosure($this->revision, $this->documentId, $this->units, $this->id,
            $this->attemptId, $this->checkedAt, $this->scope->hostScopeHash, $this->coverage);
    }

    public function __debugInfo(): array
    {
        return ['usageId' => $this->id, 'revision' => $this->revision];
    }
}
