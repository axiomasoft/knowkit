<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Cache;

use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Adapter\Postgres\Outbox\PdoEventAuthority;
use KnowKit\Adapter\Postgres\Outbox\PdoInbox;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Events\Consumer;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Retrieval\Cache\InvalidatingInbox;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as DisclosureFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';
require_once dirname(__DIR__) . '/disclosure/bootstrap.php';

#[Group('retrieval:cache')]
final class PostgresTest extends TestCase
{
    public function testSameQueryAcrossTwoTenantsUsesSeparateReferencesAndCacheEntries(): void
    {
        DisclosureFixture::run(function (DisclosureFixture $f): void {
            $cache = $this->wire($f);
            $scope = $f->host->identity->scope;
            $keys = [];
            foreach ([1 => 'allowed', 2 => 'foreign'] as $tenant => $name) {
                $values = get_object_vars($scope);
                $values['tenantId'] = new D\TenantId(PortFixture::id($tenant));
                $current = new D\Scope(...$values);
                $f->host->identity = new PolicyContext($current, 'teacher', ['source:read']);
                $snapshot = new D\SnapshotRef($current->tenantId, $current->kbId,
                    new D\SnapshotId(PortFixture::id($tenant === 1 ? 112 : 110)), new D\Fingerprint(str_repeat('b', 64)));
                $f->host->inventory = $f->catalog->load($current, $snapshot, new D\GenerationId(PortFixture::id(14)));
                $f->host->eligible = [$f->lexical->sets[$name]->chunkSet->revisionRef->sourceRef->sourceVersionId];
                for ($i = 0; $i < 2; ++$i) {
                    $selected = $f->host->retrieve($f->host->query(text: 'gravity'), $f->selections);
                    self::assertSame(D\Outcome::Success, $selected->result->outcome, $selected->result->errorCode ?? 'success');
                    foreach ($selected->result->data->candidateRefs() as $ref) {
                        self::assertEquals($current->tenantId, $ref->revisionRef->sourceRef->tenantId);
                    }
                }
                $keys[] = $cache->last->key->digest->value;
            }
            self::assertNotSame($keys[0], $keys[1]);
            self::assertSame(2, $cache->hits);
            $cache->invalidate(new D\TenantId(PortFixture::id(1)));
            self::assertNotNull($cache->memory->get($f->host->identity->scope, $cache->last->key));
        });
    }

    public function testRevokeOnCacheHitAndHistoricalReplayNeverAuthorizeFreshBytes(): void
    {
        DisclosureFixture::run(function (DisclosureFixture $f): void {
            $cache = $this->wire($f);
            $first = $f->select();
            $request = $f->request($first);
            $original = $f->disclose($first, $request);
            self::assertSame(D\Outcome::Success, $original->result->outcome);
            $history = $original->result->data;
            self::assertInstanceOf(D\EvidenceSet::class, $history);
            $digest = $history->digest();
            $canonical = $history->canonical();
            $f->revoke();
            // The cache intentionally receives no invalidation; stale owner query grant still permits refs.
            $hit = $f->select();
            self::assertSame(1, $cache->hits);
            self::assertNull($f->disclose($hit)->result->data);
            self::assertSame(D\Outcome::Denied, $f->disclose($hit)->result->outcome);
            self::assertSame(D\Outcome::Denied, $f->disclose($first)->result->outcome);
            $replay = $f->disclose($first, $request);
            self::assertInstanceOf(D\DisclosureReplay::class, $replay->result->data);
            self::assertStringNotContainsString($f->lexical->data['exact']['text'], json_encode($replay->result->data, JSON_THROW_ON_ERROR));
            self::assertSame(['usageRef', 'disclosureAttemptId', 'checkedAt', 'evidenceDigest'], array_keys(get_object_vars($replay->result->data)));
            self::assertEquals($digest, $history->digest());
            self::assertSame($canonical, $history->canonical());
            self::assertSame(1, (int) $f->db()->execute('SELECT count(*) FROM ' . $f->db()->table('evidence_usages'))->fetchColumn());
        });
    }

