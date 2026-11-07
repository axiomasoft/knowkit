<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{ConnectorCapabilities, Scope, ConnectorRequest, CrawlSnapshot};

interface SourceConnector
{
    public function describe(): ConnectorCapabilities;
    /**
     * Resolve one immutable revision before inventory/downloads. Reconciliation requires
     * a scope-bound InventoryReceipt, including full verified downloads and completeness proof.
     * Capability claims alone do not qualify an installed production connector.
     */
    public function inventory(Scope $scope, ConnectorRequest $request): CrawlSnapshot;
}
