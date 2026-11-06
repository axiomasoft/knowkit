<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{ConnectorCapabilities, Scope, ConnectorRequest, CrawlSnapshot};

interface SourceConnector
{
    public function describe(): ConnectorCapabilities;
    /** Resolve one exact revision before inventory/downloads; complete requires a proved full inventory. */
    public function inventory(Scope $scope, ConnectorRequest $request): CrawlSnapshot;
}