    public function testCacheHitLoadsExactCurrentSnapshotMembershipBeforeUsingRanks(): void
    {
        DisclosureFixture::run(function (DisclosureFixture $f): void {
            $cache = $this->wire($f);
            $f->select();
            // The lexical fixture's sealed snapshot 110 deliberately omits one manifest member.
            // Prime a matching cache entry without mutating an immutable publication snapshot.
            $complete = $f->host->inventory->snapshot;
            $incomplete = new D\SnapshotRef($complete->tenantId, $complete->kbId,
                new D\SnapshotId(PortFixture::id(110)), $complete->manifestDigest);
            $key = $cache->key($f->query(), $incomplete);
            $cache->memory->put($f->host->identity->scope, new D\ReferenceCacheEntry(
                $key, $cache->last->candidates, $cache->last->createdAt, $cache->last->expiresAt,
            ));
            self::assertNotNull($cache->memory->get($f->host->identity->scope, $key));
            $result = $f->selections->retrieve($f->host->context(), $f->query(), $incomplete,
                $f->host->inventory->generationId(), $f->host->eligible, $f->host->binding, 'run-1');
            self::assertSame(D\Outcome::Incompatible, $result->result->outcome);
            self::assertSame('snapshot_membership_mismatch', $result->result->errorCode);
            self::assertNull($result->token);
            self::assertSame(0, $cache->hits);
        });
    }

    public function testPolicyBumpAfterCacheHitBlocksFreshDisclosure(): void
    {
        DisclosureFixture::run(function (DisclosureFixture $f): void {
            $cache = $this->wire($f);
            $f->select();
            $hit = $f->select();
            self::assertSame(1, $cache->hits);
            $f->db()->execute('UPDATE ' . $f->db()->table('knowledge_bases')
                . ' SET kb_policy_epoch=kb_policy_epoch+1 WHERE tenant_id=? AND id=?',
                [PortFixture::id(1), PortFixture::id(100)]);
            $result = $f->disclose($hit);
            self::assertSame(D\Outcome::Denied, $result->result->outcome);
            self::assertNull($result->result->data);
        });
    }

    public function testDurableOutboxConsumerAndDuplicateDeliveryOnlyEvict(): void
    {
        DisclosureFixture::run(function (DisclosureFixture $f): void {
            $cache = $this->wire($f);
            $f->select();
            $f->revoke();
            $tenant = $f->host->identity->scope->tenantId;
            $source = $f->ref()->revisionRef->sourceRef->sourceId;
            $event = (new DomainEventFactory($f->host, $f->host, PortFixture::validator()))->create(
                $tenant, 'source', $source, new D\Decimal('1'), 'source.revoked', new JsonObject([
                    'sourceId' => $source->value,
                    'reason' => 'source_revoked',
                    'lifecycleEpoch' => '1',
                    'appliesTo' => 'future_versions',
                ]),
            );
            $inbox = new PdoInbox($f->db(), $f->lexical->catalog->tx, $tenant, 'reference-cache', $f->host);
            $consumer = new Consumer($tenant, PortFixture::validator(), new InvalidatingInbox($tenant, $inbox, $cache),
                new PdoEventAuthority($f->db(), $tenant));
            self::assertSame('applied', $consumer->consume($event->canonical()));
            self::assertNull($cache->memory->get($f->host->identity->scope, $cache->last->key));
            $hit = $f->select();
            self::assertSame('duplicate', $consumer->consume($event->canonical()));
            self::assertNull($cache->memory->get($f->host->identity->scope, $cache->last->key));
            self::assertSame(D\Outcome::Denied, $f->disclose($hit)->result->outcome);
        });
    }

    private function wire(DisclosureFixture $f): Fixture
    {
        $cache = new Fixture();
        $cache->host = $f->host;
        $cache->memory = new InMemoryReferenceCache($f->host);
        $f->selections = new Selections($f->checks(), $f->lexical->index, $f->catalog,
            new PdoSelections($f->db(), new D\SelectionRecordCodec(PortFixture::validator())),
            new BoundedSelector(), $f->host, $f->host, PortFixture::validator(), $cache);
        $canonical = new CanonicalBuffer($f->catalog,
            new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()), $f->host);
        $f->service = new Disclosures($f->selections, $f->checks(), $canonical,
            $f->usages, $f->host, PortFixture::validator());

        return $cache;
    }
}
