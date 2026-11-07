<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Sources;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Sources\InventoryReceipt;
use KnowKit\Domain\Sources\Rename;
use KnowKit\Port\SourceConnector;

/** SDK recording only: no HTTP, branch lookup, model transport or installed-adapter claim. */
final class RecordedConnector implements SourceConnector
{
    public int $inventoryCalls = 0;
    public int $describeCalls = 0;

    /** @param list<RecordedDownload> $downloads @param list<Rename> $renames */
    public function __construct(
        private readonly D\Scope $scope,
        private readonly D\CrawlSnapshot $recording,
        private readonly array $downloads,
        private readonly bool $fullTree = true,
        private readonly bool $allPages = true,
        private readonly bool $capped = false,
        private readonly array $renames = [],
    ) {
        D\ValueGuard::objects($downloads, RecordedDownload::class, 1000000);
    }

    public function describe(): D\ConnectorCapabilities
    {
        $this->describeCalls++;

        return new D\ConnectorCapabilities([D\Capability::CompleteInventory], ['source-inventory-1.0']);
    }

    public function inventory(D\Scope $scope, D\ConnectorRequest $request): D\CrawlSnapshot
    {
        $this->inventoryCalls++;
        if (Jcs::canonicalize($scope->toWire()) !== Jcs::canonicalize($this->scope->toWire())
            || $request->sourceNamespace !== $this->recording->sourceNamespace) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }

        return InventoryReceipt::seal(
            $scope, $this->recording,
            array_map(static fn (RecordedDownload $download) => $download->verify(), $this->downloads),
            $this->fullTree, $this->allPages, $this->capped, $this->renames,
        );
    }
}
