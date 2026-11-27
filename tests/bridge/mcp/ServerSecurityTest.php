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
use KnowKit\Domain\ActorId;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\DecisionReceipt;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\InstanceId;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Layers\DirectiveArtifact;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\QueryGrant;
use KnowKit\Domain\ResourceRef;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\Ulid;
use KnowKit\Domain\UtcInstant;
use KnowKit\Port\AccessPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('bridge:mcp-server')]
final class ServerSecurityTest extends TestCase
{
    private const TENANT_ID = '01J00000000000000000000001';
    private const FOREIGN_TENANT_ID = '01J99999999999999999999999';
    private const KB_ID = '01J00000000000000000000002';
    private const ACTOR_ID = '01J00000000000000000000003';
    private const REVISION_ID = '01J00000000000000000000004';
    private const DIRECTIVE_ID = '01J00000000000000000000005';
    private const CITATION_ID = '01J00000000000000000000007';

    public function testNoToolExposesScopeOrTenantFieldsInSchema(): void
    {
        $registry = CanonicalToolRegistry::withDefaultTools($this->dummyBackend());
        $forbidden = ['tenantId', 'actorId', 'scope', 'grants', 'hostScopeHash'];

        foreach ($registry->listTools() as $tool) {
            self::assertFalse($tool['inputSchema']['additionalProperties']);
            foreach ($forbidden as $field) {
                self::assertArrayNotHasKey($field, $tool['inputSchema']['properties']);
            }
        }
    }

