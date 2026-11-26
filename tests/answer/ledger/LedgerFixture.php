<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Ledger;

use DateTimeImmutable;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Answer\Adapter\PostgresAnswerMigrations;
use KnowKit\Answer\Adapter\PostgresInvocationLedger;
use KnowKit\Answer\Adapter\PostgresInvocationPayloads;
use KnowKit\Answer\Application\InvocationAdmission;
use KnowKit\Answer\Application\InvocationDispatch;
use KnowKit\Answer\Application\InvocationReplay;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port as P;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Clock;
use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use PDO;

final class LedgerClock implements Clock
{
    public int $offset = 0;
    public ?DateTimeImmutable $fixed = null;

    public function now(): D\UtcInstant
    {
        $now = $this->fixed ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new D\UtcInstant($now->modify("+{$this->offset} seconds")->format('Y-m-d\TH:i:s.u\Z'));
    }
}

final class PayloadSpy implements P\InvocationPayloads
{
    public int $responseReads = 0;
    public int $reads = 0;
    public ?\Closure $afterPins = null;
    public ?\Closure $afterResponseRead = null;

    public function __construct(
        public readonly PostgresInvocationPayloads $inner,
        private readonly Connection $db,
    ) {
    }

    public function put(D\Scope $scope, string $kind, string $bytes, A\DependencyManifest $dependencies, DateTimeImmutable $retention): A\InvocationPayloadRef
    {
        $ref = $this->inner->put($scope, $kind, $bytes, $dependencies, $retention);
        if ($kind === 'pins' && $this->afterPins !== null) {
            $callback = $this->afterPins;
            $this->afterPins = null;
            $callback();
        }

        return $ref;
    }

    public function read(D\Scope $scope, A\InvocationPayloadRef $ref): string
    {
        $this->reads++;
        $kind = $this->db->row('SELECT kind FROM knowkit_answer_payloads WHERE tenant_id=? AND resource_id=?', [$ref->tenantId->value, $ref->resourceId->value]);
        if (($kind['kind'] ?? null) === 'response') {
            $this->responseReads++;
        }

        $bytes = $this->inner->read($scope, $ref);
        if (($kind['kind'] ?? null) === 'response' && $this->afterResponseRead !== null) {
            ($this->afterResponseRead)();
        }

        return $bytes;
    }

    public function dependencies(A\InvocationPayloadRef $ref): A\DependencyManifest
    {
        return $this->inner->dependencies($ref);
    }
}

final class BindingResolver implements P\ModelBindingResolver
{
    public int $calls = 0;
    public bool $fail = false;

    public function __construct(
        public A\ResolvedModelBinding $binding,
        private readonly Connection $db,
    ) {
    }

    public function resolve(D\Scope $scope, A\ModelBindingRef $ref): A\ResolvedModelBinding
    {
        if ($this->db->pdo->inTransaction() || $this->fail) {
            throw new \LogicException('Mutable resolution forbidden here');
        }
        $this->calls++;

        return $this->binding;
    }
}

final class Authority implements P\InvocationAuthorization
{
    public int $replays = 0;
    public int $resumes = 0;
    public int $sends = 0;
    public bool $historyVisible = true;
    public bool $egressAllowed = true;
    public bool $credentialsValid = true;
    public ?\Closure $duringSend = null;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function beforeSend(D\Scope $scope, A\ModelCallIdentity $identity): ?A\AnswerOutcome
    {
        $this->outsideTransaction();
        $row = $this->db->row('SELECT state,model_call_identity_hash FROM knowkit_answer_invocations WHERE tenant_id=? AND invocation_key=?',
            [$scope->tenantId->value, $identity->invocationRef->key->value]);
        if ($row['state'] !== 'sending' || $row['model_call_identity_hash'] !== $identity->digest()->value) {
            throw new \LogicException('Authorization must follow durable exact intent');
        }
        $this->sends++;
        if ($this->duringSend !== null) {
            ($this->duringSend)();
        }

        return $this->check($identity->invocationRef, $identity->dependencies);
    }

    public function beforeReplay(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        $this->outsideTransaction();
        $this->replays++;

        return $this->check($record->invocationRef, $record->dependencies);
    }

    public function beforeResume(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        $this->outsideTransaction();
        $this->resumes++;

        return $this->check($record->invocationRef, $record->dependencies);
    }

