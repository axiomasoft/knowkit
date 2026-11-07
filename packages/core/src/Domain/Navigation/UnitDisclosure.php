<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;

/** A single committed delivery, never authority to send these bytes again. */
final readonly class UnitDisclosure
{
    /** @param list<Unit> $units */
    public function __construct(
        public D\DocumentRevisionRef $revision,
        public D\DocumentId $documentId,
        public array $units,
        public D\UsageId $usageRef,
        public D\Ulid $attemptId,
        public D\UtcInstant $checkedAt,
        public D\Sha256 $hostScopeHash,
        public ?D\FrozenJson $coverage = null,
    ) {
        D\ValueGuard::objects($units, Unit::class);
        if ($units === [] || count($units) > 24
            || array_sum(array_map(static fn (Unit $unit): int => strlen($unit->text()), $units)) > 8192) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
    }

    public function __debugInfo(): array
    {
        return ['usageRef' => $this->usageRef, 'revision' => $this->revision];
    }
}
