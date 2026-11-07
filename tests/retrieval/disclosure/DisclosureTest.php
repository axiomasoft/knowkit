<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Lifecycle\Restore\ProjectionTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:disclosure')]
final class DisclosureTest extends TestCase
{
    public function testFreshCanonicalDisclosureCommitsExactUsageBeforeReturning(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $before = Fixture::databaseNow($f->db());
            $result = $f->disclose($selected);
            self::assertSame(D\Outcome::Success, $result->result->outcome, $result->result->errorCode ?? 'success');
            $evidence = $result->result->data;
            self::assertInstanceOf(D\EvidenceSet::class, $evidence);
            self::assertFalse($f->db()->pdo->inTransaction());
            self::assertSame($f->lexical->data['exact']['text'], $evidence->toWire()->get('fragments')[0]->get('text'));
            self::assertSame('untrusted', $evidence->trustClass());
            self::assertFalse($evidence->memoryActivation());
            self::assertGreaterThanOrEqual(0, $evidence->checkedAt()->compare($before));
            $row = $f->db()->row('SELECT * FROM ' . $f->db()->table('evidence_usages') . ' WHERE tenant_id=? AND id=?',
                [Fixture::id(1), $evidence->usageRef()->value]);
            self::assertSame(str_repeat('a', 64), $row['host_scope_hash']);
            self::assertSame('run-1', $row['run_ref']);
            self::assertNull($row['payload_resource_id']);
            self::assertNull($row['provider_accepted_at']);
            $lineage = $f->db()->row('SELECT exact_version_ref FROM ' . $f->db()->table('usage_refs') . ' WHERE tenant_id=? AND usage_id=?',
                [Fixture::id(1), $evidence->usageRef()->value]);
            self::assertEquals($f->ref()->toWire(), StrictJson::decode($lineage['exact_version_ref'])->get('fragmentRefs')[0]->get('chunkRef'));
            $event = $f->db()->row('SELECT event_json FROM ' . $f->db()->table('outbox') . ' WHERE tenant_id=? AND id=?',
                [Fixture::id(1), $evidence->usageRef()->value]);
            self::assertSame('usage.prepared_disclosure', StrictJson::decode($event['event_json'])->get('type'));
            foreach ([$row, $lineage, $event] as $metadata) {
                self::assertStringNotContainsString($f->lexical->data['exact']['text'], json_encode($metadata, JSON_THROW_ON_ERROR));
            }
            self::assertSame(2, $f->objects->reads);
            self::assertSame(2, $f->objects->closes);
        });
    }

    public function testRevokeAfterSearchAndAfterRerankRejectsCachedSelection(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $request = $f->request($selected);
            $record = $f->selections->requireBound($f->host->context(), $selected->token,
                $f->query(), $f->host->inventory->snapshot, 'run-1');
            // A rank-only consumer can reorder a subset; it cannot refresh the selection's authority.
            $cached = (new D\SelectionRecordCodec(PortFixture::validator()))
                ->decode((new D\SelectionRecordCodec(PortFixture::validator()))->encode($record));
            self::assertEquals($record->selection->candidateRefs(), $cached->selection->candidateRefs());
            $f->revoke();
            foreach ([$request, $f->request($selected)] as $attempt) {
                $this->assertNoPayload($f->disclose($selected, $attempt), D\Outcome::Denied);
            }
            self::assertSame(0, $f->objects->reads);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testRevokeImmediatelyBeforeUsageCommitRejectsPreloadedBuffer(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $f->objects->beforeOpen = fn () => $f->revoke();
            $result = $f->disclose($selected);
            $this->assertNoPayload($result, D\Outcome::Denied);
            self::assertSame(2, $f->objects->reads);
            self::assertSame(2, $f->objects->closes);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testCorruptOrMissingCanonicalObjectNeverUsesForgedBackendText(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $key = $f->ref()->revisionRef->irDigest->value;
            $original = $f->objects->bodies[$key];
            $f->objects->bodies[$key] = str_repeat('X', strlen($original));
            $bad = $f->disclose($selected);
            $this->assertNoPayload($bad, D\Outcome::Incompatible);
            self::assertSame('digest_mismatch', $bad->result->errorCode);
            unset($f->objects->bodies[$key]);
            $this->assertNoPayload($f->disclose($selected), D\Outcome::Unavailable);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
            self::assertSame(1, $f->objects->closes);
        });
    }

    public function testUnknownOwnerAndUnavailableOwnerRemainDistinct(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $f->host->decision = D\Decision::Unknown;
            $this->assertNoPayload($f->disclose($selected), D\Outcome::Unknown);
            $f->host->decision = D\Decision::Deny;
            $this->assertNoPayload($f->disclose($selected), D\Outcome::Denied);
            $f->host->decision = D\Decision::Allow;
            $f->owner->beforeDecision = static function (): void {
                throw new \RuntimeException('sensitive raw owner payload');
            };
            $failure = $f->disclose($selected);
            $this->assertNoPayload($failure, D\Outcome::Unavailable);
            self::assertStringNotContainsString('sensitive', json_encode($failure, JSON_THROW_ON_ERROR));
            self::assertSame(0, $f->objects->reads);
        });
    }

    public function testApprovedInstructionMetadataCannotPromoteEvidenceOrActivateMemory(): void
    {
        Fixture::run(function (Fixture $f): void {
            $source = $f->ref()->revisionRef->sourceRef;
            $f->db()->execute('UPDATE ' . $f->db()->table('source_memberships') . ' SET metadata=?::jsonb WHERE tenant_id=? AND kb_id=? AND source_id=?',
                ['{"host.status":"approved","host.instruction_document":true}', Fixture::id(1), Fixture::id(100), $source->sourceId->value]);
            $stored = $f->db()->row('SELECT metadata FROM ' . $f->db()->table('source_memberships') . ' WHERE tenant_id=? AND kb_id=? AND source_id=?',
                [Fixture::id(1), Fixture::id(100), $source->sourceId->value]);
            self::assertSame('approved', StrictJson::decode($stored['metadata'])->get('host.status'));
            $identity = serialize($f->host->identity);
            $result = $f->disclose($f->select());
            self::assertSame(D\Outcome::Success, $result->result->outcome);
            self::assertSame('untrusted', $result->result->data->trustClass());
            self::assertFalse($result->result->data->memoryActivation());
            self::assertSame($identity, serialize($f->host->identity));
        });
    }

    public function testMissingCanonicalChunkFailsDespiteIntactIrAndCachedSelection(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            unset($f->objects->bodies[$f->ref()->chunkDigest->value]);
            $result = $f->disclose($selected);
            $this->assertNoPayload($result, D\Outcome::Unavailable);
            self::assertSame(2, $f->objects->reads);
            self::assertSame(1, $f->objects->closes);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testOneMissingRequiredFragmentRejectsWholePayload(): void
    {
        Fixture::run(function (Fixture $f): void {
            $other = $f->lexical->sets['allowed']->chunkSet->chunks[0]->ref;
            $f->host->eligible[] = $other->revisionRef->sourceRef->sourceVersionId;
            $binding = $f->host->binding;
            $sources = [...$binding->get('sources'), new JsonObject(['sourceId' => $other->revisionRef->sourceRef->sourceId->value,
                'sourcePolicyEpoch' => '0', 'lifecycleEpoch' => '0'])];
            usort($sources, static fn (JsonObject $a, JsonObject $b): int => strcmp($a->get('sourceId'), $b->get('sourceId')));
            $f->host->binding = $binding->with('sources', $sources);
            $f->host->candidates = [new D\Candidate($f->ref(), 1, 1.0), new D\Candidate($other, 2, 0.5)];
            $store = new PdoSelections($f->db(), new D\SelectionRecordCodec(PortFixture::validator()));
            $f->selections = new Selections($f->checks(), $f->host, $f->catalog, $store,
                new BoundedSelector(), $f->host, $f->host, PortFixture::validator());
            $canonical = new CanonicalBuffer($f->catalog,
                new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()), $f->host);
            $f->service = new Disclosures($f->selections, $f->checks(), $canonical,
                $f->usages, $f->host, PortFixture::validator());
            $selected = $f->select();
            self::assertCount(2, $selected->result->data->candidateRefs());
            $request = new D\DisclosureRequest($f->request($selected)->toWire()->with('chunkRefs', [$f->ref()->toWire(), $other->toWire()]), PortFixture::validator());
            unset($f->objects->bodies[$other->revisionRef->irDigest->value]);
            $this->assertNoPayload($f->disclose($selected, $request), D\Outcome::Unavailable);
            self::assertSame(3, $f->objects->reads);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testAttemptReplayReturnsMetadataOnlyAndNewAttemptRechecksRevoke(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $request = $f->request($selected);
            $first = $f->disclose($selected, $request)->result->data;
            self::assertInstanceOf(D\EvidenceSet::class, $first);
            $history = $first->canonical();
            $reads = $f->objects->reads;
            $calls = $f->owner->calls;
            $replay = $f->disclose($selected, $request)->result->data;
            self::assertInstanceOf(D\DisclosureReplay::class, $replay);
            self::assertEquals($first->usageRef(), $replay->usageRef);
            self::assertSame($reads, $f->objects->reads);
            self::assertSame($calls, $f->owner->calls);
            $f->revoke();
            self::assertInstanceOf(D\DisclosureReplay::class, $f->disclose($selected, $request)->result->data);
            $this->assertNoPayload($f->disclose($selected), D\Outcome::Denied);
            self::assertSame($history, $first->canonical());
            self::assertSame(1, $f->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testBindingsExpiryAndRequiredAttemptFailWithoutBytes(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $request = $f->request($selected, 'different-run');
            $this->assertNoPayload($f->disclose($selected, $request), D\Outcome::Denied);
            $result = $f->service->disclose($f->host->context(), $selected->token, $f->host->query(text: 'other'),
                $f->host->inventory->snapshot, $f->request($selected));
            $this->assertNoPayload($result, D\Outcome::Denied);
            $old = $f->host->identity;
            $s = $old->scope;
            foreach ([new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, new D\Sha256(str_repeat('b', 64))),
                new D\Scope(new D\TenantId(Fixture::id(2)), $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, $s->hostScopeHash),
                new D\Scope($s->tenantId, $s->kbId, new D\ActorId(Fixture::id(999)), $s->purpose, $s->policyEpoch, $s->hostScopeHash),
                new D\Scope($s->tenantId, $s->kbId, $s->actorId, 'lesson.other', $s->policyEpoch, $s->hostScopeHash)] as $scope) {
                $f->host->identity = new PolicyContext($scope, $old->audience, $old->grants);
                $this->assertNoPayload($f->disclose($selected), D\Outcome::Denied);
            }
            $f->host->identity = $old;
            try {
                new D\DisclosureRequest($f->request($selected)->toWire()->without('disclosureAttemptId'),
                    PortFixture::validator());
                self::fail('Missing attempt accepted');
            } catch (ContractViolation $error) {
                self::assertSame('required', $error->errorCode);
            }
            $f->host->now = $f->host->now->plusSeconds(30);
            $this->assertNoPayload($f->disclose($selected), D\Outcome::Denied);
            self::assertSame(0, $f->objects->reads);
        });
    }

    public function testDirectUsageHasNullRunAndPurgeRetainsAuditFact(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select(null);
            $request = $f->request($selected, null);
            $first = $f->disclose($selected, $request)->result->data;
            self::assertInstanceOf(D\EvidenceSet::class, $first);
            $usage = $f->db()->row('SELECT * FROM ' . $f->db()->table('evidence_usages') . ' WHERE tenant_id=? AND id=?',
                [Fixture::id(1), $first->usageRef()->value]);
            self::assertNull($usage['run_ref']);
            $f->objects->bodies = [];
            $f->revoke();
            $after = $f->db()->row('SELECT * FROM ' . $f->db()->table('evidence_usages') . ' WHERE tenant_id=? AND id=?',
                [Fixture::id(1), $first->usageRef()->value]);
            self::assertSame($usage, $after);
            self::assertSame(1, $f->lexical->catalog->count('usage_refs'));
            self::assertInstanceOf(D\DisclosureReplay::class, $f->disclose($selected, $request)->result->data);
            $this->assertNoPayload($f->disclose($selected, $f->request($selected, null)), D\Outcome::Denied);
        });
    }

    public function testProjectionRebuildKeepsSourceRevisionAndCitationIdentity(): void
    {
        require_once dirname(__DIR__, 2) . '/lifecycle/restore/ProjectionTest.php';
        $projection = new ProjectionTest(
            'testVerifiedManifestRebuildsLostProjectionWithoutChangingHistoricalIdentities',
        );
        $projection->testVerifiedManifestRebuildsLostProjectionWithoutChangingHistoricalIdentities();
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $before = $f->disclose($selected)->result->data;
            self::assertInstanceOf(D\EvidenceSet::class, $before);
            $restored = $f->select();
            self::assertEquals($selected->result->data->candidateRefs(), $restored->result->data->candidateRefs());
            $after = $f->disclose($restored)->result->data;
            self::assertInstanceOf(D\EvidenceSet::class, $after);
            $a = $before->toWire()->get('fragments')[0]->get('citation')->without('citationId');
            $b = $after->toWire()->get('fragments')[0]->get('citation')->without('citationId');
            self::assertEquals($a, $b);
            self::assertSame(2, $f->lexical->catalog->count('usage_refs'));
        });
    }

    private function assertNoPayload(D\DisclosureOutcome $result, D\Outcome $outcome): void
    {
        self::assertSame($outcome, $result->result->outcome, $result->result->errorCode ?? 'unexpected success');
        self::assertNull($result->result->data);
    }
}
