<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use DateTimeImmutable;
use KnowKit\Adapter\LocalBlob\LocalBlobStore;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Answer\Adapter\PostgresInvocationLedger;
use KnowKit\Answer\Adapter\PostgresInvocationPayloads;
use KnowKit\Answer\Application as App;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port as P;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Answer\Testing\ScriptedTransport;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\IdGenerator;
use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tests\Answer\Ledger\LedgerClock;
use KnowKit\Tests\Answer\Ledger\LedgerFixture;
use KnowKit\Tests\Answer\Ledger\PayloadSpy;
use KnowKit\Tests\Contract\Ports\PortFixture;

/** Synthetic host authority is independent of the immutable answer ledger. */
final class ReplayFixture implements P\InvocationAuthorization, P\ModelBindingResolver
{
    public readonly LedgerClock $clock;
    public readonly PayloadSpy $payloads;
    public readonly PostgresInvocationLedger $ledger;
    public readonly ScriptedTransport $transport;
    public readonly HostBudget $budget;
    public A\ResolvedModelBinding $binding;
    public int $resolutions = 0;
    public int $authorizations = 0;
    public bool $failResolution = false;
    public bool $authorizationThrows = false;
    public ?\Closure $onResolve = null;
    public ?\Closure $beforeSendCheck = null;
    private array $outboundRequests = [];

    public function __construct(
        public readonly Connection $db,
        public readonly string $blobRoot,
        public readonly D\Scope $scope,
        string $worker = 'conformance:parent',
        int $leaseSeconds = 120,
        bool $unknown = false,
    ) {
        $this->clock = new LedgerClock();
        $this->budget = new HostBudget();
        $this->payloads = $this->managedPayloads();
        $tx = new PdoCatalogTransaction($db);
        $this->ledger = new PostgresInvocationLedger($db, $tx, $this->payloads, $this->clock,
            $scope->tenantId, $worker, $leaseSeconds);
        $this->binding = Fixture::binding(['scope' => $scope, 'transport' => ScriptedTransport::testDescriptor()]);
        $this->transport = new ScriptedTransport(new EffectiveProfile(ProfileMode::Test, ScriptedTransport::testDescriptor()), [
            new A\TransportResult($unknown ? A\DispatchCertainty::Unknown : A\DispatchCertainty::Sent,
                $unknown ? null : self::corpus()['answer'], !$unknown, new A\UsageObservation(123, 17, 'script:one'),
                $this->binding->model, true),
        ]);
    }