    private function check(A\InvocationRef $ref, ?A\DependencyManifest $dependencies): ?A\AnswerOutcome
    {
        if (!$this->egressAllowed) {
            return new A\AnswerOutcome(A\OutcomeCode::EgressDenied, $ref, A\DispatchCertainty::KnownNotSent);
        }
        $denied = !$this->historyVisible || !$this->credentialsValid;
        foreach ($dependencies?->sourceIds() ?? [] as $source) {
            $row = $this->db->row('SELECT lifecycle_state FROM sources WHERE tenant_id=? AND id=?', [$ref->tenantId->value, $source->value]);
            $denied = $denied || ($row['lifecycle_state'] ?? null) !== 'active';
        }
        if ($denied) {
            return new A\AnswerOutcome(A\OutcomeCode::Denied, $ref, A\DispatchCertainty::KnownNotSent, reason: A\DenialReason::Revoked);
        }

        return null;
    }

    private function outsideTransaction(): void
    {
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('External guard inside SQL');
        }
    }
}

final class TransportSpy implements P\ModelTransport
{
    public int $sends = 0;
    public bool $uncertain = false;
    public bool $throws = false;
    public ?\Closure $onSend = null;

    public function descriptor(): A\TransportDescriptor
    {
        return new A\TransportDescriptor('host-http', '1.0', true);
    }

    public function send(A\TransportRequest $request, A\CancellationToken $cancellation): A\TransportResult
    {
        if ($this->onSend !== null) {
            ($this->onSend)();
        }
        $this->sends++;
        if ($this->throws) {
            throw new \RuntimeException('Synthetic lost reply');
        }
        if ($this->uncertain) {
            return new A\TransportResult(A\DispatchCertainty::Unknown, null, false, new A\UsageObservation(), fixture: true);
        }

        return new A\TransportResult(A\DispatchCertainty::Sent, 'Synthetic protected response', true, new A\UsageObservation(), fixture: true);
    }
}

final class LedgerFixture
{
    public readonly LedgerClock $clock;
    public readonly PayloadSpy $payloads;
    public readonly PostgresInvocationLedger $ledger;
    public readonly BindingResolver $resolver;
    public readonly Authority $authority;
    public readonly TransportSpy $transport;

    public function __construct(
        public readonly BlobFixture $blobs,
        int $leaseSeconds = 120,
    ) {
        $c = $blobs->catalog;
        $this->clock = new LedgerClock();
        $this->payloads = $this->makePayloads($c->db);
        $this->ledger = $this->makeLedger('worker:a', $leaseSeconds);
        $this->resolver = new BindingResolver($this->binding(), $c->db);
        $this->authority = new Authority($c->db);
        $this->transport = new TransportSpy();
    }

