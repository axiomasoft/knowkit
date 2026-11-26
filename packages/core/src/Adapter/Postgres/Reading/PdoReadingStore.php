<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Reading;

use Closure;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Reading as R;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\ReadingStore;

/** Reuses bounded disclosure's ordered SQL guards, Usage lineage and confirmed-COMMIT boundary. */
final readonly class PdoReadingStore implements ReadingStore
{
    private const REPLAY_SQL = <<<'SQL'
        SELECT u.*,i.response_blob FROM %s u LEFT JOIN %s i
            ON i.tenant_id=u.tenant_id AND i.key='reading:' || u.disclosure_attempt_id::text
        WHERE u.tenant_id=? AND u.disclosure_attempt_id=?
        SQL;

    private const SOURCE_SQL = <<<'SQL'
        SELECT b.*,v.media_type FROM %s v JOIN %s b
            ON b.tenant_id=v.tenant_id AND b.source_id=v.source_id AND b.id=v.blob_resource_id
        WHERE v.tenant_id=? AND v.id=? AND v.source_id=? AND v.source_digest=?
        SQL;

    private const ASSET_SQL = <<<'SQL'
        SELECT b.*,a.media_type FROM %s a JOIN %s b ON b.tenant_id=a.tenant_id AND b.id=a.resource_id
        WHERE a.tenant_id=? AND a.revision_id=? AND b.source_id=?
            AND a.source_locators_json->0->>'assetId'=?
        ORDER BY a.id LIMIT 2
        SQL;

    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private PdoUsages $usages,
        private PdoDependencyStore $dependencies,
        private ContractValidator $validator,
        private IdGenerator $ids,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    public function citation(D\Scope $scope, D\Ulid $id): R\CitationRecord
    {
        return (new PdoCitations($this->db))->get($scope, $id, $this->validator);
    }

    public function replay(D\Scope $scope, R\ReadRequest $request): ?D\DisclosureReplay
    {
        $row = $this->db->row(sprintf(self::REPLAY_SQL, $this->db->table('evidence_usages'),
            $this->db->table('idempotency_receipts')), [$scope->tenantId->value, $request->attemptId->value]);
        if ($row === null) {
            return null;
        }
        foreach (['kb_id' => $scope->kbId->value, 'actor_id' => $scope->actorId->value,
            'purpose' => $scope->purpose, 'host_scope_hash' => $scope->hostScopeHash->value, 'run_ref' => $request->runRef] as $key => $expected) {
            if ($row[$key] !== $expected) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }
        }
        if ($row['response_blob'] === null || StrictJson::decode($row['response_blob'])->get('requestDigest') !== $request->digest()->value) {
            throw new D\DomainFailure(D\Outcome::Denied, 'attempt_conflict');
        }

        return new D\DisclosureReplay(new D\UsageId($row['id']), $request->attemptId,
            self::instant($row['prepared_at']), new D\Sha256($row['evidence_digest']));
    }

    public function plan(D\Scope $scope, R\CitationRecord $record): D\DisclosurePlan
    {
        $record->requireScope($scope);

        return $this->usages->readingPlan($scope, $record->chunk);
    }

    public function object(R\CitationRecord $record, R\ReadTarget $target): R\BlobObject
    {
        $source = $record->chunk->revisionRef->sourceRef;
        $values = [$source->tenantId->value];
        if ($target->kind === 'source') {
            $sql = sprintf(self::SOURCE_SQL, $this->db->table('source_versions'), $this->db->table('resource_ledger'));
            $values = [...$values, $source->sourceVersionId->value, $source->sourceId->value, $source->sourceDigest->value];
        } elseif ($target->kind === 'asset' && $target->id !== null) {
            $sql = sprintf(self::ASSET_SQL, $this->db->table('assets'), $this->db->table('resource_ledger'));
            $values = [...$values, $record->chunk->revisionRef->documentRevisionId->value, $source->sourceId->value, $target->id->value];
        } else {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'blob_target_required');
        }
        $rows = $this->db->execute($sql, $values)->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_missing');
        }
        if (count($rows) !== 1) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'ambiguous_asset');
        }

        return $this->blob($rows[0], $source);
    }

    public function commit(D\Scope $scope, R\CitationRecord $record, R\ReadRequest $request,
        D\DisclosurePlan $plan, R\ReadBody $body, ?R\BlobObject $object, Closure $authorize): D\Result
    {
        return $this->tx->run(
            /** @return D\Result<R\ReadResponse>|D\Result<D\DisclosureReplay> */
            function () use ($scope, $record, $request, $plan, $body, $object, $authorize): D\Result {
                $this->tx->lockTenant($scope->tenantId, D\LockMode::Share);
                $this->tx->lockKb($scope->kbId, D\LockMode::Share);
                $this->tx->lockSources($plan->sources, D\LockMode::Share);
                $replay = $this->replay($scope, $request);
                if ($replay !== null) {
                    return D\Result::success($replay);
                }
                $current = $this->plan($scope, $record);
                $plan->requireSame($current);
                $fresh = $this->citation($scope, $request->citationId);
                $fresh->requirePayload();
                if (
                    !$fresh->selectionId->equals($record->selectionId)
                    || !$fresh->kbId->equals($record->kbId)
                    || !$fresh->hostScopeHash->equals($record->hostScopeHash)
                    || $fresh->joiner !== $record->joiner
                    || $fresh->payloadPresent !== $record->payloadPresent
                    || Jcs::canonicalize($fresh->citation->toWire()) !== Jcs::canonicalize($record->citation->toWire())
                    || Jcs::canonicalize($fresh->chunk->toWire()) !== Jcs::canonicalize($record->chunk->toWire())
                ) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
                }
                $this->usages->requireReadable($scope, $record->chunk);
                $this->requireObjects($record, $body, $object);
                $now = $this->now();
                $request->requireFresh($now);
                $deadline = $authorize($current, $now);
                $response = $this->persist($scope, $record, $request, $body, $current, $now);
                $finalNow = $this->now();
                $request->requireFresh($finalNow);
                $deadline->requireFresh($finalNow);

                return $response instanceof D\DisclosureReplay
                    ? D\Result::success($response)
                    : D\Result::success($response);
            }
        );
    }

    private function persist(D\Scope $scope, R\CitationRecord $record, R\ReadRequest $request,
        R\ReadBody $body, D\DisclosurePlan $plan, D\UtcInstant $now): R\ReadResponse|D\DisclosureReplay
    {
        $usage = $this->usage($scope, $record, $request, $body, $plan, $now);
        $audit = new JsonObject(['requestDigest' => $request->digest()->value, 'citationId' => $request->citationId->value,
            'target' => $request->target->toWire(), 'bodyDigest' => $body->digest->value,
            'byteLength' => strlen($body->bytes()), 'mediaType' => $body->mediaType,
            'profile' => $request->limits->profile, 'metadataDigest' => hash('sha256', Jcs::canonicalize($body->metadata()))]);
        $digest = hash('sha256', Jcs::canonicalize($audit));
        if (!$this->insertUsage($scope, $usage, $digest)) {
            return $this->replay($scope, $request) ?? throw new D\DomainFailure(D\Outcome::Unknown, 'commit_unknown');
        }
        $this->dependencies->recordUsage($usage);
        $this->db->execute('INSERT INTO ' . $this->db->table('idempotency_receipts') . ' VALUES (?,?,?,?,?,?::jsonb,?)',
            [$scope->tenantId->value, $usage->id()->value, 'reading:' . $request->attemptId->value, 'citation_read',
                $request->digest()->value, Jcs::canonicalize($audit), $now->value]);
        $this->event($usage);

        return new R\ReadResponse($record->citation, $body, $usage->id(), $now, $request->limits->profile);
    }

    private function usage(D\Scope $scope, R\CitationRecord $record, R\ReadRequest $request,
        R\ReadBody $body, D\DisclosurePlan $plan, D\UtcInstant $now): D\Usage
    {
        return new D\Usage(new JsonObject([
            'usageId' => $this->ids->next()->value, 'tenantId' => $scope->tenantId->value,
            'disclosureAttemptId' => $request->attemptId->value, 'selectionId' => $record->selectionId->value,
            'actorId' => $scope->actorId->value, 'purpose' => $scope->purpose,
            'hostScopeHash' => $scope->hostScopeHash->value, 'runRef' => $request->runRef,
            'checkedAt' => $now->value, 'policyBindingDigest' => HashDomain::PolicyBinding->digest($plan->binding()),
            'status' => 'prepared_disclosure', 'fragmentRefs' => [new JsonObject([
                'chunkRef' => $record->chunk->toWire(), 'fragmentDigest' => $body->digest->value,
            ])],
        ]), $this->validator);
    }

    private function insertUsage(D\Scope $scope, D\Usage $usage, string $digest): bool
    {
        $sql = 'INSERT INTO ' . $this->db->table('evidence_usages')
            . ' (tenant_id,id,disclosure_attempt_id,kb_id,actor_id,purpose,run_ref,selection_id,'
            . 'host_scope_hash,policy_binding_digest,prepared_at,evidence_digest) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            . ' ON CONFLICT (tenant_id,disclosure_attempt_id) DO NOTHING';

        return $this->db->execute($sql, [$scope->tenantId->value, $usage->id()->value, $usage->attemptId()->value,
            $scope->kbId->value, $scope->actorId->value, $scope->purpose, $usage->runRef(), $usage->selectionId()->value,
            $scope->hostScopeHash->value, $usage->policyBindingDigest()->value, $usage->checkedAt()->value, $digest])->rowCount() === 1;
    }

    private function event(D\Usage $usage): void
    {
        $payload = $usage->toWire()->without('tenantId')->without('status');
        $event = new D\DomainEvent(new JsonObject([
            'eventId' => $usage->id()->value, 'schemaVersion' => 'event-1.0', 'tenantId' => $usage->tenantId()->value,
            'aggregateType' => 'usage', 'aggregateId' => $usage->id()->value, 'aggregateVersion' => '1',
            'type' => 'usage.prepared_disclosure', 'occurredAt' => $usage->checkedAt()->value,
            'causationId' => null, 'correlationId' => $usage->attemptId()->value, 'payload' => $payload,
            'payloadDigest' => HashDomain::DomainEventPayload->digest($payload),
        ]), $this->validator);
        $this->db->execute('INSERT INTO ' . $this->db->table('outbox')
            . ' (tenant_id,id,event_json,payload_digest,aggregate_version) VALUES (?,?,?::jsonb,?,?)',
            [$usage->tenantId()->value, $usage->id()->value, $event->canonical(), $event->toWire()->get('payloadDigest'), '1']);
    }

    private function requireObjects(R\CitationRecord $record, R\ReadBody $body, ?R\BlobObject $object): void
    {
        $source = $record->chunk->revisionRef->sourceRef;
        $row = $this->db->row('SELECT b.state,b.digest FROM ' . $this->db->table('document_revisions')
            . ' r JOIN ' . $this->db->table('resource_ledger') . ' b ON b.tenant_id=r.tenant_id AND b.id=r.ir_resource_id'
            . ' WHERE r.tenant_id=? AND r.id=? AND b.source_id=?',
            [$source->tenantId->value, $record->chunk->revisionRef->documentRevisionId->value, $source->sourceId->value]);
        if ($row === null || $row['state'] !== 'accepted' || $row['digest'] !== $record->chunk->revisionRef->irDigest->value) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_purged');
        }
        $units = $body->metadata()->get('sourceUnits') ?? [];
        foreach ($record->citation->spans() as $span) {
            $locator = $span->locator->toWire();
            $kind = $locator->get('kind');
            $units[] = new JsonObject(['kind' => $kind, 'id' => $locator->get($kind === 'page' ? 'pageId' : 'segmentId')]);
        }
        $this->requireUnits($record, $units);
        if ($object !== null) {
            $row = $this->db->row('SELECT * FROM ' . $this->db->table('resource_ledger') . ' WHERE tenant_id=? AND id=? AND source_id=?',
                [$source->tenantId->value, $object->resourceId->value, $source->sourceId->value]);
            if ($row === null || !self::sameBlobRef($this->blob($row, $source)->blob, $object->blob)) {
                throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_purged');
            }
        }
    }

    private static function sameBlobRef(D\BlobRef $left, D\BlobRef $right): bool
    {
        return $left->tenantId->equals($right->tenantId)
            && $left->sourceId->equals($right->sourceId)
            && $left->digest->equals($right->digest)
            && $left->byteLength->value === $right->byteLength->value
            && $left->storageId === $right->storageId
            && $left->objectVersion === $right->objectVersion
            && $left->ownerKind === $right->ownerKind;
    }

    /** @param list<JsonObject> $units */
    private function requireUnits(R\CitationRecord $record, array $units): void
    {
        $ids = ['page' => [], 'segment' => []];
        foreach ($units as $unit) {
            $ids[$unit->get('kind') === 'page' ? 'page' : 'segment'][] = (string) $unit->get('id');
        }
        $accepted = [
            'page' => $this->acceptedUnits($record, true, $ids['page']),
            'segment' => $this->acceptedUnits($record, false, $ids['segment']),
        ];
        foreach ($units as $unit) {
            $kind = $unit->get('kind') === 'page' ? 'page' : 'segment';
            if (!isset($accepted[$kind][(string) $unit->get('id')])) {
                throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_purged');
            }
        }
    }

    /** @param list<string> $ids @return array<string, true> */
    private function acceptedUnits(R\CitationRecord $record, bool $page, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $column = $page ? 'local_page_id' : 'local_segment_id';
        $rows = $this->db->execute(
            'SELECT u.' . $column . ' AS local_id FROM ' . $this->db->table($page ? 'pages' : 'segments')
            . ' u JOIN ' . $this->db->table('resource_ledger') . ' b ON b.tenant_id=u.tenant_id AND b.id=u.text_resource_id'
            . ' WHERE u.tenant_id=? AND u.document_id=? AND b.state=\'accepted\' AND u.' . $column
            . ' IN (SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))',
            [$record->chunk->revisionRef->sourceRef->tenantId->value, $record->citation->documentId()->value,
                json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR)],
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_fill_keys($rows, true);
    }

    private function blob(array $row, D\ExactSourceRef $source): R\BlobObject
    {
        if ($row['state'] !== 'accepted') {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_purged');
        }

        return new R\BlobObject(new D\ResourceId($row['id']), new D\BlobRef($source->tenantId, $source->sourceId,
            new D\Sha256($row['digest']), new D\Uint64((string) $row['byte_length']), $row['storage_id'],
            $row['object_version'], $row['owner_kind']), $row['media_type'] ?? 'application/octet-stream');
    }

    private function now(): D\UtcInstant
    {
        return self::instant((string) $this->db->execute('SELECT clock_timestamp()')->fetchColumn());
    }

    private static function instant(string $value): D\UtcInstant
    {
        return new D\UtcInstant((new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
    }
}
