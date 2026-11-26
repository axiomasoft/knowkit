<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Selection;

use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Domain\SelectionRecordCodec as RecordCodec;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Tokenizer;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:selection')]
final class SelectionTest extends TestCase
{
    public function testExpiredSelectionAndOpaqueToken(): void
    {
        $f = new Fixture();
        $service = $f->service();
        $result = $f->retrieve(service: $service);
        self::assertSame(D\Outcome::Success, $result->result->outcome);
        self::assertSame(1, $f->queryChecks);
        self::assertSame(64, strlen($result->token->reveal()));
        self::assertSame(hash('sha256', hex2bin($result->token->reveal())), $result->token->hash()->value);
        self::assertStringNotContainsString($result->token->reveal(), print_r($f->store, true));
        $record = $service->requireBound($f->context(), $result->token, $f->query(), $f->inventory->snapshot, 'run-1');
        self::assertSame('gravity', $record->query->original->text());
        self::assertSame(str_repeat('a', 64), $record->selection->toWire()->get('hostScopeHash'));
        self::assertStringNotContainsString('gravity', $record->selection->canonical());
        $f->now = $f->now->plusSeconds(Fixture::corpus()['scenarios']['expired']['advance']);
        $this->failure(fn () => $service->requireBound($f->context(), $result->token, $f->query(), $f->inventory->snapshot, 'run-1'),
            D\Outcome::Denied, 'selection_expired');
        $this->failure(fn () => $service->requireBound($f->context(), D\SelectionToken::issue(), $f->query(), $f->inventory->snapshot, 'run-1'),
            D\Outcome::Denied, 'selection_invalid');
    }

    public function testAudienceHostScopeActorPurposeAndGrantChangesAreDenied(): void
    {
        $f = new Fixture();
        $result = $f->retrieve();
        $base = $f->identity;
        foreach (['audience', 'hostScopeHash', 'actor', 'purpose', 'grants', 'epoch'] as $change) {
            $f->identity = $base;
            $s = $f->identity->scope;
            $scope = new D\Scope($s->tenantId, $s->kbId, $change === 'actor' ? new D\ActorId(Fixture::id(102)) : $s->actorId,
                $change === 'purpose' ? 'lesson.audit' : $s->purpose, new D\Decimal($change === 'epoch' ? '1' : '0'),
                $change === 'hostScopeHash' ? new D\Sha256(str_repeat('b', 64)) : $s->hostScopeHash);
            $f->identity = new PolicyContext($scope, $change === 'audience' ? 'student' : 'teacher',
                $change === 'grants' ? [] : ['source:read']);
            $this->failure(fn () => $f->service()->requireBound($f->context(), $result->token, $f->query(), $f->inventory->snapshot, 'run-1'),
                D\Outcome::Denied, $change === 'epoch' ? 'POLICY_CHANGED' : ($change === 'hostScopeHash' ? 'selection_invalid' : 'scope_mismatch'));
        }
    }

    public function testQueryRunSnapshotProfileAndSpaceBindings(): void
    {
        $f = new Fixture();
        $result = $f->retrieve();
        $snapshot = $f->inventory->snapshot;
        $other = new D\SnapshotRef($snapshot->tenantId, $snapshot->kbId, $snapshot->snapshotId, new D\Fingerprint(str_repeat('b', 64)));
        foreach ([[$f->query(text: 'other'), $snapshot, 'run-1'], [$f->query(), $snapshot, 'run-2'],
            [$f->query(), $snapshot, null], [$f->query(), $other, 'run-1'],
            [$f->query(D\SelectionLimits::profile('document')), $snapshot, 'run-1'],
            [(new Normalizer())->bind(new D\Query('gravity'), new D\EffectiveProfile('selection-test', '2', []),
                D\SelectionLimits::profile('compact')), $snapshot, 'run-1']] as [$query, $pin, $run]) {
            $this->failure(fn () => $f->service()->requireBound($f->context(), $result->token, $query, $pin, $run),
                D\Outcome::Denied, 'scope_mismatch');
        }
        $query = (new Normalizer())->bind(new D\Query('gravity'), Fixture::profile(), D\SelectionLimits::profile('compact'),
            new D\Fingerprint(str_repeat('c', 64)));
        self::assertSame('space_mismatch', $f->retrieve($query)->result->errorCode);
    }

