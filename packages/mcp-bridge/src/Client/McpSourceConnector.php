<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Client;

use KnowKit\Domain\AdapterId;
use KnowKit\Domain\Capability;
use KnowKit\Domain\ConnectorCapabilities;
use KnowKit\Domain\ConnectorRequest;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\CrawlItem;
use KnowKit\Domain\CrawlSnapshot;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\Sources\InventoryReceipt;
use KnowKit\Domain\Sources\VerifiedDownload;
use KnowKit\Domain\Uint64;
use KnowKit\Domain\UtcInstant;
use KnowKit\Port\SourceConnector;

final readonly class McpSourceConnector implements SourceConnector
{
    public function __construct(
        private AdapterId $connectorId,
        private McpTransport $transport,
        private int $maxDownloadBytes = 8388608,
    ) {
    }

    public function describe(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            [Capability::CompleteInventory],
            ['source-inventory-1.0'],
        );
    }

    public function inventory(Scope $scope, ConnectorRequest $request): CrawlSnapshot
    {
        $serverId = $request->sourceNamespace;
        $cursor = null;
        $pinnedRevision = '';
        $items = [];
        $verifiedDownloads = [];
        $failures = [];
        $fullTree = true;
        $allPages = true;
        $capped = false;

        try {
            do {
                $params = ['selector' => $request->selector];
                if ($cursor !== null) {
                    $params['cursor'] = $cursor;
                }
                $page = $this->transport->call($serverId, 'resources/list', $params);
                if ($pinnedRevision === '') {
                    $pinnedRevision = (string) ($page['pinnedRevision'] ?? 'rev-0');
                }
                if (isset($page['capped']) && $page['capped'] === true) {
                    $capped = true;
                }
                if (!is_array($page['resources'] ?? null)) {
                    $fullTree = false;
                    $failures[] = 'inventory_incomplete';
                    $rawResources = [];
                } else {
                    $rawResources = $page['resources'];
                }

                foreach ($rawResources as $resource) {
                    if (!is_array($resource)) {
                        continue;
                    }
                    $originKey = (string) ($resource['originKey'] ?? ($resource['uri'] ?? ''));
                    $logicalPath = (string) ($resource['logicalPath'] ?? $originKey);
                    $uri = (string) ($resource['uri'] ?? $originKey);
                    $expectedSha256 = (string) ($resource['sha256'] ?? str_repeat('0', 64));
                    $expectedBytes = (string) ($resource['byteLength'] ?? '0');

                    $itemRevision = (string) ($resource['immutableRevision'] ?? $pinnedRevision);
                    $candidateItem = new CrawlItem(
                        $originKey,
                        $logicalPath,
                        $pinnedRevision,
                        new Sha256($expectedSha256),
                        new Uint64($expectedBytes),
                        'verified',
                    );

                    try {
                        $readResponse = $this->transport->call($serverId, 'resources/read', [
                            'uri' => $uri,
                            'revision' => $itemRevision,
                        ]);
                        $bytes = (string) ($readResponse['content'] ?? '');
                        $readRevision = (string) ($readResponse['revision'] ?? $itemRevision);
                        $verifiedDownloads[] = VerifiedDownload::verify(
                            $candidateItem,
                            $readRevision,
                            $bytes,
                            $this->maxDownloadBytes,
                        );
                        $items[] = $candidateItem;
                    } catch (DomainFailure $failure) {
                        $fullTree = false;
                        $failures[] = $failure->errorCode;
                        $items[] = new CrawlItem(
                            $originKey,
                            $logicalPath,
                            $pinnedRevision,
                            new Sha256($expectedSha256),
                            new Uint64($expectedBytes),
                            'failed',
                        );
                    } catch (\Throwable) {
                        $fullTree = false;
                        $failures[] = 'download_transport_error';
                        $items[] = new CrawlItem(
                            $originKey,
                            $logicalPath,
                            $pinnedRevision,
                            new Sha256($expectedSha256),
                            new Uint64($expectedBytes),
                            'unavailable',
                        );
                    }
                }

                $nextCursor = $page['nextCursor'] ?? null;
                $cursor = is_string($nextCursor) && $nextCursor !== '' ? $nextCursor : null;
            } while ($cursor !== null);
        } catch (\Throwable) {
            $allPages = false;
            $failures[] = 'pagination_incomplete';
        }

        if ($pinnedRevision === '') {
            $pinnedRevision = 'rev-0';
        }

        $failures = array_values(array_unique($failures));
        $complete = $fullTree && $allPages && !$capped;
        $placeholderDigest = new Fingerprint(HashDomain::ResourceInventory->digest([
            'connectorId' => $this->connectorId->value,
            'pinnedRevision' => $pinnedRevision,
        ]));

        $snapshot = new CrawlSnapshot(
            $this->connectorId,
            $request->sourceNamespace,
            $pinnedRevision,
            $complete,
            $items,
            $failures,
            0,
            $placeholderDigest,
            new UtcInstant(gmdate('Y-m-d\TH:i:s\Z')),
        );

        return InventoryReceipt::seal(
            $scope,
            $snapshot,
            $verifiedDownloads,
            $fullTree,
            $allPages,
            $capped,
        );
    }
}