    public static function run(callable $test, int $leaseSeconds = 120, ?\Closure $sync = null): void
    {
        BlobFixture::run(function (BlobFixture $blobs) use ($test, $leaseSeconds): void {
            $c = $blobs->catalog;
            $pdo = $c->db->pdo;
            $role = $pdo->query('SELECT current_user')->fetchColumn();
            $pdo->exec('RESET ROLE');
            (new PostgresAnswerMigrations($pdo, $c->db->schema))->apply();
            $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $c->db->schema . ' TO ' . $role);
            $pdo->exec('SET ROLE ' . $role);
            $c->source(3);
            $test(new self($blobs, $leaseSeconds));
        }, $sync);
    }

    public function scope(): D\Scope
    {
        return $this->blobs->catalog->scope();
    }

    public function request(array $changes = []): A\AnswerRequest
    {
        return Fixture::request(array_replace(['scope' => $this->scope()], $changes));
    }

    public function binding(array $changes = []): A\ResolvedModelBinding
    {
        return Fixture::binding(array_replace(['scope' => $this->scope()], $changes));
    }

    public function citation(): D\CitationRef
    {
        $wire = Fixture::citation()->toWire()->with('tenantId', $this->scope()->tenantId->value)
            ->with('sourceId', BlobFixture::id(3));

        return new D\CitationRef($wire, PortFixture::validator());
    }

    public function requestBytes(?A\ResolvedModelBinding $binding = null): A\TransportRequest
    {
        $request = $this->request();
        $binding ??= $this->binding();
        $bytes = Jcs::canonicalize(new JsonObject(['model' => $binding->model, 'question' => $request->question,
            'history' => array_map(static fn (A\PriorTurn $turn) => $turn->toWire(), $request->priorTurns->turns)]));
        $now = new DateTimeImmutable($this->clock->now()->value);
        $identity = new A\ModelCallIdentity(
            $request->invocationRef(), $bytes, Fixture::profile(),
            [new A\MessageDigest(A\MessageRole::User, new D\Sha256(hash('sha256', $request->question)))],
            $binding, new A\EgressDecisionRef('egress:ledger', '1', $now, $now->modify('+60 seconds')),
            new A\DependencyManifest(new D\Sha256(hash('sha256', $request->question)), [$this->citation()], [
                new A\HistoryDependency($request->scope->tenantId, 'history:turn', '1', new D\Sha256(str_repeat('d', 64))),
            ]), $now->modify('+60 seconds'),
        );

        return new A\TransportRequest($identity, $bytes);
    }

    public function admit(): A\InvocationRecord
    {
        return (new InvocationAdmission($this->ledger, $this->resolver))->admit($this->request());
    }

    public function reserve(): A\InvocationRecord
    {
        $record = $this->admit();

        return $this->ledger->reserve($record->claim, $this->requestBytes(),
            new A\BudgetReservation($record->invocationRef, 'budget:001', A\ReservationStatus::Confirmed));
    }

    public function outcome(): A\AnswerOutcome
    {
        return new A\AnswerOutcome(A\OutcomeCode::Answered, $this->request()->invocationRef(), A\DispatchCertainty::Sent,
            'observed:model', 'usage:001', true,
            new A\AnsweredContent('Synthetic protected response', [$this->citation()], $this->binding()->bindingDigest, new D\Sha256(str_repeat('2', 64))));
    }

    public function finish(): A\InvocationRecord
    {
        $reserved = $this->reserve();
        $this->dispatcher()->send($reserved->claim, $this->requestBytes(), Fixture::cancellation());
        $sending = $this->ledger->find($reserved->invocationRef);

        return $this->ledger->complete($sending->claim, $this->outcome());
    }

    public function dispatcher(?PostgresInvocationLedger $ledger = null): InvocationDispatch
    {
        return new InvocationDispatch($ledger ?? $this->ledger, $this->authority, $this->transport, $this->clock);
    }

    public function replay(): A\AnswerOutcome
    {
        return (new InvocationReplay($this->ledger, $this->payloads, $this->authority, PortFixture::validator()))->replay($this->request(), $this->scope());
    }

    public function makeLedger(string $worker, int $seconds = 120, ?Connection $db = null): PostgresInvocationLedger
    {
        $db ??= $this->blobs->catalog->db;

        return new PostgresInvocationLedger($db, new PdoCatalogTransaction($db), $this->payloads,
            $this->clock, $this->scope()->tenantId, $worker, $seconds);
    }

    public function makePayloads(Connection $db): PayloadSpy
    {
        $c = $this->blobs->catalog;
        $tx = new PdoCatalogTransaction($db);
        $resources = new PdoResourceLedger($db, $tx, $this->scope()->tenantId, 'local-v1', $this->clock);

        return new PayloadSpy(new PostgresInvocationPayloads($db, $tx, $resources, $this->blobs->store,
            $c->ids, $this->clock, $this->scope()->tenantId, new D\SourceId(BlobFixture::id(2)), 'local-v1', PortFixture::validator()), $db);
    }

    public function secondConnection(): Connection
    {
        $role = $this->blobs->catalog->db->pdo->query('SELECT current_user')->fetchColumn();
        $pdo = new PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
            getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null);
        $pdo->exec('SET ROLE ' . $role);
        $pdo->exec('SET search_path TO ' . $this->blobs->catalog->db->schema);

        return new Connection($pdo, $this->blobs->catalog->db->schema);
    }

    public function row(): array
    {
        return $this->blobs->catalog->db->row('SELECT * FROM knowkit_answer_invocations WHERE tenant_id=? AND invocation_key=?',
            [$this->scope()->tenantId->value, $this->request()->invocationKey->value]);
    }

    public function expireLease(): void
    {
        // Authoritative database clock. Do not rewrite lease_until.
        $deadline = microtime(true) + 3;
        $statement = $this->blobs->catalog->db->pdo->prepare(
            "SELECT CASE WHEN lease_until<=clock_timestamp() THEN 'yes' ELSE 'no' END
             FROM knowkit_answer_invocations WHERE tenant_id=? AND invocation_key=?",
        );
        $tenant = $this->scope()->tenantId->value;
        $key = $this->request()->invocationKey->value;
        do {
            $statement->execute([$tenant, $key]);
            $expired = $statement->fetchColumn();
            $statement->closeCursor();
            if ($expired === 'yes') {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Database lease did not expire');
            }
            usleep(10000);
        } while (true);
    }
}
