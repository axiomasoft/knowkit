<?php
declare(strict_types=1);

namespace KnowKit\Tests\Sources\Inventory;

use KnowKit\Adapter\Sources\RecordedConnector;
use KnowKit\Adapter\Sources\RecordedDownload;
use KnowKit\Adapter\Sources\RecordedInventoryStore;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sources\Rename;
use KnowKit\Tests\Contract\Ports\PortFixture;

require_once dirname(__DIR__, 2) . '/contract/ports/bootstrap.php';

final class Controls
{
    public bool $fail = false;
    public bool $allowed = true;
    public bool $changeAccess = false;
}

final class Fixture
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function data(): array
    {
        return json_decode((string) file_get_contents(self::root() . '/tests/fixtures/sources/v1/cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function scope(): D\Scope
    {
        return PortFixture::scope();
    }

    public static function context(string $key, ?string $revision = null): D\MutationContext
    {
        $data = ['idempotencyKey' => $key, 'requestId' => PortFixture::id(700)];
        if ($revision !== null) {
            $data['expectedRevision'] = $revision;
            $data['expectedEpoch'] = self::scope()->policyEpoch->value;
        }

        return new D\MutationContext(new JsonObject($data), PortFixture::validator());
    }

    public static function item(array $row, ?string $revision = null): D\CrawlItem
    {
        return new D\CrawlItem(
            $row['originKey'], $row['logicalPath'], $revision ?? $row['revision'],
            new D\Sha256($row['contentDigest']), new D\Uint64($row['byteLength']), $row['status'] ?? 'verified',
        );
    }

    public static function connector(array $case, string $namespace = 'repo:a', ?D\Scope $scope = null): RecordedConnector
    {
        $scope ??= self::scope();
        $items = [];
        $downloads = [];
        foreach ($case['items'] as $row) {
            $item = self::item($row, $case['pin']);
            $items[] = $item;
            if ($item->status === 'verified') {
                $downloads[] = new RecordedDownload(
                    $item, $row['readRevision'] ?? $case['pin'], $row['bytes'], $row['maxBytes'] ?? 4096,
                );
            }
        }
        $snapshot = new D\CrawlSnapshot(
            new D\AdapterId('recorded'), $namespace, $case['pin'], $case['complete'],
            $items, $case['failures'], $case['omittedCount'], new D\Fingerprint(str_repeat('0', 64)),
            new D\UtcInstant('2026-11-07T12:00:00Z'),
        );

        return new RecordedConnector(
            $scope, $snapshot, $downloads, $case['fullTree'], $case['allPages'], $case['capped'],
            array_map(static fn (array $r): Rename => new Rename(...$r), $case['renames'] ?? []),
        );
    }

    public static function snapshot(array $case, string $namespace = 'repo:a'): D\CrawlSnapshot
    {
        return self::connector($case, $namespace)->inventory(self::scope(), new D\ConnectorRequest($namespace, 'main'));
    }

    public static function store(?\Closure $authorize = null, ?\Closure $beforePublish = null): RecordedInventoryStore
    {
        return new RecordedInventoryStore($authorize ?? static function (): void {}, $beforePublish ?? static function (): void {});
    }
}
