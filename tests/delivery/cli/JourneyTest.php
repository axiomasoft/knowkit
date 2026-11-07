<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Cli;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\ReferenceCache;
use KnowKitExample\Host;
use KnowKitExample\Runtime;
use KnowKitExample\Sandbox;
use KnowKitExample\Walkthrough;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/examples/plain-php/bootstrap.php';
require_once __DIR__ . '/TransportTest.php';

#[Group('delivery:cli')]
final class JourneyTest extends TestCase
{
    public function testStandaloneWalkthroughWithoutOptionalServices(): void
    {
        $result = TransportTest::process([PHP_BINARY, 'examples/plain-php/run.php'], ['PATH' => '/nonexistent']);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        $wire = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('success', $wire['outcome']);
        self::assertSame(hash_file('sha256', Walkthrough::fixture()), $wire['sourceDigest']);
        self::assertSame(Host::id('demo-version'), $wire['citation']['sourceVersionId']);
        self::assertSame($wire['sourceDigest'], $wire['citation']['sourceDigest']);
        self::assertNotEmpty($wire['citation']['spans']);
        self::assertSame('denied', $wire['afterRevoke']['outcome']);
        self::assertArrayNotHasKey('data', $wire['afterRevoke']);
        self::assertSame('not_selected', $wire['optionalHttp']);
        self::assertStringNotContainsString(sys_get_temp_dir() . '/', $result['stdout']);
    }

