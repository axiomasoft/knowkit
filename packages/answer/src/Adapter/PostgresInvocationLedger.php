<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter;

use DateTimeImmutable;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Answer\Port\InvocationPayloads;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\Clock;

/** Direct-answer ledger only, adapted from the AgenTools fenced journal boundary.
 * No provider/blob/policy I/O occurs inside these short READ COMMITTED transactions.
 */
final readonly class PostgresInvocationLedger implements InvocationLedger
{
    private const INSERT_SQL = <<<'SQL'
        INSERT INTO %s
          (tenant_id,invocation_key,kb_id,actor_id,business_purpose,policy_epoch,host_scope_hash,
           original_request_digest,request_fingerprint,binding_snapshot_ref,binding_snapshot_hash,
           binding_digest,profile_ref,profile_revision,profile_digest,state,row_version,
           lease_owner,lease_until,fencing_epoch,dispatch_certainty,retention_deadline,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'preparing',0,?,
                clock_timestamp()+(? * interval '1 second'),0,'knownNotSent',?,clock_timestamp(),clock_timestamp())
        ON CONFLICT (tenant_id,invocation_key) DO NOTHING
        SQL;

    private const CAS_SQL = <<<'SQL'
        UPDATE %s SET %s,row_version=row_version+1,updated_at=clock_timestamp()
        WHERE tenant_id=? AND invocation_key=? AND row_version=? AND lease_owner=?
          AND fencing_epoch=? AND lease_until>clock_timestamp()
        RETURNING *
        SQL;

    private const RESUME_SQL = <<<'SQL'
        UPDATE %s SET
          state=CASE WHEN state='sending' THEN 'unknown' ELSE state END,
          outcome_code=CASE WHEN state='sending' THEN 'TransportUnknown' ELSE outcome_code END,
          dispatch_certainty=CASE WHEN state='sending' THEN 'unknown' ELSE dispatch_certainty END,
          lease_owner=?,lease_until=clock_timestamp()+(? * interval '1 second'),
          fencing_epoch=fencing_epoch+1,row_version=row_version+1,updated_at=clock_timestamp()
        WHERE tenant_id=? AND invocation_key=? AND row_version=? AND lease_owner=?
          AND fencing_epoch=? AND lease_until<=clock_timestamp() AND state IN ('preparing','reserved','sending')
        RETURNING *
        SQL;

    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private InvocationPayloads $payloads,
        private Clock $clock,
        private D\TenantId $tenant,
        private string $worker,
        private int $leaseSeconds = 120,
        private int $retentionSeconds = 86400,
    ) {
        A\Guard::reference($worker);
        if ($db !== $tx->db || $leaseSeconds < 1 || $leaseSeconds > 3600 || $retentionSeconds < 1) {
            throw new \InvalidArgumentException('Bounded lease and shared transaction required');
        }
    }

    public function find(A\InvocationRef $key): ?A\InvocationRecord
    {
        $this->outsideTransaction();
        $row = $this->row($key);

        return $row === null ? null : $this->record($row);
    }

    public function begin(A\InvocationRef $key, D\Sha256 $fingerprint, A\InvocationPins $pins): A\InvocationRecord
    {
        $this->outsideTransaction();
        $this->scoped($key);
        $scope = $pins->binding->scope;
        $scope->assertTenant($this->tenant);
        $existing = $this->find($key);
        if ($existing !== null) {
            return $this->winner($existing, $pins);
        }
        $retention = new DateTimeImmutable($this->clock->now()->plusSeconds($this->retentionSeconds)->value);
        $snapshot = $this->payloads->put($scope, 'pins', Jcs::canonicalize($pins->toWire()),
            new A\DependencyManifest($pins->questionDigest ?? new D\Sha256(hash('sha256', ''))), $retention);
        $row = $this->tx->run(function () use ($key, $fingerprint, $pins, $scope, $snapshot, $retention): array {
            $this->guards($scope);
            $this->db->execute(sprintf(self::INSERT_SQL, $this->table()), [
                $this->tenant->value, $key->key->value, $scope->kbId->value, $scope->actorId->value,
                $scope->purpose, $scope->policyEpoch->value, $scope->hostScopeHash->value,
                $pins->originalRequestDigest->value, $fingerprint->value, $snapshot->resourceId->value,
                $snapshot->digest->value, $pins->binding->bindingDigest->value, $pins->retrievalProfile->id,
                $pins->retrievalProfile->revision, $pins->retrievalProfile->digest->value,
                $this->worker, $this->leaseSeconds, $retention->format('c'),
            ]);

            return $this->requireRow($key);
        });

        return $this->winner($this->record($row), $pins);
    }

    private function winner(A\InvocationRecord $winner, A\InvocationPins $candidate): A\InvocationRecord
    {
        // A concurrent resolution is disposable. Only the original request decides adoption.
        if (!$winner->originalRequestDigest->equals($candidate->originalRequestDigest)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invocation_conflict');
        }

        return $winner;
    }

    public function reserve(A\LedgerClaim $claim, A\TransportRequest $request, A\BudgetReservation $budget): A\InvocationRecord
    {
        $record = $this->claimed($claim, [A\InvocationState::Preparing]);
        $identity = $request->identity;
        $this->requireIdentity($record, $identity);
        if (!$budget->invocationRef->equals($claim->invocationRef) || $budget->status !== A\ReservationStatus::Confirmed) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'reservation_mismatch');
        }
        $row = $this->requireRow($claim->invocationRef);
        $payload = $this->payloads->put($record->pins->binding->scope, 'request', $request->bytes,
            $identity->dependencies, new DateTimeImmutable($row['retention_deadline']));

        return $this->transition($claim, [A\InvocationState::Preparing], [
            'state' => 'reserved', 'request_payload_ref' => $payload->resourceId->value,
            'request_payload_hash' => $payload->digest->value, 'budget_key' => $claim->invocationRef->key->value,
            'reservation_ref' => $budget->ref, 'reservation_status' => $budget->status->value,
        ]);
    }

    public function markSending(A\LedgerClaim $claim, A\ModelCallIdentity $identity): A\InvocationRecord
    {
        $record = $this->claimed($claim, [A\InvocationState::Reserved]);
        $this->requireIdentity($record, $identity);
        $row = $this->requireRow($claim->invocationRef);
        if ($row['request_payload_hash'] !== $identity->requestBytesDigest->value
            || $record->dependencies === null || !$record->dependencies->equals($identity->dependencies)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'outbound_identity_conflict');
        }
        $call = $this->payloads->put($record->pins->binding->scope, 'call', Jcs::canonicalize($identity->toWire()),
            $identity->dependencies, new DateTimeImmutable($row['retention_deadline']));

        return $this->transition($claim, [A\InvocationState::Reserved], [
            'state' => 'sending', 'model_call_identity_ref' => $call->resourceId->value,
            'model_call_identity_hash' => $call->digest->value, 'egress_manifest_ref' => $call->resourceId->value,
            'egress_manifest_hash' => $call->digest->value, 'dispatch_certainty' => 'unknown',
        ]);
    }

    private function requireIdentity(A\InvocationRecord $record, A\ModelCallIdentity $identity): void
    {
        if (!$identity->invocationRef->equals($record->invocationRef) || !$identity->binding->equals($record->pins->binding)
            || ($record->pins->questionDigest !== null && !$record->pins->questionDigest->equals($identity->dependencies->questionDigest))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'outbound_identity_conflict');
        }
    }

    public function complete(A\LedgerClaim $claim, A\AnswerOutcome $outcome): A\InvocationRecord
    {
        $record = $this->claimed($claim, [A\InvocationState::Preparing, A\InvocationState::Reserved, A\InvocationState::Sending]);
        $this->requireOutcome($record, $outcome);
        $row = $this->requireRow($claim->invocationRef);
        $dependencies = $record->dependencies ?? new A\DependencyManifest($record->pins->questionDigest ?? new D\Sha256(hash('sha256', '')));
        $response = $this->payloads->put($record->pins->binding->scope, 'response', Jcs::canonicalize($outcome->toWire()),
            $dependencies, new DateTimeImmutable($row['retention_deadline']));

        return $this->transition($claim, [$record->state], [
            'state' => 'completed', 'response_payload_ref' => $response->resourceId->value,
            'response_payload_hash' => $response->digest->value, 'outcome_code' => $outcome->code->value,
            'dispatch_certainty' => $outcome->certainty->value, 'observed_model_ref' => $outcome->observedModelRef,
            'usage_ref' => $outcome->usageRef, 'evidence_set_id' => $outcome->answeredContent()?->evidenceSetId->value,
        ]);
    }

    private function requireOutcome(A\InvocationRecord $record, A\AnswerOutcome $outcome): void
    {
        if (!$outcome->invocationRef->equals($record->invocationRef) || $outcome->certainty === A\DispatchCertainty::Unknown
            || ($record->state !== A\InvocationState::Sending && $outcome->certainty !== A\DispatchCertainty::KnownNotSent)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'outcome_conflict');
        }
        $content = $outcome->answeredContent();
        if ($content === null) {
            return;
        }
        if (!$content->modelBindingDigest->equals($record->pins->binding->bindingDigest)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'outcome_conflict');
        }
        $evidence = array_map(
            static fn (D\CitationRef $ref): string => $ref->canonical(),
            $record->dependencies === null ? [] : $record->dependencies->evidence,
        );
        foreach ($content->citations as $citation) {
            if (!in_array($citation->canonical(), $evidence, true)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'outcome_conflict');
            }
        }
    }

    public function markUnknown(A\LedgerClaim $claim): A\InvocationRecord
    {
        return $this->transition($claim, [A\InvocationState::Sending], [
            'state' => 'unknown', 'dispatch_certainty' => 'unknown', 'outcome_code' => 'TransportUnknown',
        ]);
    }

    public function resume(A\LedgerClaim $claim): A\InvocationRecord
    {
        $this->outsideTransaction();
        $this->scoped($claim->invocationRef);
        $row = $this->row($claim->invocationRef);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
        }
        $updated = $this->tx->run(function () use ($claim, $row): array {
            $this->guards($this->scope($row));
            $updated = $this->db->row(sprintf(self::RESUME_SQL, $this->table()), [
                $this->worker, $this->leaseSeconds, ...$this->claimValues($claim),
            ]);
            if ($updated === null) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
            }

            return $updated;
        });

        return $this->record($updated);
    }

    public function assertLive(A\LedgerClaim $claim): void
    {
        $this->outsideTransaction();
        $this->scoped($claim->invocationRef);
        $row = $this->db->row('SELECT state FROM ' . $this->table()
            . ' WHERE tenant_id=? AND invocation_key=? AND row_version=? AND lease_owner=? AND fencing_epoch=? AND lease_until>clock_timestamp()',
            $this->claimValues($claim));
        if ($claim->leaseOwner !== $this->worker || ($row['state'] ?? null) !== 'sending') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
        }
    }

    private function claimed(A\LedgerClaim $claim, array $states): A\InvocationRecord
    {
        $this->outsideTransaction();
        $this->scoped($claim->invocationRef);
        if ($claim->leaseOwner !== $this->worker) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
        }
        $row = $this->db->row('SELECT * FROM ' . $this->table()
            . ' WHERE tenant_id=? AND invocation_key=? AND row_version=? AND lease_owner=? AND fencing_epoch=? AND lease_until>clock_timestamp()',
            $this->claimValues($claim));
        if ($row === null || !in_array(A\InvocationState::from($row['state']), $states, true)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
        }

        return $this->record($row);
    }

    private function transition(A\LedgerClaim $claim, array $states, array $changes): A\InvocationRecord
    {
        $current = $this->claimed($claim, $states);
        $row = $this->tx->run(function () use ($claim, $current, $states, $changes): array {
            $this->guards($current->pins->binding->scope, $current->dependencies);
            $saved = $this->requireRow($claim->invocationRef);
            if (!in_array(A\InvocationState::from($saved['state']), $states, true)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
            }
            $assignments = implode(',', array_map(static fn (string $field): string => $field . '=?', array_keys($changes)));
            $updated = $this->db->row(sprintf(self::CAS_SQL, $this->table(), $assignments), [
                ...array_values($changes), ...$this->claimValues($claim),
            ]);
            if ($updated === null) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
            }

            return $updated;
        });

        return $this->record($row);
    }

    private function guards(D\Scope $scope, ?A\DependencyManifest $dependencies = null): void
    {
        $this->tx->lockTenant($this->tenant, D\LockMode::Update);
        $this->tx->lockKb($scope->kbId, D\LockMode::Share);
        $this->tx->lockSources($dependencies?->sourceIds() ?? [], D\LockMode::Share);
    }

    private function row(A\InvocationRef $ref): ?array
    {
        $this->scoped($ref);

        return $this->db->row('SELECT * FROM ' . $this->table() . ' WHERE tenant_id=? AND invocation_key=?', [$this->tenant->value, $ref->key->value]);
    }

    private function requireRow(A\InvocationRef $ref): array
    {
        return $this->row($ref) ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_claim');
    }

    private function record(array $row): A\InvocationRecord
    {
        $ref = new A\InvocationRef($this->tenant, new A\InvocationKey($row['invocation_key']));
        $expectedScope = $this->scope($row);
        $pins = A\InvocationPins::fromWire(StrictJson::decode($this->payloads->read($expectedScope,
            $this->payloadRef($row['binding_snapshot_ref'], $row['binding_snapshot_hash']))));
        if (Jcs::canonicalize($pins->binding->scope->toWire()) !== Jcs::canonicalize($expectedScope->toWire())
            || $pins->binding->bindingDigest->value !== $row['binding_digest']
            || !$pins->retrievalProfile->equals(new A\ProfileRef($row['profile_ref'], $row['profile_revision'], new D\Sha256($row['profile_digest'])))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }

        return new A\InvocationRecord(
            $ref, new D\Sha256($row['original_request_digest']), new D\Sha256($row['request_fingerprint']), $pins,
            A\InvocationState::from($row['state']),
            new A\LedgerClaim($ref, new D\Decimal((string) $row['row_version']), $row['lease_owner'],
                new DateTimeImmutable($row['lease_until']), new D\Decimal((string) $row['fencing_epoch'])),
            $row['model_call_identity_hash'] === null ? null : new D\Sha256($row['model_call_identity_hash']),
            $row['response_payload_ref'],
            $row['response_payload_hash'] === null ? null : new D\Sha256($row['response_payload_hash']),
            $row['request_payload_ref'] === null ? null : $this->payloads->dependencies($this->payloadRef($row['request_payload_ref'], $row['request_payload_hash'])),
            $row['outcome_code'] === null ? null : A\OutcomeCode::from($row['outcome_code']),
            A\DispatchCertainty::from($row['dispatch_certainty']),
        );
    }

    private function payloadRef(string $id, string $digest): A\InvocationPayloadRef
    {
        return new A\InvocationPayloadRef($this->tenant, new D\ResourceId($id), new D\Sha256($digest));
    }

    private function scope(array $row): D\Scope
    {
        return new D\Scope($this->tenant, new D\KbId($row['kb_id']), new D\ActorId($row['actor_id']),
            $row['business_purpose'], new D\Decimal((string) $row['policy_epoch']), new D\Sha256($row['host_scope_hash']));
    }

    private function claimValues(A\LedgerClaim $claim): array
    {
        return [$this->tenant->value, $claim->invocationRef->key->value, $claim->rowVersion->value, $claim->leaseOwner, $claim->fencingEpoch->value];
    }

    private function scoped(A\InvocationRef $ref): void
    {
        if (!$ref->tenantId->equals($this->tenant)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
    }

    private function table(): string
    {
        return $this->db->table('knowkit_answer_invocations');
    }

    private function outsideTransaction(): void
    {
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Ledger owns short SQL transactions');
        }
    }
}