    public function testRewriteKeepsOriginalExactIdentifiersAndTypedFilters(): void
    {
        $f = new Fixture();
        $case = Fixture::corpus()['scenarios']['rewrite'];
        $original = new D\Query($case['original'], [new D\SourceVersionId(Fixture::id(3))], []);
        $q = (new Normalizer())->bind($original, Fixture::profile(), D\SelectionLimits::profile('compact'),
            rewrite: $case['valid'], revision: 'rewrite-test:1');
        self::assertSame($case['original'], $q->original->text());
        self::assertSame($case['valid'], $q->normalized->text());
        self::assertSame($original->sourceVersionIds, $q->normalized->sourceVersionIds);
        self::assertSame([], $q->normalized->collectionIds);
        foreach ([$case['invalid'], str_replace('tenant:alpha', 'tenant:beta', $case['valid']),
            str_replace('foo_bar.v2', 'foo_bar.v3', $case['valid'])] as $rewrite) {
            $this->failure(fn () => (new Normalizer())->bind($original, Fixture::profile(), D\SelectionLimits::profile('compact'), rewrite: $rewrite),
                D\Outcome::Incompatible, 'rewrite_identifier_mismatch');
        }
        $this->failure(fn () => new D\QueryBinding($original, new D\Query($case['valid']), 'rewrite-test:1',
            Fixture::profile(), D\SelectionLimits::profile('compact')), D\Outcome::Incompatible, 'rewrite_filter_mismatch');
        $phrase = new D\Query('Найди "точная фраза" revision 7');
        $this->failure(fn () => (new Normalizer())->bind($phrase, Fixture::profile(), D\SelectionLimits::profile('compact'),
            rewrite: 'Find "другая фраза" revision 7'), D\Outcome::Incompatible, 'rewrite_identifier_mismatch');
        $spaced = (new Normalizer())->bind(new D\Query('  Найди "точная  фраза"  '), Fixture::profile(), D\SelectionLimits::profile('compact'));
        self::assertSame('Найди "точная  фраза"', $spaced->normalized->text());
    }

    public function testPinnedHistoryAndConcurrentActivationDoNotRetargetSelection(): void
    {
        $f = new Fixture();
        $pin = $f->inventory->snapshot;
        $latest = $pin;
        $f->duringSearch = static function () use (&$latest, $pin): void {
            $latest = new D\SnapshotRef($pin->tenantId, $pin->kbId, new D\SnapshotId(Fixture::id(111)), new D\Fingerprint(str_repeat('c', 64)));
        };
        $result = $f->retrieve();
        self::assertNotEquals($pin, $latest);
        self::assertSame($pin->snapshotId->value, $result->result->data->toWire()->get('snapshotId'));
        $record = $f->service()->requireBound($f->context(), $result->token, $f->query(), $pin, 'run-1');
        self::assertSame($pin, $record->snapshot);
        $historical = $f->retrieve();
        self::assertSame($pin->snapshotId->value, $historical->result->data->toWire()->get('snapshotId'));
        $this->failure(fn () => $f->service()->requireBound($f->context(), $result->token, $f->query(), $latest, 'run-1'),
            D\Outcome::Denied, 'scope_mismatch');
    }

    public function testDeterministicDedupeOverlapAndForgedRefs(): void
    {
        $f = new Fixture('overlap');
        $f->retrieve();
        $chunks = $f->inventory->manifest->sets[0]->chunkSet->chunks;
        self::assertCount(2, $chunks);
        $candidates = [new D\Candidate($chunks[1]->ref, 1), new D\Candidate($chunks[0]->ref, 1), new D\Candidate($chunks[0]->ref, 1)];
        $selector = new BoundedSelector();
        $chosen = $selector->choose($f->lastSearch, $candidates, $f->inventory, D\SelectionLimits::profile('compact'));
        $reverse = $selector->choose($f->lastSearch, array_reverse($candidates), $f->inventory, D\SelectionLimits::profile('compact'));
        self::assertEquals($chosen, $reverse);
        self::assertCount(1, $chosen->refs);
        self::assertContains('overlap_omitted', $chosen->warnings);
        $ids = array_map(static fn (D\Chunk $c): string => $c->ref->chunkId->value, $chunks);
        self::assertSame(min($ids), $chosen->refs[0]->chunkId->value);
        $good = $chunks[0]->ref;
        $bad = new D\ChunkRef($good->revisionRef, $good->chunkSetId, $good->chunkId, new D\Sha256(str_repeat('f', 64)));
        $this->failure(fn () => $selector->choose($f->lastSearch, [...$candidates, new D\Candidate($bad, 99)],
            $f->inventory, D\SelectionLimits::profile('compact')), D\Outcome::Incompatible, 'candidate_not_in_manifest');
    }

