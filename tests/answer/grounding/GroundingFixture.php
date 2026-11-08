<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Grounding;

use Closure;
use DateTimeImmutable;
use KnowKit\Answer\Adapter\Psr18\OpenAiRequest;
use KnowKit\Answer\Application as App;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port as P;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Application\Disclosure;
use KnowKit\Application\Retrieval;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\ScopeBinding;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\Clock;
use KnowKit\Port\EgressPolicy;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\Tokenizer;
use KnowKit\Tests\Answer\Contracts\Fixture;
use PHPUnit\Framework\Assert;

final class GroundingFixture implements Clock, IdGenerator, Retrieval, Disclosure, P\ModelBindingResolver, P\InvocationAuthorization, EgressPolicy, P\AnswerBudget, P\ModelTransport, P\InvocationPayloads
{
    public array $events = [];
    public int $seconds = 0;
    public int $counter = 20;
    public int $sends = 0;
    public int $outboundBytes = 0;
    public int $resolutions = 0;
    public int $commits = 0;
    public int $releases = 0;
    public bool $cancelled = false;
    public bool $empty = false;
    public bool $revoked = false;
    public bool $replayDenied = false;
    public bool $settlementFails = false;
    public bool $reserveThrows = false;
    public bool $unknownTransport = false;
    public bool $noUsage = false;
    public bool $transportThrows = false;
    public bool $testTransport = false;
    public ?string $egressDeny = null;
    public string $egressDecision = 'Allow';
    public ?D\Outcome $disclosureFailure = null;
    public ?Closure $afterReserve = null;
    public ?Closure $afterMark = null;
    public ?Closure $onSend = null;
    public ?Closure $mutateCitation = null;
    public ?Closure $mutateEvidence = null;
    public ?string $evidenceText = null;
    public ?string $failPayloadKind = null;
    public ?string $outputText = null;
    public ?string $observedModel = null;
    public bool $incomplete = false;
    public A\ReservationStatus $reservationStatus = A\ReservationStatus::Confirmed;
    public A\ResolvedModelBinding $binding;
    public ?A\TransportRequest $lastRequest = null;
    public ?A\UsageObservation $settled = null;
    public ?A\Estimate $estimate = null;
    public array $stored = [];
    public array $kinds = [];
    public MemoryLedger $ledger;
    public ContractValidator $validator;

    public function __construct()
    {
        $schemas = [];
        foreach (['common', 'citation-ref', 'selection', 'evidence-set', 'disclosure-request', 'decision-receipt', 'egress-request', 'usage'] as $name) {
            $schema = StrictJson::decode((string) file_get_contents(Fixture::root() . "/schemas/{$name}.schema.json"));
            $schemas[$schema->get('$id')] = $schema;
        }
        $this->validator = new ContractValidator(new SchemaSet($schemas));
        $this->binding = Fixture::binding(['transport' => $this->descriptor()]);
        $this->ledger = new MemoryLedger($this);
    }