    public function testRepeatUploadIsIdempotentAndChangedRequestConflicts(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare();
            $before = $this->counts($runtime);
            $repeat = $w->call('source upload', Walkthrough::uploadInput(), 'upload', '0', '0', Walkthrough::fixture());
            self::assertSame(0, $repeat->exit);
            self::assertSame($before, $this->counts($runtime));
            $changed = $w->call('source upload', Walkthrough::uploadInput(), 'upload', '0', '1', Walkthrough::fixture());
            self::assertSame(5, $changed->exit);
            self::assertSame('idempotency_conflict', $changed->envelope->get('error')->get('code'));
            self::assertSame($before, $this->counts($runtime));
        });
    }

    public function testRevokeAfterActualCacheHitBlocksNewDisclosureAndCitationRead(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare();
            $cache = new TrackingCache($runtime->queries->cache);
            $runtime->queries->cache = $cache;
            $search = $w->require($w->call('search', ['query' => 'support']));
            $cached = $w->require($w->call('search', ['query' => 'support']));
            self::assertSame(1, $cache->hits);
            self::assertNotSame($search->get('token'), $cached->get('token'));
            self::assertSame(Jcs::canonicalize($search->get('selection')->get('candidateRefs')),
                Jcs::canonicalize($cached->get('selection')->get('candidateRefs')));
            self::assertStringNotContainsString('30 minutes', Jcs::canonicalize($cached));
            $request = Walkthrough::disclosureInput($search, 'cache-original');
            $evidence = $w->require($w->call('disclose', $request));
            $fragment = $evidence->get('fragments')[0];
            $citation = $fragment->get('citation');
            self::assertSame(hash_file('sha256', Walkthrough::fixture()), $citation->get('sourceDigest'));
            $before = $this->counts($runtime);
            $w->require($w->call('lifecycle revoke', ['sourceId' => Host::id('demo-source'),
                'reason' => 'source_revoked'], 'revoke', '0'));
            $denied = $w->call('disclose', Walkthrough::disclosureInput($cached, 'cache-new-attempt'));
            self::assertSame(3, $denied->exit);
            self::assertFalse($denied->envelope->has('data'));
            $read = $w->call('read source', ['citationId' => $citation->get('citationId'), 'attemptId' => Host::id('after-revoke-read')]);
            self::assertSame(3, $read->exit);
            self::assertFalse($read->envelope->has('data'));
            $replay = $w->require($w->call('disclose', $request));
            self::assertTrue($replay->get('replay'));
            self::assertStringNotContainsString('30 minutes', Jcs::canonicalize($replay));
            self::assertSame($before['evidence_usages'], $this->counts($runtime)['evidence_usages']);
        });
    }

    public function testDeniedAndEmptyStayDistinctAndCannotLeakText(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare();
            $empty = $w->call('search', ['query' => 'unmatchablezzzz']);
            self::assertSame(0, $empty->exit);
            self::assertSame('empty', $empty->envelope->get('outcome'));
            self::assertSame([], $empty->envelope->get('data'));
            $runtime->host->decision = D\Decision::Deny;
            $denied = $w->call('search', ['query' => 'support']);
            self::assertSame(3, $denied->exit);
            self::assertSame('denied', $denied->envelope->get('outcome'));
            self::assertFalse($denied->envelope->has('data'));
            self::assertStringNotContainsString('30 minutes', $denied->json());
            $upload = $w->call('source upload', Walkthrough::uploadInput(), 'denied-upload', '0', '1', Walkthrough::fixture());
            self::assertSame(3, $upload->exit);
        });
    }

    public function testWrongHostContextAndTamperedImmutableRefsDenyWithoutUsage(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare();
            $search = $w->require($w->call('search', ['query' => 'support']));
            $input = Walkthrough::disclosureInput($search, 'tampered');
            $input['chunkRefs'][0]['sourceVersionId'] = Host::id('foreign-version');
            $before = $this->counts($runtime)['evidence_usages'];
            $response = $w->call('disclose', $input);
            self::assertContains($response->exit, [3, 5]);
            self::assertFalse($response->envelope->has('data'));
            $input = Walkthrough::disclosureInput($search, 'foreign-token');
            $input['token'] = str_repeat('0', 64);
            self::assertSame(3, $w->call('disclose', $input)->exit);
            $scope = $runtime->host->scope();
            $foreign = new D\Scope(new D\TenantId(Host::id('foreign-tenant')), $scope->kbId,
                $scope->actorId, $scope->purpose, $scope->policyEpoch, $scope->hostScopeHash);
            $records = new \KnowKit\Adapter\Postgres\Selections\PdoSelections($runtime->host->db,
                new D\SelectionRecordCodec($runtime->host->validator));
            self::assertNull($records->get($foreign, (new D\SelectionToken($search->get('token')))->hash()));
            self::assertSame($before, $this->counts($runtime)['evidence_usages']);
        });
    }

    public function testLibraryAndCliResolveSameExactCitationAndLifecycle(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare();
            $search = $w->require($w->call('search', ['query' => 'support']));
            $evidence = $w->require($w->call('disclose', Walkthrough::disclosureInput($search, 'library-citation')));
            $citation = $evidence->get('fragments')[0]->get('citation');
            $id = new D\Ulid($citation->get('citationId'));
            $library = (new \KnowKit\Adapter\Postgres\Reading\PdoCitations($runtime->host->db))
                ->get($runtime->host->scope(), $id, $runtime->host->validator);
            self::assertSame(Jcs::canonicalize($citation), Jcs::canonicalize($library->citation->toWire()));
            $read = $w->require($w->call('read source', ['citationId' => $id->value, 'attemptId' => Host::id('library-read')]));
            self::assertSame(file_get_contents(Walkthrough::fixture()), base64_decode($read->get('contentBase64'), true));
            $runtime->lifecycle->revoke($runtime->host->scope(), new D\Revocation(new D\SourceId(Host::id('demo-source')),
                new D\Decimal('0')), new D\IdempotencyKey('library-revoke'));
            self::assertSame(3, $w->call('read source', ['citationId' => $id->value, 'attemptId' => Host::id('library-revoked-read')])->exit);
        });
    }

    public function testEmptyTextPublicationDoesNotRedirectHistoricalCitationToLatest(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare();
            $search = $w->require($w->call('search', ['query' => 'support']));
            $evidence = $w->require($w->call('disclose', Walkthrough::disclosureInput($search, 'historical')));
            $citation = $evidence->get('fragments')[0]->get('citation');
            $input = Walkthrough::uploadInput();
            $input['sourceVersionId'] = Host::id('empty-version');
            $empty = dirname(Walkthrough::fixture()) . '/empty.txt';
            $w->require($w->call('source upload', $input, 'empty-upload', '0', '1', $empty));
            $generation = ['generationId' => Host::id('empty-generation')];
            $w->require($w->call('ingest reprocess', $generation + ['sourceVersionId' => $input['sourceVersionId']], 'empty-job', '0'));
            $w->require($w->call('publication validate', $generation, 'empty-validate', '0'));
            $w->require($w->call('publication activate', $generation, 'empty-publish', '0', '1'));
            $result = $w->call('search', ['query' => 'support']);
            self::assertSame('empty', $result->envelope->get('outcome'));
            self::assertSame(0, $result->exit);
            self::assertSame([], $result->envelope->get('data'));
            $historical = $w->require($w->call('read source', ['citationId' => $citation->get('citationId'),
                'attemptId' => Host::id('historical-after-empty')]));
            self::assertSame(Host::id('demo-version'), $historical->get('citation')->get('sourceVersionId'));
            self::assertSame(file_get_contents(Walkthrough::fixture()), base64_decode($historical->get('contentBase64'), true));
        });
    }

    private function counts(Runtime $runtime): array
    {
        $counts = [];
        foreach (['source_versions', 'resource_ledger', 'evidence_usages'] as $table) {
            $counts[$table] = (int) $runtime->host->db->execute('SELECT count(*) FROM ' . $table . ' WHERE tenant_id=?',
                [$runtime->host->scope()->tenantId->value])->fetchColumn();
        }

        return $counts;
    }

    public function testCancelInvalidatesWorkerBeforePublicationAndStatusIsTyped(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare(false);
            $job = $runtime->host->db->row('SELECT id FROM jobs WHERE tenant_id=?', [$runtime->host->scope()->tenantId->value]);
            $status = $w->require($w->call('ingest status', ['jobId' => $job['id']]));
            self::assertSame('leased', $status->get('state'));
            self::assertSame(1, $status->get('completedStages'));
            self::assertSame(5, $w->call('ingest cancel', ['jobId' => $job['id']], 'wrong-cancel', '1')->exit);
            $cancelled = $w->require($w->call('ingest cancel', ['jobId' => $job['id']], 'cancel', '0'));
            self::assertSame('cancelled', $cancelled->get('state'));
            self::assertSame(0, $w->call('ingest cancel', ['jobId' => $job['id']], 'cancel', '0')->exit);
            self::assertSame(5, $w->call('publication activate', ['generationId' => Host::id('demo-generation')], 'late-publish', '0', '0')->exit);
            self::assertNull($runtime->catalog->getKb($runtime->host->scope()->tenantId, $runtime->host->scope()->kbId)->activeSnapshotId);
        });
    }

    public function testCorrectionLegalHoldDeleteAndPurgeKeepExactIdentity(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $w = new Walkthrough($runtime);
            $w->prepare();
            $search = $w->require($w->call('search', ['query' => 'support']));
            $evidence = $w->require($w->call('disclose', Walkthrough::disclosureInput($search, 'before-correction')));
            $citation = $evidence->get('fragments')[0]->get('citation');
            $source = ['sourceId' => Host::id('demo-source')];
            $w->require($w->call('lifecycle hold', $source + ['enabled' => true], 'hold', '0'));
            $version = $runtime->catalog->getSourceVersion($runtime->host->scope()->tenantId, new D\SourceVersionId(Host::id('demo-version')));
            $ref = json_decode(Jcs::canonicalize($version->ref->toWire()), true, 64, JSON_THROW_ON_ERROR);
            $w->require($w->call('lifecycle correct', ['oldVersion' => $ref], 'correct', '1'));
            self::assertSame(3, $w->call('read source', ['citationId' => $citation->get('citationId'), 'attemptId' => Host::id('corrected-read')])->exit);
            $deleted = $w->require($w->call('lifecycle delete', $source, 'delete', '2'));
            $purge = $w->require($w->call('purge status', ['taskId' => $deleted->get('taskId')]));
            self::assertSame('pending', $purge->get('state'));
            $task = $runtime->host->db->row('SELECT hold_reason FROM deletion_tasks WHERE tenant_id=? AND id=?',
                [$runtime->host->scope()->tenantId->value, $deleted->get('taskId')]);
            self::assertSame('legal_hold', $task['hold_reason']);
            self::assertSame(0, $w->call('lifecycle delete', $source, 'delete', '2')->exit);
        });
    }
}

final class TrackingCache implements ReferenceCache
{
    public int $hits = 0;

    public function __construct(
        private readonly ReferenceCache $inner,
    ) {}

    public function get(D\Scope $scope, D\ReferenceCacheKey $key): ?D\ReferenceCacheEntry
    {
        $entry = $this->inner->get($scope, $key);
        if ($entry !== null) {
            ++$this->hits;
        }

        return $entry;
    }

    public function put(D\Scope $scope, D\ReferenceCacheEntry $entry): void
    {
        $this->inner->put($scope, $entry);
    }

    public function invalidate(D\TenantId $tenant): void
    {
        $this->inner->invalidate($tenant);
    }
}
