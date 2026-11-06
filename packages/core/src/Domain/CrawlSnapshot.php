<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class CrawlSnapshot
{
    /**
     * @param list<CrawlItem> $items
     * @param list<string> $failures
     */
    public function __construct(public AdapterId $connectorId, public string $sourceNamespace, public string $pinnedRevision,
        public bool $complete, public array $items, public array $failures, public ?int $omittedCount,
        public Fingerprint $inventoryDigest, public UtcInstant $observedAt)
    {
        ValueGuard::text($sourceNamespace, 256); ValueGuard::text($pinnedRevision, 256);
        ValueGuard::objects($items, CrawlItem::class, 1000000);
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item->originKey]) || ($complete && $item->status !== 'verified')) {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
            }
            $seen[$item->originKey] = true;
        }
        ValueGuard::strings($failures, false, true);
        if (($omittedCount !== null && ($omittedCount < 0 || $omittedCount > 4294967295)) || ($complete && ($failures !== [] || ($omittedCount ?? 0) !== 0))) {
            throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
        }
    }
}
