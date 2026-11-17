<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Navigation;

use Closure;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Navigation as N;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\NavigationStore;

/** Fresh disclosure protocol reused for exact revision/unit reads, with no fabricated Selection. */
final readonly class PdoNavigationStore implements NavigationStore
{
    private const REVISION_SQL = <<<'SQL'
        SELECT r.*,v.source_id,v.source_digest,b.state AS object_state,b.digest AS object_digest
        FROM %s r JOIN %s v ON v.tenant_id=r.tenant_id AND v.id=r.source_version_id
        JOIN %s b ON b.tenant_id=r.tenant_id AND b.id=r.ir_resource_id AND b.source_id=v.source_id
        WHERE r.tenant_id=? AND r.id=?
        SQL;

    private const UNITS_SQL = <<<'SQL'
        SELECT u.local_%s_id AS local_id,u.text_digest,u.status,b.state,b.digest
        FROM %s u JOIN %s b ON b.tenant_id=u.tenant_id AND b.id=u.text_resource_id
        WHERE u.tenant_id=? AND u.document_id=? AND b.source_id=?
            AND u.local_%s_id IN (SELECT jsonb_array_elements_text(?::jsonb))
        SQL;

    private const INSERT_SQL = <<<'SQL'
        INSERT INTO %s (tenant_id,id,disclosure_attempt_id,kb_id,actor_id,purpose,host_scope_hash,
            source_id,source_version_id,revision_id,ir_digest,document_id,policy_binding_digest,
            prepared_at,payload_digest,reference_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb)
        ON CONFLICT (tenant_id,disclosure_attempt_id) DO NOTHING
        SQL;

    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private PdoUsages $usages,
        private ContractValidator $validator,
        private IdGenerator $ids,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    public function plan(D\Scope $scope, D\DocumentRevisionRef $revision): N\NavigationPlan
    {
        $scope->assertTenant($revision->sourceRef->tenantId);
        $row = $this->requireRevision($scope, $revision);
        $documents = $this->db->execute('SELECT id FROM ' . $this->db->table('documents')
            . ' WHERE tenant_id=? AND revision_id=? ORDER BY id LIMIT 2',
            [$scope->tenantId->value, $revision->documentRevisionId->value])->fetchAll(\PDO::FETCH_COLUMN);
        if (count($documents) !== 1) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'single_document_required');
        }

        return new N\NavigationPlan(new D\DocumentId($documents[0]), $this->usages->revisionPlan($scope, $revision),
            new D\FrozenJson(StrictJson::decode($row['coverage_json'])));
    }

    /**
     * @param list<N\SourceUnitRef> $units
     * @param Closure(N\NavigationPlan, D\UtcInstant): D\DisclosureDeadline $authorize
     */
    public function check(D\Scope $scope, D\DocumentRevisionRef $revision, N\NavigationPlan $plan,
        array $units, Closure $authorize): void
    {
        $this->tx->run(function () use ($scope, $revision, $plan, $units, $authorize): void {
            $current = $this->guard($scope, $revision, $plan, $units);
            $deadline = $authorize($current, $this->now());
            $deadline->requireFresh($this->now());
        });
    }

    /**
     * @param list<N\Unit> $units
     * @param Closure(N\NavigationPlan, D\UtcInstant): D\DisclosureDeadline $authorize
     */
    public function disclose(D\Scope $scope, D\DocumentRevisionRef $revision, N\NavigationPlan $plan,
        array $units, D\Ulid $attempt, Closure $authorize): N\UnitDisclosure|D\DisclosureReplay
    {
        $refs = [];
        foreach ($units as $unit) {
            foreach ($unit->sourceUnits as $ref) {
                $refs[$ref->kind . ':' . $ref->id->value] = $ref;
            }
        }

        return $this->tx->run(function () use ($scope, $revision, $plan, $units, $refs, $attempt, $authorize): N\UnitDisclosure|D\DisclosureReplay {
            $replay = $this->replay($scope, $revision, $units, $attempt);
            if ($replay !== null) {
                return $replay;
            }
            $current = $this->guard($scope, $revision, $plan, array_values($refs));
            $now = $this->now();
            $deadline = $authorize($current, $now);
            $usage = new N\NavigationUsage($scope, $revision, $plan->documentId,
                new D\UsageId($this->ids->next()->value), $attempt, $now,
                new D\Sha256(HashDomain::PolicyBinding->digest($current->guards->binding())), $units, $current->coverage);
            $response = $usage->disclosure();
            if (!$this->persist($usage)) {
                return $this->replay($scope, $revision, $units, $attempt)
                    ?? throw new D\DomainFailure(D\Outcome::Unknown, 'commit_unknown');
            }
            $deadline->requireFresh($this->now());

            return $response;
        });
    }

    /** @param list<N\SourceUnitRef> $units */
    private function guard(D\Scope $scope, D\DocumentRevisionRef $revision, N\NavigationPlan $plan, array $units): N\NavigationPlan
    {
        $this->tx->lockTenant($scope->tenantId, D\LockMode::Share);
        $this->tx->lockKb($scope->kbId, D\LockMode::Share);
        $this->tx->lockSources($plan->guards->sources, D\LockMode::Share);
        $current = $this->plan($scope, $revision);
        $plan->requireSame($current);
        $this->usages->requireRevisionReadable($scope, $revision);
        $this->requireUnits($scope, $revision, $plan, $units);

        return $current;
    }

    private function requireRevision(D\Scope $scope, D\DocumentRevisionRef $revision): array
    {
        $sql = sprintf(self::REVISION_SQL, $this->db->table('document_revisions'),
            $this->db->table('source_versions'), $this->db->table('resource_ledger'));
        $row = $this->db->row($sql, [$scope->tenantId->value, $revision->documentRevisionId->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unknown, 'revision_unknown');
        }
        $source = $revision->sourceRef;
        $expected = ['source_id' => $source->sourceId->value, 'source_version_id' => $source->sourceVersionId->value,
            'source_digest' => $source->sourceDigest->value, 'ir_digest' => $revision->irDigest->value,
            'parser_name' => $revision->parserSignature->name, 'parser_version' => $revision->parserSignature->version,
            'profile_digest' => $revision->parserSignature->profileDigest->value];
        foreach ($expected as $key => $value) {
            if ($row[$key] !== $value) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
            }
        }
        if ($row['object_state'] !== 'accepted') {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_purged');
        }
        if ($row['object_digest'] !== $revision->irDigest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }

        return $row;
    }

    /** @param list<N\SourceUnitRef> $units */
    private function requireUnits(D\Scope $scope, D\DocumentRevisionRef $revision, N\NavigationPlan $plan, array $units): void
    {
        if (count($units) > 1024) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_unit_limit');
        }
        foreach (['page', 'segment'] as $kind) {
            $expected = [];
            foreach ($units as $unit) {
                if ($unit->kind === $kind) {
                    $expected[$unit->id->value] = $unit->textDigest->value;
                }
            }
            if ($expected === []) {
                continue;
            }
            $sql = sprintf(self::UNITS_SQL, $kind, $this->db->table($kind === 'page' ? 'pages' : 'segments'),
                $this->db->table('resource_ledger'), $kind);
            $rows = $this->db->execute($sql, [$scope->tenantId->value, $plan->documentId->value,
                $revision->sourceRef->sourceId->value, json_encode(array_keys($expected), JSON_THROW_ON_ERROR)])->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertUnits($rows, $expected);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $expected
     */
    private function assertUnits(array $rows, array $expected): void
    {
        if (count($rows) !== count($expected)) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'unit_unavailable');
        }
        foreach ($rows as $row) {
            if ($row['state'] !== 'accepted' || !in_array($row['status'], ['ok', 'empty'], true)) {
                throw new D\DomainFailure(D\Outcome::Unavailable, 'unit_unavailable');
            }
            if ($row['text_digest'] !== $expected[$row['local_id']] || $row['digest'] !== $expected[$row['local_id']]) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
            }
        }
    }

    private function persist(N\NavigationUsage $usage): bool
    {
        $scope = $usage->scope;
        $revision = $usage->revision;
        $source = $revision->sourceRef;
        $payload = $usage->payload();
        $digest = HashDomain::DomainEventPayload->digest($payload);
        $event = $this->event($usage, $payload, $digest);
        $inserted = $this->db->execute(sprintf(self::INSERT_SQL, $this->db->table('navigation_usages')), [
            $scope->tenantId->value, $usage->id->value, $usage->attemptId->value, $scope->kbId->value,
            $scope->actorId->value, $scope->purpose, $scope->hostScopeHash->value,
            $source->sourceId->value, $source->sourceVersionId->value, $revision->documentRevisionId->value,
            $revision->irDigest->value, $usage->documentId->value, $usage->policyBindingDigest->value,
            $usage->checkedAt->value, $digest, Jcs::canonicalize($payload),
        ])->rowCount() === 1;
        if (!$inserted) {
            return false;
        }
        $this->db->execute('INSERT INTO ' . $this->db->table('outbox')
            . ' (tenant_id,id,event_json,payload_digest,aggregate_version) VALUES (?,?,?::jsonb,?,?)',
            [$scope->tenantId->value, $usage->id->value, $event->canonical(), $digest, '1']);

        return true;
    }

    private function event(N\NavigationUsage $usage, JsonObject $payload, string $digest): D\DomainEvent
    {
        return new D\DomainEvent(new JsonObject([
            'eventId' => $usage->id->value, 'schemaVersion' => 'event-1.0', 'tenantId' => $usage->scope->tenantId->value,
            'aggregateType' => 'usage', 'aggregateId' => $usage->id->value, 'aggregateVersion' => '1',
            'type' => 'usage.prepared_disclosure', 'occurredAt' => $usage->checkedAt->value,
            'causationId' => null, 'correlationId' => $usage->attemptId->value,
            'payload' => $payload, 'payloadDigest' => $digest,
        ]), $this->validator);
    }

    private function now(): D\UtcInstant
    {
        $value = (string) $this->db->execute('SELECT clock_timestamp()')->fetchColumn();

        return new D\UtcInstant((new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
    }

    /** @param list<N\Unit> $units */
    private function replay(D\Scope $scope, D\DocumentRevisionRef $revision, array $units, D\Ulid $attempt): ?D\DisclosureReplay
    {
        $scope->assertTenant($revision->sourceRef->tenantId);
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('navigation_usages')
            . ' WHERE tenant_id=? AND disclosure_attempt_id=?', [$scope->tenantId->value, $attempt->value]);
        if ($row === null) {
            return null;
        }
        foreach (['kb_id' => $scope->kbId->value, 'actor_id' => $scope->actorId->value,
            'purpose' => $scope->purpose, 'host_scope_hash' => $scope->hostScopeHash->value] as $key => $value) {
            if ($row[$key] !== $value) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }
        }
        $payload = StrictJson::decode($row['reference_json']);
        $refs = array_map(static fn (N\Unit $unit): JsonObject => $unit->reference(), $units);
        if (Jcs::canonicalize($payload->get('revisionRef')) !== Jcs::canonicalize($revision->toWire())
            || Jcs::canonicalize($payload->get('unitRefs')) !== Jcs::canonicalize($refs)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'attempt_conflict');
        }

        return new D\DisclosureReplay(new D\UsageId($row['id']), $attempt,
            new D\UtcInstant($payload->get('checkedAt')), new D\Sha256($row['payload_digest']));
    }
}
