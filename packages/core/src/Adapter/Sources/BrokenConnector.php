<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Sources;

use KnowKit\Domain as D;
use KnowKit\Port\SourceConnector;

/** Intentionally unsupported proof for SDK negative qualification. */
final readonly class BrokenConnector implements SourceConnector
{
    public function __construct(
        private D\CrawlSnapshot $unprovedRecording,
    ) {}

    public function describe(): D\ConnectorCapabilities
    {
        return new D\ConnectorCapabilities([D\Capability::CompleteInventory], ['source-inventory-1.0']);
    }

    public function inventory(D\Scope $scope, D\ConnectorRequest $request): D\CrawlSnapshot
    {
        return $this->unprovedRecording;
    }
}
