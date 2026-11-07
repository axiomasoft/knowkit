<?php
declare(strict_types=1);

namespace KnowKit\Domain\Sources;

use KnowKit\Domain as D;

final readonly class ReconciliationOutcome
{
    /**
     * @param list<D\CrawlItem> $members
     * @param list<D\CrawlItem> $removed
     * @param list<string> $preservedOriginKeys
     * @param list<string> $reasons
     */
    public function __construct(
        public D\CrawlSnapshot $inventory,
        public array $members,
        public array $removed,
        public array $preservedOriginKeys,
        public array $reasons,
    ) {
        PreviousInventory::byOrigin($members);
        PreviousInventory::byOrigin($removed);
        foreach ($preservedOriginKeys as $key) {
            D\ValueGuard::text($key, 1024);
        }
        D\ValueGuard::strings($reasons, true, true);
        if (!$inventory->complete && $removed !== []) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'incomplete_deletion');
        }
    }

    public function publishesEmptyPartition(): bool
    {
        return $this->inventory->complete && $this->members === [];
    }
}