    public function testWholeUtf8ByteAndExactTokenBudgets(): void
    {
        $f = new Fixture('multibyte');
        $identity = new D\TokenizerIdentity(new D\AdapterId('test.codepoints'), '1', new D\Sha256(str_repeat('c', 64)));
        $tokenizer = $this->tokenizer($identity);
        $small = D\SelectionLimits::profile('compact', maxBytes: 19, maxBytesPerFragment: 19);
        $empty = $f->retrieve($f->query($small));
        self::assertSame(D\Outcome::Empty, $empty->result->outcome);
        self::assertContains('budget_exhausted', $empty->result->warnings);
        self::assertSame('compact', $empty->result->effectiveProfile);
        $limits = D\SelectionLimits::profile('compact', maxTokens: 11, tokenizer: $identity);
        $bounded = $f->retrieve($f->query($limits), $f->service($tokenizer));
        self::assertSame(D\Outcome::Success, $bounded->result->outcome);
        $text = $f->inventory->manifest->sets[0]->chunkSet->chunks[0]->text();
        self::assertSame(1, preg_match('//u', $text));
        self::assertSame(11, mb_strlen($text, 'UTF-8'));
        $limits = D\SelectionLimits::profile('compact', maxTokens: 10, tokenizer: $identity);
        self::assertSame(D\Outcome::Empty, $f->retrieve($f->query($limits), $f->service($tokenizer))->result->outcome);
        self::assertSame('tokenizer_required', $f->retrieve($f->query($limits))->result->errorCode);
        $wrong = new D\TokenizerIdentity($identity->id, '2', $identity->vocabularyDigest);
        self::assertSame('tokenizer_mismatch', $f->retrieve($f->query($limits), $f->service($this->tokenizer($wrong)))->result->errorCode);
        self::assertSame('unsupported_capability', $f->retrieve($f->query($limits), $f->service($this->tokenizer($identity, false)))->result->errorCode);
        $this->failure(fn () => D\SelectionLimits::profile('compact', maxTokens: 1), D\Outcome::Incompatible, 'tokenizer_required');
    }

    public function testTableRowsRetainHeadersUnitsNotesOrRequireSeparateRead(): void
    {
        $f = new Fixture('table');
        $q = $f->query(D\SelectionLimits::profile('table', maxBytes: 32, maxBytesPerFragment: 32));
        $result = $f->retrieve($q);
        self::assertContains('table_read_required', $result->result->warnings);
        self::assertCount(1, $result->readRequirements);
        $oversized = $f->inventory->unit($result->readRequirements[0]);
        foreach (['Heading', 'Mass', 'kg', 'note', 'Значение'] as $context) {
            self::assertStringContainsString($context, $oversized->budgetText());
        }
        $full = $f->retrieve($f->query(D\SelectionLimits::profile('table')));
        self::assertSame(D\Outcome::Success, $full->result->outcome);
        self::assertSame([], $full->readRequirements);
        self::assertContainsEquals($oversized->chunk->ref->toWire(), $full->result->data->toWire()->get('candidateRefs'));
    }

    public function testFragmentAndAssetLimitsAreIndependent(): void
    {
        $f = new Fixture('visual');
        $result = $f->retrieve($f->query(D\SelectionLimits::profile('visual')));
        self::assertCount(2, $result->result->data->toWire()->get('candidateRefs'));
        self::assertContains('budget_exhausted', $result->result->warnings);
        $one = $f->retrieve($f->query(D\SelectionLimits::profile('visual', maxFragments: 1)));
        self::assertCount(1, $one->result->data->toWire()->get('candidateRefs'));
        self::assertSame(D\Outcome::Empty, $f->retrieve()->result->outcome);
    }

