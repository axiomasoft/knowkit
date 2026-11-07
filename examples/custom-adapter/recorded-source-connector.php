<?php
declare(strict_types=1);

use KnowKit\Adapter\Sources\ConnectorQualifier;
use KnowKit\Adapter\Sources\RecordedConnector;
use KnowKit\Adapter\Sources\RecordedDownload;
use KnowKit\Domain as D;

/**
 * SDK factory, not a command or production GitHub/web installation. The host supplies
 * trusted Scope and an already resolved immutable pin; no network or model is invoked.
 */
return static function (D\Scope $scope, string $exactRevision): D\CrawlSnapshot {
    $bytes = "Recorded source\n";
    $item = new D\CrawlItem(
        'host-allocated-origin', 'docs/example.md', $exactRevision,
        new D\Sha256(hash('sha256', $bytes)), new D\Uint64((string) strlen($bytes)), 'verified',
    );
    $recording = new D\CrawlSnapshot(
        new D\AdapterId('recorded'), 'example:repository', $exactRevision, true,
        [$item], [], 0, new D\Fingerprint(str_repeat('0', 64)), new D\UtcInstant('2026-11-07T12:00:00Z'),
    );
    $connector = new RecordedConnector(
        $scope, $recording, [new RecordedDownload($item, $exactRevision, $bytes, 4096)],
    );

    return (new ConnectorQualifier())->inventory(
        $connector, $scope, new D\ConnectorRequest('example:repository', $exactRevision),
    );
};
