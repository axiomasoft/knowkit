<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Cache;

use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Retrieval\Cache\InvalidatingInbox;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\EventInbox;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('retrieval:cache')]
final class CacheTest extends TestCase
{
    public function testHitsRecheckQueryGrantAndIssueNewReferenceOnlySelection(): void
    {
        $f = new Fixture();
        $first = $f->retrieve();
        $second = $f->retrieve();
        self::assertSame(D\Outcome::Success, $second->result->outcome);
        self::assertSame(1, $f->host->searchCalls);
        self::assertSame(2, $f->host->queryChecks);
        self::assertSame(1, $f->hits);
        self::assertNotEquals($first->token, $second->token);
        self::assertNotEquals($first->result->data->id(), $second->result->data->id());
        self::assertEquals($first->result->data->candidateRefs(), $second->result->data->candidateRefs());
        self::assertSame($f->host->identity->scope->hostScopeHash, $f->last->key->hostScopeHash);
        $serialized = serialize($f->last);
        self::assertStringNotContainsString($f->host->inventory->manifest->sets[0]->chunkSet->chunks[0]->text(), $serialized);
        self::assertStringNotContainsString('gravity', $serialized);
        self::assertStringNotContainsString('QueryGrant', $serialized);
        self::assertStringNotContainsString('DecisionReceipt', $serialized);
        self::assertNull($f->last->candidates[0]->score);
    }

    public function testFullScopePolicyPartitionsKeys(): void
    {
        $f = new Fixture();
        $base = $f->key()->digest;
        $identity = $f->host->identity;
        $scope = $identity->scope;
        foreach (['actorId' => new D\ActorId(PortFixture::id(102)), 'purpose' => 'lesson.audit',
            'hostScopeHash' => new D\Sha256(str_repeat('b', 64)), 'policyEpoch' => new D\Decimal('1'),
            'kbId' => new D\KbId(PortFixture::id(103)), 'tenantId' => new D\TenantId(PortFixture::id(2))] as $name => $value) {
            $values = get_object_vars($scope);
            $values[$name] = $value;
            $changed = new D\Scope(...$values);
            $f->host->identity = new PolicyContext($changed, $identity->audience, $identity->grants);
            $snapshot = new D\SnapshotRef($changed->tenantId, $changed->kbId,
                $f->host->inventory->snapshot->snapshotId, $f->host->inventory->snapshot->manifestDigest);
            self::assertNotEquals($base, $f->key(snapshot: $snapshot)->digest, $name);
        }
        foreach ([new PolicyContext($scope, 'student', $identity->grants),
            new PolicyContext($scope, $identity->audience, ['source:limited']),
            new PolicyContext($scope, $identity->audience, $identity->grants, ['provider:allowed'])] as $policy) {
            $f->host->identity = $policy;
            self::assertNotEquals($base, $f->key()->digest);
        }
    }

    public function testQueryNormalizationProfileSpaceAndPolicyRevisionsPartitionKeys(): void
    {
        $f = new Fixture();
        $base = $f->key()->digest;
        $query = $f->host->query();
        foreach ([new D\QueryBinding($query->original, $query->normalized, 'norm-2', $query->profile, $query->limits),
            new D\QueryBinding($query->original, $query->normalized, $query->normalizationRevision,
                new D\EffectiveProfile('selection-test', '2', []), $query->limits),
            new D\QueryBinding($query->original, $query->normalized, $query->normalizationRevision,
                $query->profile, $query->limits, new D\Fingerprint(str_repeat('d', 64))),
            $f->host->query(D\SelectionLimits::profile('document')),
            $f->host->query(text: 'gravity changed')] as $changedQuery) {
            self::assertNotEquals($base, $f->key($changedQuery)->digest);
        }
        $f->host->binding = $f->host->binding->with('kbPolicyEpoch', '1');
        self::assertNotEquals($base, $f->key()->digest);
    }

    public function testNewSnapshotAndIndexGenerationNeverReuseOldRanks(): void
    {
        $f = new Fixture();
        $base = $f->key();
        $snapshot = $f->host->inventory->snapshot;
        foreach ([new D\SnapshotRef($snapshot->tenantId, $snapshot->kbId,
            new D\SnapshotId(PortFixture::id(111)), $snapshot->manifestDigest),
            new D\SnapshotRef($snapshot->tenantId, $snapshot->kbId, $snapshot->snapshotId,
                new D\Fingerprint(str_repeat('f', 64)))] as $changed) {
            self::assertNotEquals($base->digest, $f->key(snapshot: $changed)->digest);
        }
        $f->retrieve();
        $generationKey = $f->key(generation: new D\GenerationId(PortFixture::id(15)));
        self::assertNotEquals($base->digest, $generationKey->digest);
        self::assertNull($f->memory->get($f->host->identity->scope, $generationKey));
        $inventory = $f->host->inventory;
        $manifest = new D\ManifestCandidate(new D\IndexManifest(
            $inventory->manifest->manifest->toWire()->with('generationId', PortFixture::id(15)), PortFixture::validator()),
            $inventory->manifest->sets, $inventory->manifest->resources);
        $f->host->inventory = (new CanonicalUnits())->build(
            new D\SnapshotRef($snapshot->tenantId, $snapshot->kbId, new D\SnapshotId(PortFixture::id(111)),
                new D\Fingerprint(str_repeat('f', 64))), $manifest, $f->host->documents);
        self::assertSame(D\Outcome::Success, $f->retrieve()->result->outcome);
        self::assertSame(2, $f->host->searchCalls);
        self::assertSame(0, $f->hits);
    }

