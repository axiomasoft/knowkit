<?php
declare(strict_types=1);

namespace KnowKit\Tests\Bridge\Mcp;

use KnowKit\Bridge\Mcp\Server\AuthenticatedMcpContext;
use KnowKit\Bridge\Mcp\Server\CanonicalToolRegistry;
use KnowKit\Bridge\Mcp\Server\DirectivePromptHandler;
use KnowKit\Bridge\Mcp\Server\McpBackend;
use KnowKit\Bridge\Mcp\Server\McpScopeAuthenticator;
use KnowKit\Bridge\Mcp\Server\ResourceTemplateHandler;
use KnowKit\Bridge\Mcp\Server\StatelessMcpServer;
use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\InstanceId;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Layers\DirectiveArtifact;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\Ulid;
use KnowKit\Domain\UtcInstant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('bridge:mcp-server')]
final class ServerProtocolTest extends TestCase
{
    private const TENANT_ID = '01J00000000000000000000001';
    private const KB_ID = '01J00000000000000000000002';
    private const ACTOR_ID = '01J00000000000000000000003';
    private const REVISION_ID = '01J00000000000000000000004';
    private const DIRECTIVE_ID = '01J00000000000000000000005';
    private const ARTIFACT_ID = '01J00000000000000000000006';

    public function testServerDiscoverReturnsSingleRoundTripWithSep2549AndJcsSortedTools(): void
    {
        $server = $this->createServer();
        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-1',
            'method' => 'server/discover',
            'params' => [],
        ], ['authorization' => 'Bearer valid-token']);

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame('req-1', $response['id']);
        $result = $response['result'];
        self::assertSame(StatelessMcpServer::PROTOCOL_VERSION, $result['protocolVersion']);
        self::assertTrue($result['stateless']);
        self::assertSame(['cacheScope' => 'tenant', 'ttlMs' => 300000], $result['cache']);
        self::assertCount(5, $result['tools']);
        self::assertCount(3, $result['resourceTemplates']);
        self::assertCount(1, $result['prompts']);

        foreach ($result['tools'] as $tool) {
            $canonical = Jcs::canonicalize($tool['inputSchema']);
            $reencoded = json_encode($tool['inputSchema'], JSON_THROW_ON_ERROR);
            self::assertSame($canonical, $reencoded);
        }
    }

    public function testToolsListHasDeterministicJcsSchemaDigests(): void
    {
        $backend = $this->createBackend();
        $registry = CanonicalToolRegistry::withDefaultTools($backend);
        $digest1 = $registry->schemaDigest('knowkit.search_layered');
        $digest2 = $registry->schemaDigest('knowkit.search_layered');

        self::assertSame($digest1, $digest2);
        self::assertCount(5, $registry->listTools());
    }

    public function testAllFiveToolsExecuteStatelesslyAndSearchLayeredReturnsZeroChunkText(): void
    {
        $server = $this->createServer();
        $headers = ['authorization' => 'Bearer valid-token'];

        $outlineResp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 't1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.navigate_outline',
                'arguments' => ['revisionId' => self::REVISION_ID, 'maxDepth' => 3, 'limit' => 10],
            ],
        ], $headers);
        self::assertFalse($outlineResp['result']['isError']);
        self::assertSame(self::REVISION_ID, $outlineResp['result']['structuredContent']['revisionId']);

        $searchResp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 't2',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.search_layered',
                'arguments' => ['query' => 'refund policy', 'topK' => 5],
            ],
        ], $headers);
        $searchContent = $searchResp['result']['structuredContent'];
        self::assertArrayHasKey('selectionToken', $searchContent);
        self::assertCount(1, $searchContent['candidatesMeta']);
        self::assertArrayNotHasKey('text', $searchContent['candidatesMeta'][0]);
        self::assertArrayNotHasKey('content', $searchContent['candidatesMeta'][0]);

        $discloseResp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 't3',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.disclose_evidence',
                'arguments' => [
                    'selectionToken' => $searchContent['selectionToken'],
                    'resolutionTier' => 'R2',
                    'maxFragments' => 4,
                ],
            ],
        ], $headers);
        self::assertSame('[#1]', $discloseResp['result']['structuredContent']['fragments'][0]['alias']);

        $readResp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 't4',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.read_units',
                'arguments' => [
                    'revisionId' => self::REVISION_ID,
                    'unitIds' => ['unit-1'],
                ],
            ],
        ], $headers);
        self::assertSame('Refunds are allowed within 14 days.', $readResp['result']['structuredContent']['units'][0]['text']);

        $verifyResp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 't5',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.verify_grounding',
                'arguments' => [
                    'selectionToken' => $searchContent['selectionToken'],
                    'responseText' => 'Refunds are allowed within 14 days [#1].',
                    'citations' => ['[#1]'],
                ],
            ],
        ], $headers);
        self::assertTrue($verifyResp['result']['structuredContent']['grounded']);
    }

    public function testResourcesAndPromptsAreServedForAuthorizedTenant(): void
    {
        $server = $this->createServer();
        $headers = ['authorization' => 'Bearer valid-token'];

        $templates = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'r1',
            'method' => 'resources/templates/list',
            'params' => [],
        ], $headers);
        self::assertCount(3, $templates['result']['resourceTemplates']);

        $readDigest = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'r2',
            'method' => 'resources/read',
            'params' => ['uri' => 'knowkit://support_faq/' . self::KB_ID . '/digest/' . self::ARTIFACT_ID],
        ], $headers);
        self::assertSame('{"digest":"ok"}', $readDigest['result']['contents'][0]['text']);

        $promptsList = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'p1',
            'method' => 'prompts/list',
            'params' => [],
        ], $headers);
        $promptName = $promptsList['result']['prompts'][0]['name'];

        $promptGet = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'p2',
            'method' => 'prompts/get',
            'params' => ['name' => $promptName],
        ], $headers);
        self::assertSame('trusted_directive', $promptGet['result']['meta']['trustTier']);
        self::assertSame(14, $promptGet['result']['meta']['lockedRules']['refund.max_days']);
    }

    private function createServer(DisclosureMode $mode = DisclosureMode::VerbatimAllowed): StatelessMcpServer
    {
        $scope = new Scope(
            new TenantId(self::TENANT_ID),
            new KbId(self::KB_ID),
            new ActorId(self::ACTOR_ID),
            'support.answer',
            new Decimal('1'),
            new Sha256(str_repeat('a', 64)),
        );
        $context = new AuthenticatedMcpContext(
            InstanceId::fromString('support_faq'),
            $scope,
            ['mcp:support'],
            $mode,
        );
        $auth = new class($context) implements McpScopeAuthenticator {
            public function __construct(private readonly AuthenticatedMcpContext $ctx) {}
            public function authenticate(array $transportHeaders): AuthenticatedMcpContext
            {
                return $this->ctx;
            }
        };

        $directive = new DirectiveArtifact(
            tenantId: new TenantId(self::TENANT_ID),
            directiveId: new Ulid(self::DIRECTIVE_ID),
            version: 1,
            title: 'Support Rules',
            contentFormat: 'compact_markdown',
            body: 'Always verify refund eligibility.',
            bodyDigest: new Sha256(hash('sha256', 'Always verify refund eligibility.')),
            lockedRules: new JsonObject(['refund.max_days' => 14]),
            promotedBy: new ActorId(self::ACTOR_ID),
            promotedAt: new UtcInstant('2026-11-27T10:00:00Z'),
            derivedFrom: [],
            approvalDigest: new Sha256(str_repeat('b', 64)),
        );

        $backend = $this->createBackend();

        return new StatelessMcpServer(
            $auth,
            CanonicalToolRegistry::withDefaultTools($backend),
            new ResourceTemplateHandler($backend),
            new DirectivePromptHandler([$directive]),
        );
    }

    private function createBackend(): McpBackend
    {
        return new class() implements McpBackend {
            public function navigateOutline(AuthenticatedMcpContext $context, string $revisionId, int $maxDepth, int $limit): array
            {
                return ['revisionId' => $revisionId, 'nodes' => [['id' => 'sec-1', 'title' => 'Refunds', 'depth' => 1]]];
            }

            public function searchLayered(AuthenticatedMcpContext $context, string $query, ?string $stackId, int $topK): array
            {
                return [
                    'selectionToken' => str_repeat('c', 64),
                    'candidatesMeta' => [
                        [
                            'chunkId' => '01J00000000000000000000099',
                            'scoreMilli' => 920,
                            'text' => 'MUST_BE_STRIPPED_BY_TOOL',
                        ],
                    ],
                ];
            }

            public function discloseEvidence(AuthenticatedMcpContext $context, string $selectionToken, ResolutionTier $tier, int $maxFragments): array
            {
                return [
                    'selectionToken' => $selectionToken,
                    'tier' => $tier->value,
                    'fragments' => [
                        ['alias' => '[#1]', 'summary' => 'Refunds are allowed within 14 days.'],
                    ],
                ];
            }

            public function readUnits(AuthenticatedMcpContext $context, string $revisionId, array $unitIds): array
            {
                return [
                    'revisionId' => $revisionId,
                    'units' => [
                        ['unitId' => $unitIds[0], 'text' => 'Refunds are allowed within 14 days.'],
                    ],
                ];
            }

            public function verifyGrounding(AuthenticatedMcpContext $context, string $selectionToken, string $responseText, array $citations): array
            {
                // Reference CitationAliasMap class existence to ensure compiler package integration.
                $validClass = CitationAliasMap::class;

                return [
                    'grounded' => $citations === ['[#1]'] && str_contains($responseText, '[#1]'),
                    'aliasMapClass' => $validClass,
                    'missing' => [],
                ];
            }

            public function listResources(AuthenticatedMcpContext $context): array
            {
                return [];
            }

            public function readOutlineResource(AuthenticatedMcpContext $context, string $kb, string $revision): array
            {
                return ['uri' => "knowkit://{$context->instanceId->value}/{$kb}/outline/{$revision}", 'mimeType' => 'application/json', 'text' => '{"outline":[]}'];
            }

            public function readDigestResource(AuthenticatedMcpContext $context, string $kb, string $artifactId): array
            {
                return ['uri' => "knowkit://{$context->instanceId->value}/{$kb}/digest/{$artifactId}", 'mimeType' => 'application/json', 'text' => '{"digest":"ok"}'];
            }

            public function readCitationResource(AuthenticatedMcpContext $context, string $kb, string $citationId): array
            {
                return ['uri' => "knowkit://{$context->instanceId->value}/{$kb}/citation/{$citationId}", 'mimeType' => 'application/json', 'text' => '{"verbatim":"secret"}'];
            }
        };
    }
}
