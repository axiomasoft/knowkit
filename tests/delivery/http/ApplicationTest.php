<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Http;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Retrieval\Disclosure\FreshDisclosure;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Http\Server as H;
use KnowKit\Tests\Retrieval\Reading\Fixture as ReadingFixture;
use KnowKitExample\Runtime;
use KnowKitExample\Sandbox;
use KnowKitExample\Walkthrough;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';
require_once dirname(__DIR__, 3) . '/examples/plain-php/bootstrap.php';
require_once dirname(__DIR__, 2) . '/retrieval/reading/bootstrap.php';
require_once __DIR__ . '/SelectionBindings.php';

#[Group('delivery:http')]
final class ApplicationTest extends TestCase
{
    public function testCatalogAndLifecycleRoutesCallAtomicCoreServices(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $http = new Fixture();
            $http->host = $runtime->host;
            $app = new H\CoreCommands(
                remaining: $http, catalog: $runtime->catalogCommands, sources: $runtime->sources, lifecycle: $runtime->lifecycle,
            );
            $server = $http->server(application: $app, clock: $runtime->host, ids: $runtime->host);
            $kb = $runtime->host->scope()->kbId->value;
            $source = Fixture::id(600);
            $steps = [
                ['/v1/knowledge-bases', ['name' => 'HTTP catalog', 'policyRef' => 'host-policy:1', 'defaultRetrievalProfile' => 'postgres-fts-simple-v1'], 'kb', null, 201],
                ['/v1/knowledge-bases/' . $kb . '/collections', ['collectionId' => Fixture::id(601)], 'collection', '0', 201],
                ['/v1/sources', ['sourceId' => $source, 'ownerRef' => 'host:owner', 'origin' => 'upload:fixture'], 'source', null, 201],
                ['/v1/memberships', ['sourceId' => $source, 'collectionId' => Fixture::id(601)], 'member', '1', 201],
                ['/v1/lifecycle/legal-holds', ['sourceId' => $source, 'enabled' => true], 'hold', '0', 200],
                ['/v1/lifecycle/legal-holds', ['sourceId' => $source, 'enabled' => false], 'unhold', '1', 200],
                ['/v1/lifecycle/revocations', ['sourceId' => $source, 'reason' => 'source_revoked'], 'revoke', '2', 200],
                ['/v1/lifecycle/deletions', ['sourceId' => $source], 'delete', '3', 202],
            ];
            foreach ($steps as [$path, $body, $key, $epoch, $status]) {
                $headers = ['Idempotency-Key' => 'http-' . $key];
                if ($epoch !== null) {
                    $headers['Expected-Epoch'] = $epoch;
                }
                $response = $server->handle(Fixture::request($path, $body, $headers));
                self::assertSame($status, $response->status, $path . ': ' . $response->body);
                self::assertSame('success', Fixture::wire($response)['outcome']);
                self::assertFalse($runtime->host->db->pdo->inTransaction());
            }
            self::assertInstanceOf(D\Ulid::class, new D\Ulid(Fixture::wire($response)['data']['taskId']));
            self::assertSame([], $http->seen, 'Core operations must not fall through to another implementation');
        });
    }

    public function testCorrectionRoutePreservesExactVersionIdentity(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            (new Walkthrough($runtime))->prepare();
            $http = new Fixture();
            $http->host = $runtime->host;
            $app = new H\CoreCommands(
                remaining: $http, catalog: $runtime->catalogCommands, sources: $runtime->sources, lifecycle: $runtime->lifecycle,
            );
            $server = $http->server(application: $app, clock: $runtime->host, ids: $runtime->host);
            $body = ['oldVersion' => [
                'tenantId' => $runtime->host->scope()->tenantId->value,
                'sourceId' => \KnowKitExample\Host::id('demo-source'),
                'sourceVersionId' => \KnowKitExample\Host::id('demo-version'),
                'sourceDigest' => hash_file('sha256', Walkthrough::fixture()),
            ]];
            $response = $server->handle(Fixture::request('/v1/lifecycle/corrections', $body,
                ['Idempotency-Key' => 'http-correction', 'Expected-Epoch' => '0']));
            self::assertSame(200, $response->status, $response->body);
            $state = $runtime->host->db->row('SELECT usability FROM ' . $runtime->host->db->table('source_version_state') . ' WHERE tenant_id=? AND id=?',
                [$body['oldVersion']['tenantId'], $body['oldVersion']['sourceVersionId']]);
            self::assertSame('corrected', $state['usability']);
            self::assertSame([], $http->seen);
        });
    }

    public function testDurableIdempotencyReplayRejectsPayloadAndExpectedEpochConflict(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            (new Walkthrough($runtime))->prepare();
            $http = new Fixture();
            $http->identity = $runtime->host->current();
            $http->host = $runtime->host;
            $app = new H\CoreCommands(
                remaining: $http, catalog: $runtime->catalogCommands, sources: $runtime->sources, lifecycle: $runtime->lifecycle,
            );
            $server = $http->server(application: $app, clock: $runtime->host, ids: $runtime->host);
            $path = '/v1/knowledge-bases/' . $http->identity->scope->kbId->value . '/collections';
            $body = ['collectionId' => Fixture::id(400)];
            $headers = ['Idempotency-Key' => 'http-create-collection', 'Expected-Epoch' => $http->identity->scope->policyEpoch->value];
            $created = $server->handle(Fixture::request($path, $body, $headers));
            self::assertSame(201, $created->status, $created->body);
            $count = $runtime->host->db->execute('SELECT count(*) FROM ' . $runtime->host->db->table('idempotency_receipts'))->fetchColumn();
            $replayed = $server->handle(Fixture::request($path, $body, $headers));
            self::assertSame(201, $replayed->status, $replayed->body);
            self::assertSame($created->body, $replayed->body);
            self::assertSame($count, $runtime->host->db->execute('SELECT count(*) FROM ' . $runtime->host->db->table('idempotency_receipts'))->fetchColumn());
            $conflict = $server->handle(Fixture::request($path, ['collectionId' => Fixture::id(401)], $headers));
            self::assertSame(409, $conflict->status, $conflict->body);
            self::assertSame('idempotency_conflict', Fixture::wire($conflict)['error']['code']);
            $changedEpoch = $headers;
            $changedEpoch['Expected-Epoch'] = '999';
            self::assertSame(409, $server->handle(Fixture::request($path, $body, $changedEpoch))->status);
            $stale = $headers;
            $stale['Idempotency-Key'] = 'http-stale-epoch';
            $stale['Expected-Epoch'] = '999';
            $response = $server->handle(Fixture::request($path, ['collectionId' => Fixture::id(402)], $stale));
            self::assertSame(409, $response->status, $response->body);
            self::assertSame('POLICY_CHANGED', Fixture::wire($response)['error']['code']);
            self::assertArrayNotHasKey('data', Fixture::wire($response));
            self::assertSame($count, $runtime->host->db->execute('SELECT count(*) FROM ' . $runtime->host->db->table('idempotency_receipts'))->fetchColumn());
        });
    }

    public function testHttpDisclosureAndCitationSendOnlyAfterCommittedUsageAndReplayHasNoText(): void
    {
        ReadingFixture::run(function (ReadingFixture $f): void {
            [$http, $server] = $this->server($f);
            $before = $f->upstream->lexical->catalog->count('evidence_usages');
            $search = $server->handle(Fixture::request('/v1/search', ['query' => 'DOC-2026/0042']));
            self::assertSame(200, $search->status, $search->body);
            $selection = Fixture::wire($search)['data']['selection'];
            $input = ['selectionId' => $selection['selectionId'], 'chunkRefs' => [$selection['candidateRefs'][0]], 'disclosureAttemptId' => $http->next()->value];
            $disclosed = $server->handle(Fixture::request('/v1/disclosures', $input));
            self::assertSame(200, $disclosed->status, $disclosed->body);
            $data = Fixture::wire($disclosed)['data'];
            self::assertArrayHasKey('usageId', $data);
            self::assertSame($before + 1, $f->upstream->lexical->catalog->count('evidence_usages'));
            self::assertFalse($f->upstream->db()->pdo->inTransaction());
            $usage = $f->upstream->db()->row('SELECT id FROM ' . $f->upstream->db()->table('evidence_usages') . ' WHERE tenant_id=? AND id=?',
                [$http->identity->scope->tenantId->value, $data['usageId']]);
            self::assertSame($data['usageId'], $usage['id']);
            $wireOnly = new D\EvidenceSet(
                value: \KnowKit\Domain\Contract\StrictJson::decode(json_encode($data, JSON_THROW_ON_ERROR)),
                validator: Fixture::validator(),
            );
            try {
                H\CoreReads::committed(D\Result::success($wireOnly));
                self::fail('Evidence without Usage binding accepted');
            } catch (\LogicException $failure) {
                self::assertSame('Usage-backed evidence required', $failure->getMessage());
            }
            $citation = $data['fragments'][0]['citation'];
            self::assertSame($selection['candidateRefs'][0]['sourceVersionId'], $citation['sourceVersionId']);
            self::assertSame($selection['candidateRefs'][0]['irDigest'], $citation['irDigest']);
            $replay = $server->handle(Fixture::request('/v1/disclosures', $input));
            self::assertSame(200, $replay->status, $replay->body);
            self::assertTrue(Fixture::wire($replay)['data']['replay']);
            self::assertArrayNotHasKey('fragments', Fixture::wire($replay)['data']);
            self::assertSame($before + 1, $f->upstream->lexical->catalog->count('evidence_usages'));
            $resolved = $server->handle(Fixture::request('/v1/citations/' . $citation['citationId'], method: 'GET'));
            self::assertSame(200, $resolved->status, $resolved->body);
            self::assertSame($citation, Fixture::wire($resolved)['data']['citation']);
            self::assertSame($before + 2, $f->upstream->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testRevokedPreviewsAndUnknownPolicyNeverDiscloseOrRegisterUsage(): void
    {
        ReadingFixture::run(function (ReadingFixture $f): void {
            [$http, $server] = $this->server($f);
            $f->upstream->host->decision = D\Decision::Unknown;
            $request = ['citationId' => $f->citation->id()->value, 'attemptId' => $http->next()->value, 'pageId' => 'p1'];
            $before = $f->upstream->lexical->catalog->count('evidence_usages');
            $unknown = $server->handle(Fixture::request('/v1/reads/page', $request));
            self::assertSame(503, $unknown->status, $unknown->body);
            self::assertSame('unknown', Fixture::wire($unknown)['outcome']);
            self::assertArrayNotHasKey('data', Fixture::wire($unknown));
            self::assertSame($before, $f->upstream->lexical->catalog->count('evidence_usages'));
            $f->upstream->host->decision = D\Decision::Allow;
            $f->upstream->objects->beforeOpen = fn () => $f->upstream->revoke();
            $request['attemptId'] = $http->next()->value;
            $revoked = $server->handle(Fixture::request('/v1/reads/page', $request));
            self::assertSame(403, $revoked->status, $revoked->body);
            self::assertSame('denied', Fixture::wire($revoked)['outcome']);
            self::assertArrayNotHasKey('data', Fixture::wire($revoked));
            self::assertSame($before, $f->upstream->lexical->catalog->count('evidence_usages'));
            foreach (['source', 'page', 'region', 'table', 'asset'] as $kind) {
                $body = ['citationId' => $f->citation->id()->value, 'attemptId' => $http->next()->value];
                $body += match ($kind) {
                    'page' => ['pageId' => 'p1'],
                    'region' => ['pageId' => 'p1', 'bbox' => ['x0' => 0, 'y0' => 0, 'x1' => 500000, 'y1' => 500000]],
                    'table' => ['tableId' => 'tb'],
                    'asset' => ['assetId' => 'a1'],
                    default => [],
                };
                $denied = $server->handle(Fixture::request('/v1/reads/' . $kind, $body));
                self::assertSame(403, $denied->status, $denied->body);
                self::assertArrayNotHasKey('data', Fixture::wire($denied));
            }
            self::assertSame($before, $f->upstream->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testForeignExactRefsAndChangedHostScopeCannotEnlargeSelection(): void
    {
        ReadingFixture::run(function (ReadingFixture $f): void {
            [$http, $server] = $this->server($f);
            $search = Fixture::wire($server->handle(Fixture::request('/v1/search', ['query' => 'DOC-2026/0042'])));
            $selection = $search['data']['selection'];
            $body = ['selectionId' => $selection['selectionId'], 'chunkRefs' => [$selection['candidateRefs'][0]], 'disclosureAttemptId' => $http->next()->value];
            $body['chunkRefs'][0]['tenantId'] = Fixture::id(999);
            $before = $f->upstream->lexical->catalog->count('evidence_usages');
            self::assertSame(403, $server->handle(Fixture::request('/v1/disclosures', $body))->status);
            $body['chunkRefs'] = [$selection['candidateRefs'][0]];
            $scope = $http->identity->scope;
            $http->identity = new D\Access\PolicyContext(new D\Scope(
                tenantId: $scope->tenantId, kbId: $scope->kbId, actorId: $scope->actorId,
                purpose: $scope->purpose, policyEpoch: $scope->policyEpoch, hostScopeHash: new D\Sha256(str_repeat('f', 64)),
            ), $http->identity->audience, $http->identity->grants);
            self::assertSame(403, $server->handle(Fixture::request('/v1/disclosures', $body))->status);
            self::assertSame($before, $f->upstream->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testUsageWriteFailureNeverReleasesPreviewBytes(): void
    {
        ReadingFixture::run(function (ReadingFixture $f): void {
            [$http, $server] = $this->server($f);
            $db = $f->upstream->db();
            $role = $db->execute('SELECT current_user')->fetchColumn();
            $before = $f->upstream->lexical->catalog->count('evidence_usages');
            $db->pdo->exec('RESET ROLE');
            $db->pdo->exec('REVOKE INSERT ON ' . $db->table('evidence_usages') . ' FROM "' . $role . '"');
            $db->pdo->exec('SET ROLE "' . $role . '"');
            $response = $server->handle(Fixture::request('/v1/reads/page', [
                'citationId' => $f->citation->id()->value, 'attemptId' => $http->next()->value, 'pageId' => 'p1',
            ]));
            self::assertSame(503, $response->status, $response->body);
            self::assertSame('unavailable', Fixture::wire($response)['outcome']);
            self::assertArrayNotHasKey('data', Fixture::wire($response));
            self::assertStringNotContainsString('contentBase64', $response->body);
            self::assertSame($before, $f->upstream->lexical->catalog->count('evidence_usages'));
            self::assertFalse($db->pdo->inTransaction());
        });
    }

    private function server(ReadingFixture $f): array
    {
        $http = new Fixture();
        $http->identity = $f->upstream->host->identity;
        $bindings = new SelectionBindings($f->upstream);
        $disclosure = new FreshDisclosure(disclosures: $f->upstream->service, store: $f->upstream->usages, host: $bindings);
        $application = new H\CoreReads(commands: $http, retrieval: $bindings, disclosure: $disclosure, readings: $f->service);

        return [$http, $http->server(application: $application, clock: $f->upstream->host, ids: $f->upstream->host)];
    }
}