    public function testSixTypedOutcomesAndNoPartialPayload(): void
    {
        $f = new Fixture();
        $outcomes = [$f->retrieve()->result->outcome->value];
        $f->candidates = [];
        $outcomes[] = $f->retrieve()->result->outcome->value;
        foreach ([D\Outcome::Denied, D\Outcome::Unknown, D\Outcome::Unavailable, D\Outcome::Incompatible] as $outcome) {
            $f->failure = new D\DomainFailure($outcome, 'test_failure');
            $result = $f->retrieve();
            $outcomes[] = $result->result->outcome->value;
            self::assertNull($result->result->data);
            self::assertNull($result->token);
            self::assertSame([], $result->readRequirements);
        }
        self::assertSame(Fixture::corpus()['expectedOutcomes'], $outcomes);
        self::assertSame(2, $f->searchCalls);
    }

    public function testSelectionIsNotAuthorityAfterRevokeAndEveryUseNeedsFreshPolicy(): void
    {
        $f = new Fixture();
        $service = $f->service();
        $result = $f->retrieve(service: $service);
        $record = $service->requireBound($f->context(), $result->token, $f->query(), $f->inventory->snapshot, 'run-1');
        $resource = new D\ResourceRef($f->identity->scope->tenantId, 'source_version', new D\SourceVersionId(Fixture::id(3)));
        $f->decision = D\Decision::Deny;
        $this->failure(fn () => $f->checks()->prepare($f->context(), [$resource], D\Operation::Disclose, $f->binding),
            D\Outcome::Denied, 'policy_denied');
        $f->decision = D\Decision::Unknown;
        $this->failure(fn () => $f->checks()->prepare($f->context(), [$resource], D\Operation::Disclose, $f->binding),
            D\Outcome::Unknown, 'policy_unknown');
        self::assertSame($record, $service->requireBound($f->context(), $result->token, $f->query(), $f->inventory->snapshot, 'run-1'));
        self::assertStringNotContainsString('gravity', $record->selection->canonical());
        $f->decision = D\Decision::Allow;
        $prepared = $f->checks()->prepare($f->context(), [$resource], D\Operation::Disclose, $f->binding);
        $prepared->requireAllow($f->context(), $f->binding, $f->now);
        $this->failure(fn () => $prepared->requireAllow($f->context(), $f->binding, $f->now), D\Outcome::Denied, 'decision_consumed');
    }

    public function testRecordRoundTripImmutableStoreAndCacheBinding(): void
    {
        $f = new Fixture();
        $result = $f->retrieve();
        $record = $f->service()->requireBound($f->context(), $result->token, $f->query(), $f->inventory->snapshot, 'run-1');
        $codec = new RecordCodec(PortFixture::validator());
        $copy = $codec->decode($codec->encode($record));
        self::assertSame($record->query->digest()->value, $copy->query->digest()->value);
        self::assertSame(Jcs::canonicalize($codec->encode($record)), Jcs::canonicalize($codec->encode($copy)));
        self::assertSame($record->cacheKey()->value, $copy->cacheKey()->value);
        $this->failure(fn () => $f->store->put($f->identity->scope, $result->token->hash(), $record), D\Outcome::Incompatible, 'immutable_conflict');
        $other = new D\Scope(new D\TenantId(Fixture::id(99)), $f->identity->scope->kbId, $f->identity->scope->actorId,
            'lesson.read', new D\Decimal('0'), $f->identity->scope->hostScopeHash);
        self::assertNull($f->store->get($other, $result->token->hash()));
    }