    public static function corpus(): array
    {
        return json_decode((string) file_get_contents(Fixture::root() . '/tests/fixtures/answer/replay/v1/cases.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function run(callable $test, int $leaseSeconds = 120, bool $unknown = false): void
    {
        LedgerFixture::run(function (LedgerFixture $base) use ($test, $leaseSeconds, $unknown): void {
            $f = new self($base->blobs->catalog->db, $base->blobs->root, $base->scope(), leaseSeconds: $leaseSeconds, unknown: $unknown);
            $f->seedAuthority();
            $test($f, $base);
        });
    }

    public function seedAuthority(): void
    {
        $role = $this->db->pdo->query('SELECT current_user')->fetchColumn();
        $this->db->pdo->exec('RESET ROLE');
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS answer_test_authority (
              tenant_id text PRIMARY KEY, actor_allowed boolean NOT NULL DEFAULT true,
              audience_allowed boolean NOT NULL DEFAULT true, consent_allowed boolean NOT NULL DEFAULT true,
              history_allowed boolean NOT NULL DEFAULT true, egress_allowed boolean NOT NULL DEFAULT true,
              policy_known boolean NOT NULL DEFAULT true
            )
            SQL);
        $this->db->pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON answer_test_authority TO ' . $role);
        $this->db->pdo->exec('SET ROLE ' . $role);
        $this->db->execute('INSERT INTO answer_test_authority(tenant_id) VALUES (?)', [$this->scope->tenantId->value]);
        $this->db->execute(<<<'SQL'
            INSERT INTO resource_ledger
              (tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state)
            VALUES (?,?,?,'owned','fixture','fixture','1',?,1,'accepted')
            SQL, [$this->scope->tenantId->value, self::id(300), self::id(3), $this->citation()->toWire()->get('sourceDigest')]);
        $this->db->execute(<<<'SQL'
            INSERT INTO source_versions
              (tenant_id,id,source_id,blob_resource_id,source_digest,byte_length,media_type,rights_ref,idempotency_fingerprint,created_at)
            VALUES (?,?,?,?,?,1,'text/plain','synthetic:private',?,clock_timestamp())
            SQL, [$this->scope->tenantId->value, $this->citation()->toWire()->get('sourceVersionId'), self::id(3), self::id(300),
                $this->citation()->toWire()->get('sourceDigest'), str_repeat('2', 64)]);
        $this->db->execute("INSERT INTO source_version_state(tenant_id,id,usability) VALUES (?,?,'usable')",
            [$this->scope->tenantId->value, $this->citation()->toWire()->get('sourceVersionId')]);
    }

    private function managedPayloads(): PayloadSpy
    {
        $tx = new PdoCatalogTransaction($this->db);
        $resources = new PdoResourceLedger($this->db, $tx, $this->scope->tenantId, 'local-v1', $this->clock);
        $ids = new class implements IdGenerator {
            public function next(): D\Ulid
            {
                $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
                $value = '0';
                for ($i = 0; $i < 25; $i++) {
                    $value .= $alphabet[random_int(0, 31)];
                }

                return new D\Ulid($value);
            }
        };
        $callbacks = [];
        foreach (['inspectWriter', 'finalizeIdentity', 'closeWriter', 'markUncertain', 'requireAccepted',
            'listUnreferenced', 'quarantine', 'recoveryRef', 'reconcileWriter'] as $method) {
            $callbacks[$method] = $resources->{$method}(...);
        }
        // Blob custody is real; host disclosure authority is exercised separately at beforeReplay.
        $blobs = new LocalBlobStore($this->scope->tenantId, $this->blobRoot, 'local-v1', 1048576,
            $callbacks, static function (): void {}, $resources->deletionBinding(...), $ids, $this->clock, PortFixture::validator());

        return new PayloadSpy(new PostgresInvocationPayloads($this->db, $tx, $resources, $blobs, $ids, $this->clock,
            $this->scope->tenantId, new D\SourceId(self::id(2)), 'local-v1', PortFixture::validator()), $this->db);
    }

    public static function id(int $id): string
    {
        return \KnowKit\Tests\Storage\Blobs\BlobFixture::id($id);
    }

    public function request(string $key = 'replay:one'): A\AnswerRequest
    {
        $turns = new A\BoundedTurns([new A\PriorTurn(A\MessageRole::Assistant, self::corpus()['history'])]);
        $attestation = new A\HistoryReadAttestation($this->scope, $turns->digest(), 'history:turn',
            new DateTimeImmutable($this->clock->now()->plusSeconds(60)->value));

        return Fixture::request(['scope' => $this->scope, 'invocationKey' => new A\InvocationKey($key),
            'question' => self::corpus()['question'], 'priorTurns' => new A\BoundedTurns($turns->turns, $attestation)]);
    }

    public function resolve(D\Scope $scope, A\ModelBindingRef $ref): A\ResolvedModelBinding
    {
        if ($this->failResolution || $this->db->pdo->inTransaction()) {
            throw new \LogicException('Existing key must never resolve a mutable tutor');
        }
        $this->binding->requireScope($scope);
        $this->resolutions++;
        if ($this->onResolve !== null) {
            ($this->onResolve)();
        }

        return $this->binding;
    }

    public function admit(?A\AnswerRequest $request = null): A\InvocationRecord
    {
        return (new App\InvocationAdmission($this->ledger, $this))->admit($request ?? $this->request());
    }

    public function citation(): D\CitationRef
    {
        return new D\CitationRef(Fixture::citation()->toWire()->with('tenantId', $this->scope->tenantId->value)
            ->with('sourceId', self::id(3)), PortFixture::validator());
    }

    public function outbound(A\InvocationRecord $record): A\TransportRequest
    {
        $key = $record->invocationRef->key->value;
        if (isset($this->outboundRequests[$key])) {
            return $this->outboundRequests[$key];
        }
        $request = $this->request($record->invocationRef->key->value);
        $bytes = Jcs::canonicalize(new JsonObject(['model' => $record->pins->binding->model,
            'question' => $request->question, 'history' => self::corpus()['history']]));
        $now = new DateTimeImmutable($this->clock->now()->value);
        $history = new A\HistoryDependency($this->scope->tenantId, 'history:turn', '0',
            new D\Sha256(hash('sha256', self::corpus()['history'])));
        $identity = new A\ModelCallIdentity($record->invocationRef, $bytes, Fixture::profile(),
            [new A\MessageDigest(A\MessageRole::User, new D\Sha256(hash('sha256', $request->question)))],
            $record->pins->binding, new A\EgressDecisionRef('fixture:egress', '1', $now, $now->modify('+60 seconds')),
            new A\DependencyManifest(new D\Sha256(hash('sha256', $request->question)), [$this->citation()], [$history]),
            $now->modify('+60 seconds'));

        return $this->outboundRequests[$key] = new A\TransportRequest($identity, $bytes);
    }

    public function reserve(A\InvocationRecord $record): A\InvocationRecord
    {
        return $this->ledger->reserve($record->claim, $this->outbound($record),
            new A\BudgetReservation($record->invocationRef, 'budget:' . $record->invocationRef->key->value, A\ReservationStatus::Confirmed));
    }

    public function dispatch(A\InvocationRecord $record, bool $cancelled = false): A\TransportResult|A\AnswerOutcome
    {
        return (new App\InvocationDispatch($this->ledger, $this, $this->transport, $this->clock))
            ->send($record->claim, $this->outbound($record), Fixture::cancellation($cancelled));
    }

    public function finish(A\InvocationRecord $sending, A\TransportResult $result): A\InvocationRecord
    {
        if ($result->certainty !== A\DispatchCertainty::Sent || !$result->complete || $result->text === null
            || $result->observedModelRef !== $sending->pins->binding->model) {
            throw new \LogicException('Only the actual complete pinned-model reply can be captured');
        }
        $request = $this->outbound($sending);
        $settlement = new App\AnswerSettlement($this->payloads, $this->budget, $this->clock);
        $usageRef = $settlement->capture($request,
            new A\BudgetReservation($sending->invocationRef, 'budget:' . $sending->invocationRef->key->value, A\ReservationStatus::Confirmed),
            $result, $request->identity->egressDecision);
        $outcome = new A\AnswerOutcome(A\OutcomeCode::Answered, $sending->invocationRef, A\DispatchCertainty::Sent,
            $result->observedModelRef, $usageRef, fixture: $result->fixture, content: new A\AnsweredContent($result->text,
                [$this->citation()], $sending->pins->binding->bindingDigest, new D\Sha256(str_repeat('2', 64))));
        $record = $this->ledger->complete($sending->claim, $outcome);
        $settlement->settle($this->scope, $outcome);

        return $record;
    }

    public function completed(): A\InvocationRecord
    {
        $reserved = $this->reserve($this->admit());
        $result = $this->dispatch($reserved);
        if (!$result instanceof A\TransportResult) {
            throw new \LogicException('Fixture requires an actual transport result');
        }

        return $this->finish($this->ledger->find($reserved->invocationRef), $result);
    }

    /** @phpstan-impure */
    public function replay(?D\Scope $current = null): A\AnswerOutcome
    {
        return (new App\InvocationReplay($this->ledger, $this->payloads, $this, PortFixture::validator()))
            ->replay($this->request(), $current ?? $this->scope);
    }

    public function beforeSend(D\Scope $scope, A\ModelCallIdentity $identity): ?A\AnswerOutcome
    {
        if ($this->beforeSendCheck !== null) {
            ($this->beforeSendCheck)();
        }

        return $this->authorize($scope, $identity->invocationRef, $identity->dependencies);
    }

    public function beforeReplay(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        return $this->authorize($scope, $record->invocationRef, $record->dependencies);
    }

    public function beforeResume(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        return $this->beforeReplay($scope, $record);
    }

    private function authorize(D\Scope $scope, A\InvocationRef $ref, ?A\DependencyManifest $dependencies): ?A\AnswerOutcome
    {
        if ($this->authorizationThrows) {
            throw new \RuntimeException('Synthetic host policy unavailable');
        }
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Host policy I/O must be outside ledger transaction');
        }
        A\InvocationDecision::requireAudience($scope, $this->scope);
        $this->authorizations++;
        $tx = new PdoCatalogTransaction($this->db);
        $code = $tx->run(function () use ($tx, $scope, $dependencies): ?A\OutcomeCode {
            $tx->lockTenant($scope->tenantId, D\LockMode::Share);
            $tx->lockKb($scope->kbId, D\LockMode::Share);
            $tx->lockSources($dependencies?->sourceIds() ?? [], D\LockMode::Share);

            return $this->currentDecision($scope, $dependencies);
        });
        return $code === null ? null : new A\AnswerOutcome($code, $ref, A\DispatchCertainty::KnownNotSent,
            fixture: true, reason: $code === A\OutcomeCode::Denied ? A\DenialReason::Revoked : null);
    }

    private function currentDecision(D\Scope $scope, ?A\DependencyManifest $dependencies): ?A\OutcomeCode
    {
        $rights = $this->db->row('SELECT * FROM answer_test_authority WHERE tenant_id=?', [$scope->tenantId->value]);
        if ($rights === null || !$rights['policy_known']) {
            return A\OutcomeCode::PolicyUnknown;
        }
        foreach (['actor_allowed', 'audience_allowed', 'consent_allowed'] as $field) {
            if (!$rights[$field]) {
                return A\OutcomeCode::Denied;
            }
        }
        if (($dependencies->history ?? []) !== [] && !$rights['history_allowed']) {
            return A\OutcomeCode::Denied;
        }
        foreach ($dependencies->evidence ?? [] as $citation) {
            $row = $this->db->row(<<<'SQL'
                SELECT s.lifecycle_state,v.usability,
                  EXISTS (SELECT 1 FROM tombstones t WHERE t.tenant_id=s.tenant_id AND t.source_id=s.id) AS tombstoned
                FROM sources s JOIN source_versions r ON r.tenant_id=s.tenant_id AND r.source_id=s.id
                JOIN source_version_state v ON v.tenant_id=r.tenant_id AND v.id=r.id
                WHERE s.tenant_id=? AND s.id=? AND r.id=?
                SQL, [$scope->tenantId->value, $citation->toWire()->get('sourceId'), $citation->toWire()->get('sourceVersionId')]);
            if ($row === null || $row['lifecycle_state'] !== 'active' || $row['usability'] !== 'usable' || $row['tombstoned']) {
                return A\OutcomeCode::Denied;
            }
        }
        if (!$rights['egress_allowed']) {
            return A\OutcomeCode::EgressDenied;
        }

        return null;
    }

    public function row(): array
    {
        return $this->db->row('SELECT * FROM knowkit_answer_invocations WHERE tenant_id=? AND invocation_key=?',
            [$this->scope->tenantId->value, $this->request()->invocationKey->value]);
    }

    public function deny(string $field): void
    {
        $this->right($field, false);
    }

    public function allow(string $field): void
    {
        $this->right($field, true);
    }

    private function right(string $field, bool $allowed): void
    {
        if (!in_array($field, ['actor_allowed', 'audience_allowed', 'consent_allowed', 'history_allowed', 'egress_allowed', 'policy_known'], true)) {
            throw new \InvalidArgumentException('Unknown fixture right');
        }
        $value = $allowed ? 'true' : 'false';
        $this->db->execute("UPDATE answer_test_authority SET {$field}={$value} WHERE tenant_id=?", [$this->scope->tenantId->value]);
    }
}
