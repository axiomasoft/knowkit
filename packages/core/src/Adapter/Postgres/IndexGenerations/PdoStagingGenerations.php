<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\IndexGenerations;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\ManifestCandidate as Candidate;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Port\Outbox;

/** Durable staging and guarded seal only. Active snapshot publication belongs to the publish use case.
 * Adapts pinned AgenTools rollback/revalidation and DeepTutor staged receipts/recovery;
 * tests/publication/manifest/evidence/P3.3-reuse.json records exact sources and limitations.
 */
final readonly class PdoStagingGenerations
{
    /** Host-supplied application callbacks keep the Adapter -> Domain/Port boundary.
     * @param \Closure(D\Scope,D\ResourceRef,D\Operation): void $preparePolicy Outside SQL only.
     * @param \Closure(D\Scope,D\ResourceRef,D\Operation,JsonObject): void $requireAllow Local prepared-receipt validation only.
     * @param \Closure(Candidate): array $verifyResources Full object and backend probe, outside SQL only.
     * @param \Closure(Candidate): void $verifyBackend Local persisted SQL reads only, under guards.
     * @param \Closure(Candidate): JsonObject $optionalProjections Persisted optional readiness, outside SQL only.
     * @param \Closure(D\TenantId,string,D\Ulid,D\Decimal,string,JsonObject): D\DomainEvent $createEvent Local assembly only.
     */
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx,
        private \Closure $preparePolicy, private \Closure $requireAllow, private ResourceInventory $inventory,
        private \Closure $verifyResources, private \Closure $verifyBackend, private \Closure $optionalProjections,
        private \Closure $createEvent, private Outbox $outbox)
    { if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared catalog connection required'); } }

    /** Persist candidate identity and inventory before projection writes, for retry/reconciliation. */
    public function stage(D\Lease $lease, Candidate $candidate): void
    {
        $this->scope($lease, $candidate); $this->prepare($lease, $candidate);
        $this->tx->run(function() use ($lease, $candidate): void {
            $s = $lease->scope; $w = $candidate->manifest->toWire(); $this->locks($lease, $candidate, true);
            $binding = $this->current($lease, $candidate); $this->allow($lease, $candidate, $binding);
            $this->inventory->assertExact($candidate); $this->coverage($s, $w);
            $g = $this->generation($lease, $candidate);
            if ($g['state'] !== 'building' || ($g['manifest_digest'] !== null && $g['manifest_digest'] !== $candidate->digest->value)) { self::fail('generation_immutable'); }
            $old = $this->check($lease, $candidate);
            if ($old !== null && ($old['manifest_digest'] !== $candidate->digest->value || $old['job_id'] !== $lease->jobId->value
                || Jcs::canonicalize(StrictJson::decode($old['source_epochs'])) !== Jcs::canonicalize($binding->get('sources')))) { self::fail('staging_conflict'); }
            $this->db->execute('UPDATE ' . $this->db->table('index_generations') . ' SET manifest_json=?,manifest_digest=? WHERE tenant_id=? AND id=?',
                [$candidate->manifest->canonical(), $candidate->digest->value, $s->tenantId->value, $w->get('generationId')]);
            $this->db->execute('INSERT INTO ' . $this->db->table('generation_checks') . ' (tenant_id,generation_id,job_id,manifest_digest,source_epochs)
                VALUES (?,?,?,?,?) ON CONFLICT (tenant_id,generation_id) DO NOTHING',
                [$s->tenantId->value, $w->get('generationId'), $lease->jobId->value, $candidate->digest->value, Jcs::canonicalize($binding->get('sources'))]);
        });
    }

    /** Required failure never mutates the active pointer. Optional failures become explicit capabilities. */
    public function seal(D\Lease $lease, Candidate $candidate): JsonObject
    {
        $this->scope($lease, $candidate);
        $g = $this->generation($lease, $candidate); $stored = $this->check($lease, $candidate);
        if ($g['manifest_digest'] !== $candidate->digest->value || $stored === null || $stored['manifest_digest'] !== $candidate->digest->value
            || Jcs::canonicalize(StrictJson::decode($g['manifest_json'])) !== $candidate->manifest->canonical()) { self::fail('manifest_mismatch'); }
        if (in_array($g['state'], ['sealed','active','retired'], true) && $stored['receipt_json'] !== null) { return StrictJson::decode($stored['receipt_json']); }
        if ($g['state'] !== 'building') { self::fail('generation_immutable'); }
        try {
            if ($this->db->pdo->inTransaction()) { throw new \LogicException('Probe I/O outside SQL required'); }
            $this->inventory->assertExact($candidate);
            $resources = ($this->verifyResources)($candidate); $profile = ($this->optionalProjections)($candidate);
        } catch (D\DomainFailure $failure) {
            $this->failBuild($lease, $candidate, $failure); throw $failure;
        }
        $receipt = new JsonObject(['manifestDigest'=>$candidate->digest->value,'resources'=>$resources,'effectiveProfile'=>$profile]);
        $w = $candidate->manifest->toWire();
        $event = ($this->createEvent)($lease->scope->tenantId, 'generation', new D\GenerationId($w->get('generationId')), new D\Decimal('1'),
            'generation.sealed', new JsonObject(['kbId'=>$w->get('kbId'),'generationId'=>$w->get('generationId'),'manifestDigest'=>$candidate->digest->value]));
        $this->prepare($lease, $candidate);
        return $this->tx->run(function() use ($lease, $candidate, $receipt, $event): JsonObject {
            $s = $lease->scope; $w = $candidate->manifest->toWire(); $this->locks($lease, $candidate);
            $binding = $this->current($lease, $candidate); $this->allow($lease, $candidate, $binding);
            $g = $this->generation($lease, $candidate); $check = $this->check($lease, $candidate);
            if ($g['state'] !== 'building' || $g['manifest_digest'] !== $candidate->digest->value || $check === null
                || $check['manifest_digest'] !== $candidate->digest->value || $check['job_id'] !== $lease->jobId->value
                || Jcs::canonicalize(StrictJson::decode($g['manifest_json'])) !== $candidate->manifest->canonical()
                || Jcs::canonicalize(StrictJson::decode($check['source_epochs'])) !== Jcs::canonicalize($binding->get('sources'))) { self::fail('staging_conflict'); }
            $this->inventory->assertExact($candidate); $this->coverage($s, $w); ($this->verifyBackend)($candidate);
            $this->db->execute('UPDATE ' . $this->db->table('index_generations') . " SET state='sealed',revision=revision+1 WHERE tenant_id=? AND id=? AND state='building'",
                [$s->tenantId->value, $w->get('generationId')]);
            $this->db->execute('UPDATE ' . $this->db->table('generation_checks') . ' SET receipt_json=?,event_id=? WHERE tenant_id=? AND generation_id=? AND receipt_json IS NULL',
                [Jcs::canonicalize($receipt), $event->toWire()->get('eventId'), $s->tenantId->value, $w->get('generationId')]);
            $this->outbox->append($event); return $receipt;
        });
    }

    private function scope(D\Lease $lease, Candidate $candidate): void
    {
        $w = $candidate->manifest->toWire(); $s = $lease->scope;
        if ($w->get('tenantId') !== $s->tenantId->value || $w->get('kbId') !== $s->kbId->value
            || ($lease->generationId !== null && $w->get('generationId') !== $lease->generationId->value)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        $found = false;
        foreach ($candidate->sets as $set) { if ($set->chunkSet->revisionRef->sourceRef == $lease->sourceRef) { $found = true; } }
        if (!$found) { self::fail('job_source_mismatch'); }
    }
    private function sources(Candidate $candidate): array
    {
        $ids = [];
        foreach ($candidate->manifest->toWire()->get('members') as $member) { $ids[$member->get('sourceId')] = new D\SourceId($member->get('sourceId')); }
        ksort($ids, SORT_STRING); return array_values($ids);
    }
    /** Publication reuses seal's exact guards; caller holds the tenant UPDATE barrier. SQL only.
     * @return list<string> Locked generations affected by activation/retirement.
     */
    public function checkPublication(D\ActivationRequest $request, PdoCatalogTransaction $transaction): array
    {
        if ($transaction !== $this->tx) { throw new \InvalidArgumentException('Shared publication transaction required'); }
        $lease = $request->lease; $candidate = $request->candidate;
        $this->tx->requireTenant($lease->scope->tenantId, true);
        $this->scope($lease, $candidate);
        $s = $lease->scope; $w = $candidate->manifest->toWire();
        $this->tx->lockKb($s->kbId, D\LockMode::Update);
        $this->tx->lockSources($request->sources(), D\LockMode::Update);
        // Retirement also writes metadata: acquire every affected generation before the job lock.
        $generations = $this->db->execute('SELECT g.id FROM '.$this->db->table('index_generations').
            " g WHERE g.tenant_id=? AND g.kb_id=? AND (g.id=? OR g.state='active' OR EXISTS (SELECT 1 FROM ".
            $this->db->table('snapshot_generations').' sg JOIN '.$this->db->table('knowledge_bases').
            ' k ON k.tenant_id=sg.tenant_id AND k.id=sg.kb_id AND k.active_snapshot_id=sg.snapshot_id
                WHERE sg.tenant_id=g.tenant_id AND sg.kb_id=g.kb_id AND sg.generation_id=g.id)) ORDER BY g.id',
            [$s->tenantId->value,$s->kbId->value,$w->get('generationId')])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($generations as $id) { $this->tx->lockGeneration(new D\GenerationId($id), D\LockMode::Update); }
        $this->tx->lockJob($lease->jobId, D\LockMode::Update);
        $tenant = $this->db->row('SELECT serving_state FROM '.$this->db->table('tenants').' WHERE id=?', [$s->tenantId->value]);
        if ($tenant === null || $tenant['serving_state'] !== 'ready') { throw new D\DomainFailure(D\Outcome::Unavailable, 'tenant_blocked'); }
        $binding = $this->current($lease, $candidate); $this->allow($lease, $candidate, $binding);
        $g = $this->generation($lease, $candidate); $check = $this->check($lease, $candidate);
        if (!in_array($g['state'], ['sealed','active','retired'], true) || $check === null || $check['receipt_json'] === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'generation_not_ready');
        }
        $receipt = StrictJson::decode($check['receipt_json']);
        if ($g['manifest_digest'] !== $candidate->digest->value || $check['manifest_digest'] !== $candidate->digest->value
            || $check['job_id'] !== $lease->jobId->value || $receipt->get('manifestDigest') !== $candidate->digest->value
            || Jcs::canonicalize(StrictJson::decode($g['manifest_json'])) !== $candidate->manifest->canonical()
            || Jcs::canonicalize(StrictJson::decode($check['source_epochs'])) !== Jcs::canonicalize($binding->get('sources'))
            || Jcs::canonicalize($receipt->get('resources')) !== Jcs::canonicalize($candidate->resources)) { self::fail('staging_conflict'); }
        $this->inventory->assertExact($candidate); $this->coverage($lease->scope, $candidate->manifest->toWire());
        ($this->verifyBackend)($candidate);
        return $generations;
    }

    private function locks(D\Lease $lease, Candidate $candidate, bool $create = false): void
    {
        $s = $lease->scope; $w = $candidate->manifest->toWire();
        $this->tx->lockTenant($s->tenantId, D\LockMode::Update); $this->tx->lockKb($s->kbId, D\LockMode::Update);
        $this->tx->lockSources($this->sources($candidate), D\LockMode::Update);
        if ($create) {
            $this->db->execute('INSERT INTO ' . $this->db->table('index_generations') . " (tenant_id,id,kb_id,profile_id,state,embedding_space_digest)
                VALUES (?,?,?,?,'building',?) ON CONFLICT (tenant_id,id) DO NOTHING",
                [$s->tenantId->value, $w->get('generationId'), $s->kbId->value, $w->get('profile')->get('id'), $w->get('embeddingSpaceDigest')]);
        }
        $this->tx->lockGeneration(new D\GenerationId($w->get('generationId')), D\LockMode::Update); $this->tx->lockJob($lease->jobId, D\LockMode::Update);
    }
    private function generation(D\Lease $lease, Candidate $candidate): array
    {
        $w = $candidate->manifest->toWire();
        $g = $this->db->row('SELECT * FROM ' . $this->db->table('index_generations') . ' WHERE tenant_id=? AND kb_id=? AND id=?',
            [$lease->scope->tenantId->value, $lease->scope->kbId->value, $w->get('generationId')]);
        if ($g === null || $g['profile_id'] !== $w->get('profile')->get('id') || $g['embedding_space_digest'] !== $w->get('embeddingSpaceDigest')) { self::fail('generation_mismatch'); }
        return $g;
    }
    private function check(D\Lease $lease, Candidate $candidate): ?array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table('generation_checks') . ' WHERE tenant_id=? AND generation_id=?',
            [$lease->scope->tenantId->value, $candidate->manifest->toWire()->get('generationId')]);
    }
    /** Fresh authoritative lifecycle, membership, epochs and lease fence under ordered locks. */
    private function current(D\Lease $lease, Candidate $candidate): JsonObject
    {
        $s = $lease->scope;
        $t = $this->db->row('SELECT * FROM ' . $this->db->table('tenants') . ' WHERE id=?', [$s->tenantId->value]);
        $k = $this->db->row('SELECT * FROM ' . $this->db->table('knowledge_bases') . ' WHERE tenant_id=? AND id=?', [$s->tenantId->value, $s->kbId->value]);
        if ($t === null || $t['serving_state'] !== 'ready' || $k === null || $k['removed_at'] !== null || (string)$k['kb_policy_epoch'] !== $s->policyEpoch->value) {
            throw new D\DomainFailure(D\Outcome::Denied, 'policy_changed');
        }
        $sources = [];
        foreach ($this->sources($candidate) as $id) {
            $r = $this->db->row('SELECT * FROM ' . $this->db->table('sources') . ' WHERE tenant_id=? AND id=?', [$s->tenantId->value, $id->value]);
            if ($r === null || $r['lifecycle_state'] !== 'active'
                || $this->db->row('SELECT id FROM ' . $this->db->table('tombstones') . ' WHERE tenant_id=? AND source_id=?', [$s->tenantId->value, $id->value]) !== null
                || $this->db->row('SELECT id FROM ' . $this->db->table('source_memberships') . ' WHERE tenant_id=? AND kb_id=? AND source_id=?', [$s->tenantId->value, $s->kbId->value, $id->value]) === null) {
                throw new D\DomainFailure(D\Outcome::Denied, 'source_changed');
            }
            $sources[] = new JsonObject(['sourceId'=>$id->value,'lifecycleEpoch'=>(string)$r['lifecycle_epoch'],'sourcePolicyEpoch'=>(string)$r['source_policy_epoch']]);
        }
        foreach ($candidate->manifest->toWire()->get('members') as $member) {
            $version = $this->db->row('SELECT usability FROM ' . $this->db->table('source_version_state') . ' WHERE tenant_id=? AND id=?', [$s->tenantId->value, $member->get('sourceVersionId')]);
            if ($version === null || $version['usability'] !== 'usable') { throw new D\DomainFailure(D\Outcome::Denied, 'source_changed'); }
        }
        $job = $this->db->row('SELECT *,clock_timestamp() database_now FROM ' . $this->db->table('jobs') . ' WHERE tenant_id=? AND id=?', [$s->tenantId->value, $lease->jobId->value]);
        if ($job === null || $job['state'] !== 'leased' || $job['cancel_requested'] || $job['kb_id'] !== $s->kbId->value
            || $job['source_id'] !== $lease->sourceRef->sourceId->value || $job['source_version_id'] !== $lease->sourceRef->sourceVersionId->value
            || ($job['generation_id'] !== null && $job['generation_id'] !== $candidate->manifest->toWire()->get('generationId'))
            || (string)$job['fence'] !== $lease->fence->value || $job['attempt_id'] !== $lease->attemptId->value
            || $job['lease_token_hash'] !== $lease->tokenHash()->value || (string)$job['source_epoch'] !== $lease->sourceEpoch->value
            || Jcs::canonicalize(StrictJson::decode($job['scope_json'])) !== Jcs::canonicalize($s->toWire())
            || new \DateTimeImmutable($job['lease_until']) <= new \DateTimeImmutable($job['database_now'])
            || new \DateTimeImmutable($job['attempt_deadline']) <= new \DateTimeImmutable($job['database_now'])) { self::fail('writer_fenced'); }
        $processing=StrictJson::decode($job['processing_identity_json']); $wire=$candidate->manifest->toWire(); $profile=$wire->get('profile');
        if (!$processing instanceof JsonObject || HashDomain::ProcessingIdentity->digest($processing)!==$job['processing_digest']
            || $processing->get('sourceVersionId')!==$lease->sourceRef->sourceVersionId->value || $processing->get('sourceDigest')!==$lease->sourceRef->sourceDigest->value
            || $processing->get('irSchema')!=='ir-1.0' || $processing->get('embeddingSpaceDigest')!==$wire->get('embeddingSpaceDigest')
            || Jcs::canonicalize($processing->get('parserSignature'))!==Jcs::canonicalize($profile->get('parserSignature'))
            || Jcs::canonicalize($processing->get('chunkerSignature'))!==Jcs::canonicalize($profile->get('chunkerSignature'))) { self::fail('processing_incompatible'); }
        foreach ($sources as $source) {
            if ($source->get('sourceId') === $lease->sourceRef->sourceId->value && $source->get('lifecycleEpoch') !== $lease->sourceEpoch->value) {
                throw new D\DomainFailure(D\Outcome::Denied, 'source_changed');
            }
        }
        return new JsonObject(['tenantPolicyEpoch'=>(string)$t['tenant_policy_epoch'],'kbPolicyEpoch'=>(string)$k['kb_policy_epoch'],'sources'=>$sources]);
    }
    private function prepare(D\Lease $lease, Candidate $candidate): void
    {
        $s = $lease->scope;
        ($this->preparePolicy)($s, new D\ResourceRef($s->tenantId, 'knowledge_base', $s->kbId), D\Operation::Publish);
        foreach ($this->sources($candidate) as $id) { ($this->preparePolicy)($s, new D\ResourceRef($s->tenantId, 'source', $id), D\Operation::Publish); }
    }
    private function allow(D\Lease $lease, Candidate $candidate, JsonObject $binding): void
    {
        $s = $lease->scope;
        ($this->requireAllow)($s, new D\ResourceRef($s->tenantId, 'knowledge_base', $s->kbId), D\Operation::Publish, $binding->with('sources', []));
        foreach ($binding->get('sources') as $source) {
            ($this->requireAllow)($s, new D\ResourceRef($s->tenantId, 'source', new D\SourceId($source->get('sourceId'))), D\Operation::Publish, $binding->with('sources', [$source]));
        }
    }
    private function coverage(D\Scope $scope, JsonObject $manifest): void
    {
        $k = $this->db->row('SELECT coverage_policy FROM ' . $this->db->table('knowledge_bases') . ' WHERE tenant_id=? AND id=?', [$scope->tenantId->value, $scope->kbId->value]);
        $policy = StrictJson::decode($k['coverage_policy']); $coverage = $manifest->get('coverage');
        if (($coverage->get('state') !== 'complete' && $policy->get('allowPartial') !== true)
            || array_intersect($policy->get('requiredFeatures'), $coverage->get('missingFeatures')) !== [] || $coverage->get('state') === 'unknown') {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'coverage_required');
        }
        if ($manifest->get('profile')->get('kind') !== 'lexical' || $manifest->get('profile')->get('id') !== 'postgres-fts-simple-v1'
            || $manifest->get('embeddingSpaceDigest') !== null) { self::fail('profile_incompatible'); }
    }
    private function failBuild(D\Lease $lease, Candidate $candidate, D\DomainFailure $failure): void
    {
        $this->tx->run(function() use ($lease, $candidate, $failure): void {
            $this->locks($lease, $candidate); $this->current($lease, $candidate);
            $this->db->execute('UPDATE ' . $this->db->table('index_generations') . " SET state='failed' WHERE tenant_id=? AND id=? AND state='building'",
                [$lease->scope->tenantId->value, $candidate->manifest->toWire()->get('generationId')]);
            $this->db->execute('UPDATE ' . $this->db->table('generation_checks') . ' SET failure_outcome=?,failure_code=? WHERE tenant_id=? AND generation_id=? AND receipt_json IS NULL',
                [$failure->outcome->value, $failure->errorCode, $lease->scope->tenantId->value, $candidate->manifest->toWire()->get('generationId')]);
        });
    }
    private static function fail(string $code): never { throw new D\DomainFailure(D\Outcome::Incompatible, $code); }
}