    public function testAudiencePurposeAndPolicyChangesMissEvenWithStaleInvalidation(): void
    {
        $f = new Fixture();
        $f->retrieve();
        $scope = $f->host->identity->scope;
        $f->host->identity = new PolicyContext($scope, 'student', ['source:read']);
        self::assertSame(D\Outcome::Success, $f->retrieve()->result->outcome);
        $values = get_object_vars($scope);
        $values['purpose'] = 'lesson.audit';
        $f->host->identity = new PolicyContext(new D\Scope(...$values), 'student', ['source:read']);
        self::assertSame(D\Outcome::Success, $f->retrieve()->result->outcome);
        $f->host->binding = $f->host->binding->with('kbPolicyEpoch', '1');
        $values['policyEpoch'] = new D\Decimal('1');
        $f->host->identity = new PolicyContext(new D\Scope(...$values), 'student', ['source:read']);
        self::assertSame(D\Outcome::Success, $f->retrieve()->result->outcome);
        self::assertSame(4, $f->host->searchCalls);
        self::assertSame(0, $f->hits);
    }

    public function testFreshQueryDenialUnknownAndUnavailableCannotBeHiddenByCache(): void
    {
        $f = new Fixture();
        $f->retrieve();
        foreach ([D\Outcome::Denied, D\Outcome::Unknown, D\Outcome::Unavailable, D\Outcome::Incompatible] as $outcome) {
            $f->host->failure = new D\DomainFailure($outcome, 'policy_test');
            $result = $f->retrieve();
            self::assertSame($outcome, $result->result->outcome);
            self::assertNull($result->result->data);
            self::assertNull($result->token);
        }
        self::assertSame(1, $f->host->searchCalls);
        self::assertSame(0, $f->hits);
    }

    public function testHostExclusionsAndPoisonedBindingsCannotExpandUniverse(): void
    {
        $f = new Fixture();
        $f->retrieve();
        $old = $f->last;
        $f->host->eligible = [];
        $f->host->candidates = [];
        self::assertSame(D\Outcome::Empty, $f->retrieve()->result->outcome);
        self::assertSame(2, $f->host->searchCalls);
        $f->onGet = static fn () => $old;
        self::assertSame(D\Outcome::Denied, $f->retrieve()->result->outcome);
    }

    public function testPoisonedRefsAndImmutableIdentityFailOnHitBeforeIssuingToken(): void
    {
        foreach (['digest', 'foreign-version', 'foreign-key'] as $fault) {
            $f = new Fixture();
            $f->retrieve();
            $ref = $f->last->candidates[0]->ref;
            if ($fault === 'foreign-version') {
                $source = $ref->revisionRef->sourceRef;
                $revision = new D\DocumentRevisionRef(new D\SourceVersionRef($source->tenantId, $source->sourceId,
                    new D\SourceVersionId(PortFixture::id(999)), $source->sourceDigest),
                    $ref->revisionRef->documentRevisionId, $ref->revisionRef->irDigest, $ref->revisionRef->parserSignature);
            }
            $badRef = new D\ChunkRef($revision ?? $ref->revisionRef, $ref->chunkSetId, $ref->chunkId,
                new D\Sha256(str_repeat('e', 64)));
            $key = $fault === 'foreign-key' ? $f->key(topK: 1) : $f->last->key;
            $bad = new D\ReferenceCacheEntry($key, [new D\Candidate($badRef, 1)],
                $f->last->createdAt, $f->last->expiresAt);
            $f->onGet = static fn () => $bad;
            $result = $f->retrieve();
            self::assertContains($result->result->outcome, [D\Outcome::Denied, D\Outcome::Incompatible]);
            self::assertNull($result->token);
            self::assertNull($result->result->data);
            unset($revision);
        }
    }