    public function testRejectsTenantIdSpoofingInToolArguments(): void
    {
        $server = $this->createServer(DisclosureMode::VerbatimAllowed);
        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-attack-1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.search_layered',
                'arguments' => [
                    'query' => 'salaries',
                    'tenantId' => self::FOREIGN_TENANT_ID,
                ],
            ],
        ], ['authorization' => 'Bearer valid']);

        self::assertSame(-32602, $response['error']['code']);
        self::assertSame('unknown_field', $response['error']['message']);
        self::assertSame('unknown_field', $response['error']['data']['knowkitCode']);
        self::assertSame('tenantId', $response['error']['data']['field']);
    }

    public function testRejectsVerbatimReadUnitsAndCitationResourceUnderDerivedOnly(): void
    {
        $server = $this->createServer(DisclosureMode::DerivedOnly);
        $headers = ['authorization' => 'Bearer valid'];

        $readUnitsResp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-attack-2',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.read_units',
                'arguments' => [
                    'revisionId' => self::REVISION_ID,
                    'unitIds' => ['unit-1'],
                ],
            ],
        ], $headers);

        self::assertSame(-32003, $readUnitsResp['error']['code']);
        self::assertSame('verbatim_disclosure_forbidden', $readUnitsResp['error']['message']);
        self::assertSame('denied', $readUnitsResp['error']['data']['outcome']);

        $discloseR3Resp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-attack-3',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.disclose_evidence',
                'arguments' => [
                    'selectionToken' => str_repeat('a', 64),
                    'resolutionTier' => 'R3',
                ],
            ],
        ], $headers);
        self::assertSame(-32003, $discloseR3Resp['error']['code']);
        self::assertSame('verbatim_disclosure_forbidden', $discloseR3Resp['error']['message']);

        $citationResourceResp = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-attack-4',
            'method' => 'resources/read',
            'params' => [
                'uri' => 'knowkit://support_faq/' . self::KB_ID . '/citation/' . self::CITATION_ID,
            ],
        ], $headers);
        self::assertSame(-32003, $citationResourceResp['error']['code']);
        self::assertSame('verbatim_disclosure_forbidden', $citationResourceResp['error']['message']);
    }

    public function testAuthenticatesBeforeParsingBusinessArgumentsAndEnforcesFreshAccessPolicy(): void
    {
        $unauthServer = $this->createServer(DisclosureMode::VerbatimAllowed, failAuth: true);
        $unauthResp = $unauthServer->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-unauth',
            'method' => 'tools/call',
            'params' => ['name' => 'knowkit.search_layered', 'arguments' => ['tenantId' => 'bad']],
        ], []);
        self::assertSame(-32003, $unauthResp['error']['code']);
        self::assertSame('unauthenticated', $unauthResp['error']['message']);

        $deniedPolicy = new class() implements AccessPolicy {
            public function authorizeQuery(Scope $scope): QueryGrant
            {
                throw new DomainFailure(Outcome::Denied, 'policy_denied');
            }

            public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt
            {
                throw new DomainFailure(Outcome::Denied, 'policy_denied');
            }
        };

        $policyDeniedServer = $this->createServer(DisclosureMode::VerbatimAllowed, policy: $deniedPolicy);
        $policyResp = $policyDeniedServer->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-policy',
            'method' => 'tools/call',
            'params' => [
                'name' => 'knowkit.disclose_evidence',
                'arguments' => ['selectionToken' => str_repeat('a', 64), 'resolutionTier' => 'R1'],
            ],
        ], ['authorization' => 'Bearer valid']);
        self::assertSame(-32003, $policyResp['error']['code']);
        self::assertSame('policy_denied', $policyResp['error']['message']);
    }

    public function testRejectsCrossInstanceResourceReadAndCrossTenantPrompt(): void
    {
        $server = $this->createServer(DisclosureMode::VerbatimAllowed);
        $headers = ['authorization' => 'Bearer valid'];

        $crossInstance = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-cross-inst',
            'method' => 'resources/read',
            'params' => [
                'uri' => 'knowkit://enterprise_kb/' . self::KB_ID . '/outline/' . self::REVISION_ID,
            ],
        ], $headers);
        self::assertSame(-32003, $crossInstance['error']['code']);
        self::assertSame('scope_mismatch', $crossInstance['error']['message']);

        $crossTenantPrompt = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'req-cross-prompt',
            'method' => 'prompts/get',
            'params' => ['name' => 'knowkit.directive.' . self::DIRECTIVE_ID . '.v1'],
        ], $headers);
        self::assertSame(-32003, $crossTenantPrompt['error']['code']);
        self::assertSame('prompt_not_found', $crossTenantPrompt['error']['message']);
    }

    private function createServer(
        DisclosureMode $mode,
        bool $failAuth = false,
        ?AccessPolicy $policy = null,
    ): StatelessMcpServer {
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
        $auth = new class($context, $failAuth) implements McpScopeAuthenticator {
            public function __construct(
                private readonly AuthenticatedMcpContext $ctx,
                private readonly bool $failAuth,
            ) {}

            public function authenticate(array $transportHeaders): AuthenticatedMcpContext
            {
                if ($this->failAuth || !isset($transportHeaders['authorization'])) {
                    throw new DomainFailure(Outcome::Denied, 'unauthenticated');
                }

                return $this->ctx;
            }
        };

        $foreignDirective = new DirectiveArtifact(
            tenantId: new TenantId(self::FOREIGN_TENANT_ID),
            directiveId: new Ulid(self::DIRECTIVE_ID),
            version: 1,
            title: 'Foreign Directive',
            contentFormat: 'compact_markdown',
            body: 'Foreign rules',
            bodyDigest: new Sha256(hash('sha256', 'Foreign rules')),
            lockedRules: new JsonObject(['refund.max_days' => 999]),
            promotedBy: new ActorId(self::ACTOR_ID),
            promotedAt: new UtcInstant('2026-11-27T10:00:00Z'),
            derivedFrom: [],
            approvalDigest: new Sha256(str_repeat('b', 64)),
        );

        $backend = $this->dummyBackend();

        return new StatelessMcpServer(
            $auth,
            CanonicalToolRegistry::withDefaultTools($backend, $policy),
            new ResourceTemplateHandler($backend, $policy),
            new DirectivePromptHandler([$foreignDirective]),
        );
    }

    private function dummyBackend(): McpBackend
    {
        return new class() implements McpBackend {
            public function navigateOutline(AuthenticatedMcpContext $context, string $revisionId, int $maxDepth, int $limit): array
            {
                return [];
            }

            public function searchLayered(AuthenticatedMcpContext $context, string $query, ?string $stackId, int $topK): array
            {
                return ['selectionToken' => str_repeat('a', 64), 'candidatesMeta' => []];
            }

            public function discloseEvidence(AuthenticatedMcpContext $context, string $selectionToken, ResolutionTier $tier, int $maxFragments): array
            {
                return ['fragments' => []];
            }

            public function readUnits(AuthenticatedMcpContext $context, string $revisionId, array $unitIds): array
            {
                return ['units' => []];
            }

            public function verifyGrounding(AuthenticatedMcpContext $context, string $selectionToken, string $responseText, array $citations): array
            {
                return ['grounded' => true];
            }

            public function listResources(AuthenticatedMcpContext $context): array
            {
                return [];
            }

            public function readOutlineResource(AuthenticatedMcpContext $context, string $kb, string $revision): array
            {
                return ['uri' => 'u', 'mimeType' => 'application/json', 'text' => '{}'];
            }

            public function readDigestResource(AuthenticatedMcpContext $context, string $kb, string $artifactId): array
            {
                return ['uri' => 'u', 'mimeType' => 'application/json', 'text' => '{}'];
            }

            public function readCitationResource(AuthenticatedMcpContext $context, string $kb, string $citationId): array
            {
                return ['uri' => 'u', 'mimeType' => 'application/json', 'text' => '{}'];
            }
        };
    }
}
