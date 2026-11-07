<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Sources;

use KnowKit\Domain as D;
use KnowKit\Port\SourceConnector;

/** Runs only the explicit inventory call. A contract result does not qualify installation. */
final class ConnectorQualifier
{
    public function inventory(SourceConnector $connector, D\Scope $scope, D\ConnectorRequest $request): D\CrawlSnapshot
    {
        $capabilities = $connector->describe();
        $snapshot = $connector->inventory($scope, $request);
        if ($snapshot->sourceNamespace !== $request->sourceNamespace || $snapshot->receipt === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'unsupported_completeness_proof');
        }
        if ($snapshot->complete) {
            $capabilities->require(D\Capability::CompleteInventory, 'source-inventory-1.0');
        }
        $snapshot->receipt->assertMatches($scope, $snapshot);

        return $snapshot;
    }
}