    public static function corpus(): array
    {
        return json_decode((string) file_get_contents(Fixture::root() . '/tests/fixtures/answer/grounding/v1/cases.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function request(array $changes = []): A\AnswerRequest
    {
        $turns = new A\BoundedTurns([new A\PriorTurn(A\MessageRole::Assistant, self::corpus()['history'])]);
        $attestation = new A\HistoryReadAttestation(Fixture::scope(), $turns->digest(), 'history:current', $this->date()->modify('+60 seconds'));

        return Fixture::request(array_replace([
            'question' => self::corpus()['question'],
            'priorTurns' => new A\BoundedTurns($turns->turns, $attestation),
        ], $changes));
    }

    public function service(?Tokenizer $tokenizer = null, ProfileMode $mode = ProfileMode::Test): App\GroundedAnswerService
    {
        return new App\GroundedAnswerService(
            new App\InvocationAdmission($this->ledger, $this),
            $this->ledger,
            new App\InvocationReplay($this->ledger, $this, $this, $this->validator),
            new App\GroundingEvidenceReader($this, $this, $this, $this->validator, Fixture::profile()),
            new App\GroundingPromptComposer($tokenizer),
            $this,
            new App\CurrentModelEgress($this, $this, $this->validator),
            $this,
            $this,
            new App\AnswerOutcomeMapper(new App\CitationValidator($this->validator), $tokenizer),
            new App\AnswerSettlement($this, $this, $this),
            $this,
            $mode,
        );
    }

    public function token(): A\CancellationToken
    {
        return new class ($this) implements A\CancellationToken {
            public function __construct(
                private GroundingFixture $fixture,
            ) {
            }

            public function isCancelled(): bool
            {
                return $this->fixture->cancelled;
            }
        };
    }

    public function now(): D\UtcInstant
    {
        return new D\UtcInstant($this->date()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function date(): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-11-08T00:00:01Z'))->modify("+{$this->seconds} seconds");
    }

    public function next(): D\Ulid
    {
        return new D\Ulid(self::id(++$this->counter));
    }

    public static function id(int $number): string
    {
        return '01JC' . str_pad((string) $number, 22, '0', STR_PAD_LEFT);
    }

    public function resolve(D\Scope $scope, A\ModelBindingRef $ref): A\ResolvedModelBinding
    {
        $this->events[] = 'resolve';
        $this->resolutions++;
        $this->binding->requireScope($scope);
        Assert::assertEquals($this->binding->requestedRef, $ref);

        return $this->binding;
    }

    public function retrieve(D\Scope $scope, D\Query $query, D\Limit $limit): D\RetrievalOutcome
    {
        $this->events[] = 'retrieve';
        Assert::assertEquals(Fixture::scope(), $scope);
        Assert::assertSame(4, $limit->k);

        return new D\RetrievalOutcome($this->empty ? D\Result::empty() : D\Result::success($this->selection()));
    }

    public function disclose(D\Scope $scope, D\DisclosureRequest $request): D\DisclosureOutcome
    {
        $this->events[] = 'disclose';
        Assert::assertEquals(Fixture::scope(), $scope);
        Assert::assertEquals($this->selection()->candidateRefs()[0]->toWire(), $request->toWire()->get('chunkRefs')[0]);
        if ($this->disclosureFailure !== null) {
            return new D\DisclosureOutcome(D\Result::failure(new D\DomainFailure($this->disclosureFailure, 'revoked')));
        }

        return new D\DisclosureOutcome(D\Result::success($this->evidence($request->attemptId()->value)));
    }

    public function selection(): D\Selection
    {
        $scope = Fixture::scope();
        $bindingDigest = HashDomain::PolicyBinding->digest($this->policyBinding());
        $wire = new JsonObject([
            'selectionId' => self::id(9), 'tenantId' => $scope->tenantId->value,
            'hostScopeHash' => $scope->hostScopeHash->value, 'runRef' => null,
            'scopeBindingDigest' => ScopeBinding::scopeBindingDigest($scope->toWire(), $bindingDigest),
            'queryDigest' => str_repeat('1', 64), 'snapshotId' => self::id(10), 'generationId' => self::id(11),
            'candidateRefs' => [$this->chunk()],
            'limits' => new JsonObject(['profile' => 'compact', 'maxFragments' => 4, 'maxBytes' => 4096, 'tokenBudget' => null, 'maxBytesPerFragment' => 1024, 'maxAssets' => 0]),
            'policyBinding' => $this->policyBinding(), 'expiresAt' => $this->date()->modify('+30 seconds')->format('Y-m-d\TH:i:s.u\Z'),
        ]);

        return new D\Selection($wire, $this->validator);
    }

    public function citation(): D\CitationRef
    {
        $text = $this->evidenceText ?? self::corpus()['canonical'];
        $wire = Fixture::citation()->toWire()
            ->with('contentDigest', hash('sha256', $text))
            ->with('spans', [new JsonObject(['kind' => 'segment', 'segmentId' => 's1', 'blockId' => 'b1',
                'byteStart' => 0, 'byteEnd' => strlen($text), 'textDigest' => hash('sha256', $text)])]);

        return new D\CitationRef($wire, $this->validator);
    }

    public function chunk(): JsonObject
    {
        $citation = $this->citation()->toWire();
        $fields = [];
        foreach (['tenantId', 'sourceId', 'sourceVersionId', 'sourceDigest', 'documentRevisionId', 'irDigest'] as $key) {
            $fields[$key] = $citation->get($key);
        }

        return new JsonObject($fields + [
            'parserSignature' => new JsonObject(['name' => 'fixture', 'version' => '1', 'profileDigest' => str_repeat('2', 64)]),
            'chunkSetId' => self::id(12), 'chunkId' => self::id(13), 'chunkDigest' => $citation->get('contentDigest'),
        ]);
    }

    public function evidence(string $attempt): D\EvidenceSet
    {
        $scope = Fixture::scope();
        $selection = $this->selection();
        $usage = new D\Usage(new JsonObject([
            'usageId' => self::id(14), 'tenantId' => $scope->tenantId->value, 'disclosureAttemptId' => $attempt,
            'selectionId' => $selection->id()->value, 'actorId' => $scope->actorId->value, 'purpose' => $scope->purpose,
            'hostScopeHash' => $scope->hostScopeHash->value, 'runRef' => null,
            'fragmentRefs' => [new JsonObject(['chunkRef' => $this->chunk(), 'fragmentDigest' => $this->citation()->contentDigest()->value])],
            'checkedAt' => $this->now()->value, 'policyBindingDigest' => HashDomain::PolicyBinding->digest($this->policyBinding()),
            'status' => 'prepared_disclosure',
        ]), $this->validator);
        $text = $this->evidenceText ?? self::corpus()['canonical'];
        $wire = new JsonObject([
            'tenantId' => $scope->tenantId->value, 'selectionId' => $selection->id()->value, 'disclosureAttemptId' => $attempt,
            'usageId' => $usage->id()->value, 'hostScopeHash' => $scope->hostScopeHash->value,
            'scopeBindingDigest' => $selection->scopeBindingDigest()->value,
            'snapshotRef' => new JsonObject(['tenantId' => $scope->tenantId->value, 'kbId' => $scope->kbId->value,
                'snapshotId' => self::id(10), 'manifestDigest' => str_repeat('3', 64)]),
            'fragments' => [new JsonObject(['citation' => $this->citation()->toWire(), 'text' => $text, 'joiner' => null,
                'fragmentDigest' => hash('sha256', $text), 'provenanceKind' => 'verbatim'])],
            'totalBytes' => strlen($text),
        ]);

        if ($this->mutateEvidence !== null) {
            $wire = ($this->mutateEvidence)($wire);
        }

        return D\EvidenceSet::prepared($wire->with('evidenceSetDigest', HashDomain::EvidenceSet->digest($wire)), $this->validator, $usage);
    }

    public function policyBinding(): JsonObject
    {
        return new JsonObject(['tenantPolicyEpoch' => '1', 'kbPolicyEpoch' => '42', 'sources' => [new JsonObject([
            'sourceId' => Fixture::citation()->toWire()->get('sourceId'), 'sourcePolicyEpoch' => '1', 'lifecycleEpoch' => '1',
        ])]]);
    }

    public function beforeSend(D\Scope $scope, A\ModelCallIdentity $identity): ?A\AnswerOutcome
    {
        $this->events[] = 'authorize';
        Assert::assertEquals(Fixture::scope(), $scope);
        Assert::assertEquals($identity->binding->scope, $scope);
        if ($this->revoked) {
            return new A\AnswerOutcome(A\OutcomeCode::Denied, $identity->invocationRef, A\DispatchCertainty::KnownNotSent, reason: A\DenialReason::Revoked);
        }

        return null;
    }

    public function beforeReplay(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        $this->events[] = 'replay-authorize';
        if ($this->replayDenied || $this->revoked) {
            return new A\AnswerOutcome(A\OutcomeCode::Denied, $record->invocationRef, A\DispatchCertainty::KnownNotSent, reason: A\DenialReason::Revoked);
        }

        return null;
    }

    public function beforeResume(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        return $this->beforeReplay($scope, $record);
    }

    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        $this->events[] = 'egress';
        $wire = $request->toWire();
        Assert::assertEquals(Fixture::scope(), $scope);
        Assert::assertEquals(new JsonObject(['providerId' => $this->binding->provider, 'accountId' => $this->binding->account,
            'region' => $this->binding->region, 'modelId' => $this->binding->model]), $wire->get('destination'));
        $categories = array_map(static fn (JsonObject $item) => $item->get('category'), $wire->get('items'));
        Assert::assertContains('EVIDENCE', $categories);
        Assert::assertContains('QUESTION', $categories);
        Assert::assertContains('HISTORY', $categories);
        $decision = $this->egressDeny !== null && in_array($this->egressDeny, $categories, true) ? 'Deny' : $this->egressDecision;
        $source = new D\ResourceRef($scope->tenantId, 'source_version', new D\SourceVersionId($this->citation()->toWire()->get('sourceVersionId')));
        $receipt = new JsonObject([
            'decision' => $decision, 'operation' => 'model_egress_context', 'resourceRefs' => [$source->toWire()],
            'binding' => $this->policyBinding(), 'ownerRevision' => 'current:1', 'checkedAt' => $this->now()->value,
            'expiresAt' => $this->date()->modify('+4 seconds')->format('Y-m-d\TH:i:s.u\Z'),
            'hostScopeHash' => $scope->hostScopeHash->value,
        ]);

        return new D\DecisionReceipt($receipt->with('receiptDigest', HashDomain::DecisionReceipt->digest($receipt)), $this->validator);
    }

    public function reserve(A\InvocationKey $key, A\Estimate $estimate): A\BudgetReservation
    {
        $this->events[] = 'budget-reserve';
        $this->estimate = $estimate;
        if ($this->reserveThrows) {
            throw new \RuntimeException('Lost reserve ACK');
        }
        if ($this->afterReserve !== null) {
            ($this->afterReserve)();
        }

        return new A\BudgetReservation(new A\InvocationRef($estimate->scope->tenantId, $key), 'budget:stable', $this->reservationStatus);
    }

    public function commit(A\BudgetReservation $reservation, A\UsageObservation $usage): void
    {
        $this->events[] = 'budget-commit';
        $this->commits++;
        $this->settled = $usage;
        if ($this->settlementFails) {
            throw new \RuntimeException('Lost settlement ACK');
        }
    }

    public function release(A\BudgetReservation $reservation, A\ReleaseReason $reason): void
    {
        $this->events[] = 'budget-release';
        $this->releases++;
    }

    public function descriptor(): A\TransportDescriptor
    {
        return new A\TransportDescriptor('host-http', '1.0', !$this->testTransport);
    }

    public function send(A\TransportRequest $request, A\CancellationToken $cancellation): A\TransportResult
    {
        $this->events[] = 'send';
        $this->sends++;
        $this->outboundBytes += strlen($request->bytes);
        $this->lastRequest = $request;
        OpenAiRequest::requireExact($request);
        Assert::assertEquals($this->ledger->record->modelCallIdentityDigest, $request->identity->digest());
        Assert::assertSame('sending', $this->ledger->record->state->value);
        if ($this->onSend !== null) {
            ($this->onSend)();
        }
        if ($this->transportThrows) {
            throw new \RuntimeException('Provider may have accepted');
        }
        $usage = $this->noUsage ? new A\UsageObservation() : new A\UsageObservation(123, 17, 'provider:one');
        if ($this->unknownTransport) {
            return new A\TransportResult(A\DispatchCertainty::Unknown, null, false, $usage, $this->binding->model, true);
        }
        if ($this->incomplete) {
            return new A\TransportResult(A\DispatchCertainty::Sent, null, false, $usage, $this->binding->model, true);
        }
        $citation = $this->citation()->toWire();
        if ($this->mutateCitation !== null) {
            $citation = ($this->mutateCitation)($citation);
        }
        $output = Jcs::canonicalize(new JsonObject(['text' => self::corpus()['answer'], 'citations' => [$citation]]));

        return new A\TransportResult(A\DispatchCertainty::Sent, $this->outputText ?? $output, true, $usage, $this->observedModel ?? $this->binding->model, true);
    }

    public function put(D\Scope $scope, string $kind, string $bytes, A\DependencyManifest $dependencies, DateTimeImmutable $retention): A\InvocationPayloadRef
    {
        $this->events[] = "persist:{$kind}";
        if ($kind === $this->failPayloadKind) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_unavailable');
        }
        $dependencies->requireScope($scope);
        $ref = new A\InvocationPayloadRef($scope->tenantId, new D\ResourceId($this->next()->value), new D\Sha256(hash('sha256', $bytes)));
        $this->stored[$ref->resourceId->value] = [$bytes, $dependencies];
        $this->kinds[$ref->resourceId->value] = $kind;

        return $ref;
    }

    public function read(D\Scope $scope, A\InvocationPayloadRef $ref): string
    {
        $scope->assertTenant($ref->tenantId);
        $bytes = $this->stored[$ref->resourceId->value][0];
        Assert::assertSame($ref->digest->value, hash('sha256', $bytes));

        return $bytes;
    }

    public function dependencies(A\InvocationPayloadRef $ref): A\DependencyManifest
    {
        return $this->stored[$ref->resourceId->value][1];
    }
}

/** Deterministic state/fence spy. Real SQL fencing is qualified in the ledger suite. */
final class MemoryLedger implements P\InvocationLedger
{
    public ?A\InvocationRecord $record = null;
    public ?A\AnswerOutcome $outcome = null;
    public ?A\TransportRequest $request = null;

    public function __construct(
        private GroundingFixture $fixture,
    ) {
    }

    public function find(A\InvocationRef $key): ?A\InvocationRecord
    {
        $this->fixture->events[] = 'find';

        return $this->record;
    }

    public function begin(A\InvocationRef $key, D\Sha256 $fingerprint, A\InvocationPins $pins): A\InvocationRecord
    {
        $this->fixture->events[] = 'begin';
        $claim = new A\LedgerClaim($key, new D\Decimal('0'), 'grounding-fixture', $this->fixture->date()->modify('+120 seconds'), new D\Decimal('0'));
        $this->record = new A\InvocationRecord($key, $pins->originalRequestDigest, $fingerprint, $pins, A\InvocationState::Preparing, $claim);

        return $this->record;
    }

    public function reserve(A\LedgerClaim $claim, A\TransportRequest $request, A\BudgetReservation $budget): A\InvocationRecord
    {
        $this->fixture->events[] = 'ledger-reserve';
        $this->assertClaim($claim);
        Assert::assertSame(A\InvocationState::Preparing, $this->record->state);
        Assert::assertSame(A\ReservationStatus::Confirmed, $budget->status);
        $this->request = $request;
        $this->fixture->put($request->identity->binding->scope, 'request', $request->bytes, $request->identity->dependencies, $claim->leaseUntil);

        return $this->change(A\InvocationState::Reserved);
    }

    public function markSending(A\LedgerClaim $claim, A\ModelCallIdentity $identity): A\InvocationRecord
    {
        $this->fixture->events[] = 'markSending';
        $this->assertClaim($claim);
        Assert::assertSame(A\InvocationState::Reserved, $this->record->state);
        Assert::assertEquals($this->request->identity->digest(), $identity->digest());
        $identity->requireBytes($this->request->bytes);
        $record = $this->change(A\InvocationState::Sending);
        if ($this->fixture->afterMark !== null) {
            ($this->fixture->afterMark)();
        }

        return $record;
    }

    public function assertLive(A\LedgerClaim $claim): void
    {
        $this->fixture->events[] = 'live';
        $this->assertClaim($claim);
        Assert::assertSame(A\InvocationState::Sending, $this->record->state);
    }

    public function complete(A\LedgerClaim $claim, A\AnswerOutcome $outcome): A\InvocationRecord
    {
        $this->fixture->events[] = 'complete';
        $this->assertClaim($claim);
        Assert::assertNotSame(A\InvocationState::Completed, $this->record->state);
        Assert::assertNotSame(A\DispatchCertainty::Unknown, $outcome->certainty);
        $this->outcome = $outcome;
        $ref = $this->fixture->put($this->record->pins->binding->scope, 'response', Jcs::canonicalize($outcome->toWire()),
            $this->request?->identity->dependencies ?? new A\DependencyManifest(new D\Sha256(str_repeat('1', 64))), $claim->leaseUntil);

        return $this->change(A\InvocationState::Completed, $ref);
    }

    public function markUnknown(A\LedgerClaim $claim): A\InvocationRecord
    {
        $this->fixture->events[] = 'markUnknown';
        $this->assertClaim($claim);

        return $this->change(A\InvocationState::Unknown);
    }

    public function resume(A\LedgerClaim $claim): A\InvocationRecord
    {
        throw new \LogicException('The answer flow never resumes a model dispatch');
    }

    private function assertClaim(A\LedgerClaim $claim): void
    {
        if ($claim != $this->record->claim) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
        }
    }

    private function change(A\InvocationState $state, ?A\InvocationPayloadRef $response = null): A\InvocationRecord
    {
        $old = $this->record;
        $claim = new A\LedgerClaim($old->invocationRef, $old->claim->rowVersion->next(), $old->claim->leaseOwner, $old->claim->leaseUntil, $old->claim->fencingEpoch);
        $this->record = new A\InvocationRecord($old->invocationRef, $old->originalRequestDigest, $old->requestFingerprint, $old->pins, $state, $claim,
            $this->request?->identity->digest(), $response?->resourceId->value, $response?->digest,
            $this->request?->identity->dependencies, $this->outcome?->code,
            $state === A\InvocationState::Unknown ? A\DispatchCertainty::Unknown : ($this->outcome?->certainty ?? A\DispatchCertainty::KnownNotSent));

        return $this->record;
    }
}
