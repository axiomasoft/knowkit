<?php
declare(strict_types=1);

namespace KnowKit\Tests\Bridge\Mcp;

use KnowKit\Adapter\Sources\RecordedInventoryStore;
use KnowKit\Application\Sources\InventoryReconciler;
use KnowKit\Bridge\Mcp\Client\McpSourceConnector;
use KnowKit\Bridge\Mcp\Client\ScriptedMcpTransport;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\AdapterId;
use KnowKit\Domain\Capability;
use KnowKit\Domain\ConnectorRequest;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\KbId;
use KnowKit\Domain\MutationContext;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\Sources\PreviousInventory;
use KnowKit\Domain\TenantId;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/contract/ports/bootstrap.php';

#[Group('bridge:mcp-client')]
final class McpSourceConnectorTest extends TestCase
{
    public function testCompleteInventoryVerifiesSha256DownloadsAndSealsReceipt(): void
    {
        $doc1 = 'Refund policy v1';
        $doc2 = 'Shipping policy v1';
        $transport = (new ScriptedMcpTransport())
            ->enqueue('mcp:docs', 'resources/list', [
                'pinnedRevision' => 'rev-100',
                'resources' => [
                    [
                        'originKey' => 'doc-1',
                        'logicalPath' => '/refund.md',
                        'uri' => 'mcp://docs/refund.md',
                        'sha256' => hash('sha256', $doc1),
                        'byteLength' => (string) strlen($doc1),
                    ],
                ],
                'nextCursor' => 'page-2',
            ])
            ->enqueue('mcp:docs', 'resources/list', [
                'pinnedRevision' => 'rev-100',
                'resources' => [
                    [
                        'originKey' => 'doc-2',
                        'logicalPath' => '/shipping.md',
                        'uri' => 'mcp://docs/shipping.md',
                        'sha256' => hash('sha256', $doc2),
                        'byteLength' => (string) strlen($doc2),
                    ],
                ],
                'nextCursor' => null,
            ])
            ->on('mcp:docs', 'resources/read', static fn(array $params): array => match ($params['uri']) {
                'mcp://docs/refund.md' => ['content' => $doc1, 'revision' => 'rev-100'],
                'mcp://docs/shipping.md' => ['content' => $doc2, 'revision' => 'rev-100'],
            });

        $connector = new McpSourceConnector(new AdapterId('mcp_connector'), $transport);
        self::assertTrue(in_array(Capability::CompleteInventory, $connector->describe()->capabilities, true));

        $snapshot = $connector->inventory($this->scope(), new ConnectorRequest('mcp:docs', 'all'));
        self::assertTrue($snapshot->complete);
        self::assertCount(2, $snapshot->items);
        self::assertNotNull($snapshot->receipt);
        self::assertSame([], $snapshot->failures);
    }

    public function testMissingResourceListIsNotACompleteInventory(): void
    {
        $transport = (new ScriptedMcpTransport())
            ->enqueue('mcp:docs', 'resources/list', [
                'pinnedRevision' => 'rev-100',
            ]);
        $connector = new McpSourceConnector(new AdapterId('mcp_connector'), $transport);
        $snapshot = $connector->inventory($this->scope(), new ConnectorRequest('mcp:docs', 'all'));

        self::assertFalse($snapshot->complete);
        self::assertContains('inventory_incomplete', $snapshot->failures);
        self::assertSame([], $snapshot->items);
    }