    public function testApprovedProfilesAndTtlBounds(): void
    {
        foreach (['compact' => [4, 4096, 1024, 0], 'document' => [8, 16384, 4096, 0],
            'table' => [4, 32768, 16384, 0], 'visual' => [4, 8192, 2048, 2], 'agentools-v1' => [3, 2048, 512, 0]] as $name => $expected) {
            $limits = D\SelectionLimits::profile($name);
            self::assertSame($expected, [$limits->maxFragments, $limits->maxBytes, $limits->maxBytesPerFragment, $limits->maxAssets]);
        }
        $this->failure(fn () => D\SelectionLimits::profile('agentools-v1', maxBytes: 31, maxBytesPerFragment: 31),
            D\Outcome::Incompatible, 'limit_exceeded');
        $this->failure(fn () => D\SelectionLimits::profile('agentools-v1', maxFragments: 9), D\Outcome::Incompatible, 'limit_exceeded');
        $this->failure(fn () => D\SelectionLimits::profile('agentools-v1', maxBytes: 8193), D\Outcome::Incompatible, 'limit_exceeded');
        $this->failure(fn () => D\SelectionLimits::profile('unapproved'), D\Outcome::Incompatible, 'profile_incompatible');
        $f = new Fixture();
        foreach ([0, 301] as $ttl) {
            self::assertSame('limit_exceeded', $f->retrieve(ttl: $ttl)->result->errorCode);
        }
        self::assertSame(0, $f->searchCalls);
        self::assertSame(D\Outcome::Success, $f->retrieve(ttl: 300)->result->outcome);
    }

    public function testNewSnapshotRefsCannotBeMixedAndBackendExceptionsFailClosed(): void
    {
        $f = new Fixture();
        $new = new Fixture('multibyte');
        $f->candidates = [new D\Candidate($new->inventory->manifest->sets[0]->chunkSet->chunks[0]->ref, 1)];
        $result = $f->retrieve();
        self::assertSame(D\Outcome::Incompatible, $result->result->outcome);
        self::assertSame('candidate_not_in_manifest', $result->result->errorCode);
        self::assertNull($result->result->data);
        $f->candidates = null;
        $f->duringSearch = static function (): void {
            throw new \RuntimeException('backend down');
        };
        $result = $f->retrieve();
        self::assertSame(D\Outcome::Unavailable, $result->result->outcome);
        self::assertNull($result->result->data);
        self::assertNull($result->token);
    }

    public function testCanonicalSelectionRejectsUtf8ContinuationOffsets(): void
    {
        $f = new Fixture('multibyte');
        $result = $f->inventory->manifest->sets[0];
        $chunk = $result->chunkSet->chunks[0];
        $span = new D\Span(D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), 1, strlen($chunk->text())),
            $chunk->spans[0]->textDigest);
        $forged = new D\Chunk($chunk->ref, $chunk->documentId, [$span], $chunk->text());
        $entry = $result->metadata($chunk->ref->chunkId->value)
            ->with('spans', [$span->locator->toWire()->with('textDigest', $span->textDigest->value)]);
        $set = new D\ChunkingResult(new D\ChunkSet($result->chunkSet->revisionRef, $result->chunkSet->chunkSetId,
            $result->chunkSet->chunkerSignature, [$forged]), $result->manifest()->with('chunks', [$entry]));
        $manifest = (new Builder(PortFixture::validator()))->build($f->identity->scope, $f->inventory->generationId(),
            [$set], [], $result->manifest()->get('coverage'));
        $this->failure(fn () => (new CanonicalUnits())->build($f->inventory->snapshot, $manifest, $f->documents),
            D\Outcome::Incompatible, 'utf8_boundary');
    }

    private function tokenizer(D\TokenizerIdentity $identity, bool $exact = true): Tokenizer
    {
        return new class ($identity, $exact) implements Tokenizer {
            public function __construct(
                private D\TokenizerIdentity $identity,
                private bool $exact,
            ) {}

            public function capabilities(): D\TokenizerCapabilities
            {
                return new D\TokenizerCapabilities($this->identity, $this->exact);
            }

            public function count(string $utf8, D\TokenizerIdentity $identity): int
            {
                $this->identity->assertCompatible($identity);

                return mb_strlen($utf8, 'UTF-8');
            }
        };
    }

    private function failure(\Closure $action, D\Outcome $outcome, string $code): void
    {
        try {
            $action();
            self::fail('Forbidden input accepted');
        } catch (D\DomainFailure $error) {
            self::assertSame($outcome, $error->outcome);
            self::assertSame($code, $error->errorCode);
        }
    }
}