    public function testFailedProducerCancelledWaiterAndExpiryCannotPopulateOrRenewCache(): void
    {
        $f = new Fixture();
        $original = $f->host->identity;
        $f->host->duringSearch = static function () use ($f): void {
            $f->host->identity = new PolicyContext($f->host->identity->scope, 'student');
        };
        self::assertSame(D\Outcome::Denied, $f->retrieve()->result->outcome);
        self::assertSame(0, $f->puts);
        $f->host->identity = $original;
        $f->host->duringSearch = static function (): void {
            throw new \RuntimeException('failed producer');
        };
        self::assertSame(D\Outcome::Unavailable, $f->retrieve()->result->outcome);
        self::assertSame(0, $f->puts);
        $f->host->duringSearch = null;
        $f->retrieve();
        $expires = $f->last->expiresAt;
        $f->host->now = $f->host->now->plusSeconds(29);
        $f->retrieve();
        self::assertEquals($expires, $f->last->expiresAt);
        $f->host->now = $expires;
        $f->retrieve();
        self::assertSame(4, $f->host->searchCalls);
        self::assertSame(1, $f->hits);
    }

    public function testSlowProducerCannotInstallExpiredEntry(): void
    {
        $f = new Fixture();
        $f->host->duringSearch = static function () use ($f): void {
            $f->host->now = $f->host->now->plusSeconds(30);
        };
        self::assertSame(D\Outcome::Success, $f->retrieve()->result->outcome);
        self::assertSame(0, $f->puts);
    }

    public function testCancelledCacheWaiterCannotIssueTokenOrPublishCachedAuthorization(): void
    {
        $f = new Fixture();
        $f->retrieve();
        $f->onGet = static function ($scope, $key, $entry) use ($f) {
            $f->host->identity = new PolicyContext($f->host->identity->scope, 'student');

            return $entry;
        };
        $result = $f->retrieve();
        self::assertSame(D\Outcome::Denied, $result->result->outcome);
        self::assertNull($result->token);
        self::assertNull($result->result->data);
        self::assertSame(1, $f->hits);
        self::assertSame(1, $f->puts);
    }

    public function testMalformedLifetimeAndExpiredUntrustedCacheAreRejected(): void
    {
        $f = new Fixture();
        $f->retrieve();
        $entry = $f->last;
        foreach ([0, 301] as $ttl) {
            try {
                new D\ReferenceCacheEntry($entry->key, $entry->candidates,
                    $entry->createdAt, $entry->createdAt->plusSeconds($ttl));
                self::fail('Invalid cache lifetime accepted');
            } catch (D\DomainFailure $failure) {
                self::assertSame('cache_lifetime_invalid', $failure->errorCode);
            }
        }
        $f->host->now = $entry->expiresAt;
        $f->onGet = static fn () => $entry;
        self::assertSame(D\Outcome::Denied, $f->retrieve()->result->outcome);
        self::assertSame(1, $f->host->searchCalls);
        $f->host->now = $entry->createdAt->plusSeconds(-1);
        self::assertSame(D\Outcome::Denied, $f->retrieve()->result->outcome);
    }

    public function testBoundedCacheEvictionAndTenantBoundary(): void
    {
        $f = new Fixture();
        $f->memory = new InMemoryReferenceCache($f->host, 1);
        $f->retrieve();
        $first = $f->last;
        $f->retrieve($f->host->query(text: 'other'));
        self::assertNull($f->memory->get($f->host->identity->scope, $first->key));
        $values = get_object_vars($f->host->identity->scope);
        $values['tenantId'] = new D\TenantId(PortFixture::id(2));
        $this->expectException(D\DomainFailure::class);
        $f->memory->put(new D\Scope(...$values), $first);
    }

    public function testOutboxDuplicateGapAndDelayedInvalidationOnlyEvict(): void
    {
        $f = new Fixture();
        $inbox = new class implements EventInbox {
            public string $result = 'applied';

            public function apply(D\DomainEvent $event): string
            {
                return $this->result;
            }

            public function quarantine(D\Sha256 $envelopeDigest): void {}

            public function reconcile(D\DomainEvent $event, D\Decimal $authoritativeVersion): void {}
        };
        $factory = new DomainEventFactory($f->host, $f->host, PortFixture::validator());
        $event = $factory->create($f->host->identity->scope->tenantId, 'source', new D\Ulid(PortFixture::id(2)),
            new D\Decimal('1'), 'source.revoked', new JsonObject([
                'sourceId' => PortFixture::id(2),
                'reason' => 'source_revoked',
                'lifecycleEpoch' => '1',
                'appliesTo' => 'future_versions',
            ]));
        $consumer = new InvalidatingInbox($event->tenantId(), $inbox, $f);
        foreach (['applied', 'duplicate', 'gap', 'blocked'] as $result) {
            $f->retrieve();
            $inbox->result = $result;
            self::assertSame($result, $consumer->apply($event));
            self::assertNull($f->memory->get($f->host->identity->scope, $f->last->key));
        }
        $f->retrieve();
        $consumer->reconcile($event, new D\Decimal('3'));
        self::assertNull($f->memory->get($f->host->identity->scope, $f->last->key));
        $foreign = new InvalidatingInbox(new D\TenantId(PortFixture::id(2)), $inbox, $f);
        $this->expectException(D\DomainFailure::class);
        $foreign->apply($event);
    }
}
