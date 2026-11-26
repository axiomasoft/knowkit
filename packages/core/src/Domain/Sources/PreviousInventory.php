<?php
declare(strict_types=1);

namespace KnowKit\Domain\Sources;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;

/** One connector/namespace partition; historical item revisions remain exact. */
final readonly class PreviousInventory
{
    /** @param list<D\CrawlItem> $members */
    public function __construct(
        public D\Scope $scope,
        public D\AdapterId $connectorId,
        public string $sourceNamespace,
        public D\Decimal $revision,
        public array $members,
    ) {
        D\ValueGuard::text($sourceNamespace, 256);
        self::byOrigin($members);
    }

    public function assertMatches(D\Scope $scope, D\CrawlSnapshot $snapshot): void
    {
        if (Jcs::canonicalize($scope->toWire()) !== Jcs::canonicalize($this->scope->toWire())
            || $snapshot->connectorId->value !== $this->connectorId->value
            || $snapshot->sourceNamespace !== $this->sourceNamespace) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
    }

    /**
     * @param list<D\CrawlItem> $members
     * @return array<string, D\CrawlItem>
     */
    public static function byOrigin(array $members): array
    {
        D\ValueGuard::objects($members, D\CrawlItem::class, 1000000);
        $map = [];
        $paths = [];
        foreach ($members as $member) {
            if ($member->status !== 'verified' || isset($map[$member->originKey]) || isset($paths[$member->logicalPath])) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'inventory_member_collision');
            }
            $map[$member->originKey] = $member;
            $paths[$member->logicalPath] = true;
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}