    public function testMcpConnectorPartialPaginationBreakPreservesExistingSources(): void
    {
        $doc1 = 'Refund policy v1';
        $doc2 = 'Shipping policy v1';
        $scope = $this->scope();

        // First, establish an initial complete inventory with 2 documents.
        $initialTransport = (new ScriptedMcpTransport())
            ->enqueue('mcp:docs', 'resources/list', [
                'pinnedRevision' => 'rev-100',
                'resources' => [
                    [
                        'originKey' => 'doc-1',
                        'logicalPath' => '/refund.md',
                        'uri' => 'mcp://docs/refund.md',
                        'sha256' => hash('sha256', $doc1),
                        'byteLength' => (string) strlen($doc1),
                    ],
                    [
                        'originKey' => 'doc-2',
                        'logicalPath' => '/shipping.md',
                        'uri' => 'mcp://docs/shipping.md',
                        'sha256' => hash('sha256', $doc2),
                        'byteLength' => (string) strlen($doc2),
                    ],
                ],
                'nextCursor' => null,
            ])
            ->on('mcp:docs', 'resources/read', static fn(array $params): array => match ($params['uri']) {
                'mcp://docs/refund.md' => ['content' => $doc1, 'revision' => 'rev-100'],
                'mcp://docs/shipping.md' => ['content' => $doc2, 'revision' => 'rev-100'],
            });

        $store = new RecordedInventoryStore(
            static function (): void {},
            static function (): void {},
        );
        $reconciler = new InventoryReconciler($store);
        $connectorId = new AdapterId('mcp_connector');
        $initialConnector = new McpSourceConnector($connectorId, $initialTransport);
        $initialSnapshot = $initialConnector->inventory($scope, new ConnectorRequest('mcp:docs', 'all'));
        $reconciler->reconcile(
            $scope,
            $store->previous($scope, $connectorId, 'mcp:docs'),
            $initialSnapshot,
            new MutationContext(new JsonObject([
                'idempotencyKey' => 'mcp-initial-1',
                'requestId' => PortFixture::id(700),
            ]), PortFixture::validator()),
        );
        $previous = $store->previous($scope, $connectorId, 'mcp:docs');

        // Now, pagination breaks on page 2 after returning only doc-1.
        $partialTransport = (new ScriptedMcpTransport())
            ->enqueue('mcp:docs', 'resources/list', [
                'pinnedRevision' => 'rev-101',
                'resources' => [
                    [
                        'originKey' => 'doc-1',
                        'logicalPath' => '/refund.md',
                        'uri' => 'mcp://docs/refund.md',
                        'sha256' => hash('sha256', $doc1),
                        'byteLength' => (string) strlen($doc1),
                    ],
                ],
                'nextCursor' => 'page-2',
            ])
            ->enqueue('mcp:docs', 'resources/list', new \RuntimeException('connection reset'))
            ->on('mcp:docs', 'resources/read', static fn(): array => ['content' => $doc1, 'revision' => 'rev-101']);

        $partialConnector = new McpSourceConnector(new AdapterId('mcp_connector'), $partialTransport);
        $partialSnapshot = $partialConnector->inventory($scope, new ConnectorRequest('mcp:docs', 'all'));

        self::assertFalse($partialSnapshot->complete);
        self::assertContains('pagination_incomplete', $partialSnapshot->failures);

        $outcome = $reconciler->reconcile(
            $scope,
            $previous,
            $partialSnapshot,
            new MutationContext(new JsonObject([
                'idempotencyKey' => 'mcp-partial-1',
                'requestId' => PortFixture::id(701),
            ]), PortFixture::validator()),
        );

        // Existing doc-2 must NOT be removed when complete=false!
        self::assertSame([], $outcome->removed);
        self::assertCount(2, $outcome->members);
        self::assertSame(['doc-2'], $outcome->preservedOriginKeys);
    }

    public function testSha256MismatchMarksSnapshotIncomplete(): void
    {
        $transport = (new ScriptedMcpTransport())
            ->enqueue('mcp:docs', 'resources/list', [
                'pinnedRevision' => 'rev-102',
                'resources' => [
                    [
                        'originKey' => 'doc-1',
                        'logicalPath' => '/refund.md',
                        'uri' => 'mcp://docs/refund.md',
                        'sha256' => hash('sha256', 'expected content'),
                        'byteLength' => (string) strlen('expected content'),
                    ],
                ],
                'nextCursor' => null,
            ])
            ->on('mcp:docs', 'resources/read', static fn(): array => [
                'content' => 'tampered content',
                'revision' => 'rev-102',
            ]);

        $connector = new McpSourceConnector(new AdapterId('mcp_connector'), $transport);
        $snapshot = $connector->inventory($this->scope(), new ConnectorRequest('mcp:docs', 'all'));

        self::assertFalse($snapshot->complete);
        self::assertSame('failed', $snapshot->items[0]->status);
        self::assertContains('download_integrity_mismatch', $snapshot->failures);
    }

    private function scope(): Scope
    {
        return PortFixture::scope();
    }
}
