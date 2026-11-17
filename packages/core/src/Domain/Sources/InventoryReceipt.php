<?php
declare(strict_types=1);

namespace KnowKit\Domain\Sources;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;

/** Local contract proof, not a signature or evidence of real network security. */
final readonly class InventoryReceipt
{
    /**
     * @param list<Rename> $renames
     * @param list<string> $reasons
     */
    private function __construct(
        private string $scopeBinding,
        private D\Fingerprint $digest,
        public array $renames,
        public array $reasons,
    ) {}

    /**
     * @param list<VerifiedDownload> $downloads
     * @param list<Rename> $renames
     */
    public static function seal(
        D\Scope $scope,
        D\CrawlSnapshot $snapshot,
        array $downloads,
        bool $fullTree,
        bool $allPages,
        bool $capped,
        array $renames = [],
    ): D\CrawlSnapshot {
        D\ValueGuard::objects($renames, Rename::class);
        self::assertDownloads($snapshot, $downloads);
        if ($snapshot->complete && (!$fullTree || !$allPages || $capped || $snapshot->omittedCount !== 0)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'unsupported_completeness_proof');
        }
        $digest = self::digest($scope, $snapshot, $renames);
        $receipt = new self(
            Jcs::canonicalize($scope->toWire()), $digest, $renames,
            self::proofReasons($fullTree, $allPages, $capped),
        );

        return new D\CrawlSnapshot(
            $snapshot->connectorId, $snapshot->sourceNamespace, $snapshot->pinnedRevision,
            $snapshot->complete, $snapshot->items, $snapshot->failures, $snapshot->omittedCount,
            $digest, $snapshot->observedAt, $receipt,
        );
    }

    /** @return list<string> */
    private static function proofReasons(bool $fullTree, bool $allPages, bool $capped): array
    {
        $reasons = [];
        if (!$fullTree) {
            $reasons[] = 'full_tree_unproven';
        }
        if (!$allPages) {
            $reasons[] = 'pagination_incomplete';
        }
        if ($capped) {
            $reasons[] = 'crawl_capped';
        }

        return $reasons;
    }

    public function assertMatches(D\Scope $scope, D\CrawlSnapshot $snapshot): void
    {
        if ($this->scopeBinding !== Jcs::canonicalize($scope->toWire())
            || $this->digest->value !== $snapshot->inventoryDigest->value
            || $this->digest->value !== self::digest($scope, $snapshot, $this->renames)->value) {
            throw new D\DomainFailure(D\Outcome::Denied, 'inventory_receipt_mismatch');
        }
    }

    /** @param list<VerifiedDownload> $downloads */
    private static function assertDownloads(D\CrawlSnapshot $snapshot, array $downloads): void
    {
        D\ValueGuard::objects($downloads, VerifiedDownload::class, 1000000);
        $verified = [];
        foreach ($downloads as $download) {
            $key = $download->item->originKey;
            if (isset($verified[$key]) || $download->readRevision !== $snapshot->pinnedRevision) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'download_revision_mismatch');
            }
            $verified[$key] = $download->item;
        }
        foreach ($snapshot->items as $item) {
            if ($item->immutableRevision !== $snapshot->pinnedRevision) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'download_revision_mismatch');
            }
            $verifiedItem = $verified[$item->originKey] ?? null;
            if (($item->status === 'verified' && ($verifiedItem === null || !$verifiedItem->equals($item)))
                || ($item->status !== 'verified' && isset($verified[$item->originKey]))) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'unverified_download');
            }
            unset($verified[$item->originKey]);
        }
        if ($verified !== []) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'unlisted_download');
        }
    }

    /** @param list<Rename> $renames */
    private static function digest(D\Scope $scope, D\CrawlSnapshot $snapshot, array $renames): D\Fingerprint
    {
        $items = $snapshot->items;
        usort($items, static fn (D\CrawlItem $a, D\CrawlItem $b): int => strcmp($a->originKey, $b->originKey));
        $renameTuples = array_map(static fn (Rename $r): array => [
            $r->oldOriginKey, $r->newOriginKey, $r->oldPath, $r->newPath,
        ], $renames);
        usort($renameTuples, static fn (array $a, array $b): int => strcmp(Jcs::canonicalize($a), Jcs::canonicalize($b)));

        return new D\Fingerprint(HashDomain::ResourceInventory->digest(new JsonObject([
            'kind' => 'source-inventory', 'schemaVersion' => 'source-inventory-1.0',
            'scope' => $scope->toWire(), 'connectorId' => $snapshot->connectorId->value,
            'sourceNamespace' => $snapshot->sourceNamespace, 'pinnedRevision' => $snapshot->pinnedRevision,
            'complete' => $snapshot->complete, 'failures' => $snapshot->failures,
            'omittedCount' => $snapshot->omittedCount, 'observedAt' => $snapshot->observedAt->value,
            'items' => array_map(static fn (D\CrawlItem $i): array => [
                $i->originKey, $i->logicalPath, $i->immutableRevision,
                $i->contentDigest->value, $i->byteLength->value, $i->status,
            ], $items), 'renames' => $renameTuples,
        ])));
    }
}
