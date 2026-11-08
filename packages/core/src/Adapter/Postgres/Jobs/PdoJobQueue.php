<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Jobs;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Port\{IdGenerator, WorkerJobs};

/**
 * Durable tenant/KB partition. AgenTools immutable replay/rollback and DeepTutor recovery
 * patterns adapted to PostgreSQL fencing; see tests/jobs/leases/evidence/P2.7-reuse.json.
 * (c) 2026 Dmitry Vostrikov, private local reuse. No donor runtime dependency.
 */
final readonly class PdoJobQueue implements WorkerJobs
{
    private const ACTIVE_TENANT_JOBS_SQL = <<<'SQL'
        SELECT count(*) FROM %s
        WHERE tenant_id = ? AND state = 'leased' AND lease_until > clock_timestamp()
        SQL;

    /** @param array<string,array<array-key,string>> $stagePlans Trusted registered type -> ordered stages.
     *  @param \Closure(D\Lease,D\Checkpoint): D\JobCheckpointManifest $verifiedCheckpoint
     *  The callback must independently read/hash immutable manifest/resources outside SQL. */
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private D\Scope $partition,
        private IdGenerator $ids,
        private array $stagePlans,
        private \Closure $verifiedCheckpoint,
        private int $maxConcurrentIngest = 4,
    ) {
        if ($maxConcurrentIngest < 1 || $maxConcurrentIngest > 1024) {
            throw new \InvalidArgumentException('Finite tenant ingest quota required');
        }
        if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared connection required'); }
        foreach ($stagePlans as $type => $stages) {
            new D\JobType($type);
            if (!array_is_list($stages) || count($stages)<1 || count($stages)>16 || count(array_unique($stages))!==count($stages)) {
                throw new \InvalidArgumentException('Unique bounded stage plan required');
            }
            foreach ($stages as $stage) { new D\JobType($stage); }
        }
    }

    public function enqueue(D\JobCommand $command, D\IdempotencyKey $key): D\JobId
    {
        $this->partition($command->scope);
        $plan = $this->stagePlans[$command->type->value] ?? throw new D\DomainFailure(D\Outcome::Incompatible,'unknown_job_type');
        $id = new D\JobId($this->ids->next()->value); $receipt = $this->ids->next()->value;
        $fingerprint = HashDomain::IdempotencyRequest->digest(new JsonObject(['operation'=>'job_enqueue',
            'scope'=>$command->scope->toWire(),'source'=>$command->sourceRef->toWire(),'generationId'=>$command->generationId?->value,
            'type'=>$command->type->value,'processingIdentity'=>$command->processingIdentity->toWire(),
            'stagePlan'=>$plan,'availableAt'=>$command->availableAt->value]));
        $seed = ['id'=>$id->value,'kb_id'=>$command->scope->kbId->value,'source_id'=>$command->sourceRef->sourceId->value,
            'source_version_id'=>$command->sourceRef->sourceVersionId->value,'generation_id'=>$command->generationId?->value];
        return $this->guarded($seed, function(?array $row, array $source) use($command,$key,$id,$receipt,$fingerprint,$plan): D\JobId {
            if (!$source['usable'] || $source['source_digest']!==$command->sourceRef->sourceDigest->value) {
                throw new D\DomainFailure(D\Outcome::Denied,'source_unusable');
            }
            $this->db->execute('INSERT INTO '.$this->db->table('idempotency_receipts').
                '(tenant_id,id,key,operation,request_fingerprint,response_blob,accepted_at) VALUES (?,?,?,\'job_enqueue\',?,?::jsonb,clock_timestamp())
                 ON CONFLICT (tenant_id,key) DO NOTHING', [$this->tenant(),$receipt,$key->value,$fingerprint,Jcs::canonicalize(new JsonObject(['jobId'=>$id->value]))]);
            $stored = $this->db->row('SELECT * FROM '.$this->db->table('idempotency_receipts').' WHERE tenant_id=? AND key=?',[$this->tenant(),$key->value]);
            if ($stored===null || $stored['operation']!=='job_enqueue' || $stored['request_fingerprint']!==$fingerprint) {
                throw new D\DomainFailure(D\Outcome::Incompatible,'idempotency_conflict');
            }
            $original = new D\JobId(StrictJson::decode($stored['response_blob'])->get('jobId'));
            if ($stored['id']!==$receipt) { return $original; }
            $this->db->execute('INSERT INTO '.$this->db->table('jobs').
                '(tenant_id,id,source_id,source_version_id,kb_id,generation_id,type,stage,state,available_at,source_epoch,idempotency_key,
                  request_fingerprint,scope_json,processing_identity_json,processing_digest,stage_plan_json,stage_input_digest)
                 VALUES (?,?,?,?,?,?,?,?,\'pending\',?::timestamptz,?,?,?,?::jsonb,?::jsonb,?,?::jsonb,?)',
                [$this->tenant(),$id->value,$command->sourceRef->sourceId->value,$command->sourceRef->sourceVersionId->value,
                 $command->scope->kbId->value,$command->generationId?->value,$command->type->value,$plan[0],$command->availableAt->value,
                 $source['lifecycle_epoch'],$key->value,$fingerprint,Jcs::canonicalize($command->scope->toWire()),
                 Jcs::canonicalize($command->processingIdentity->toWire()),$command->processingIdentity->digest()->value,
                 Jcs::canonicalize($plan),$command->sourceRef->sourceDigest->value]);
            return $id;
        });
    }

    public function lease(D\JobType $type, D\WorkerId $worker): ?D\Lease
    {
        if (!isset($this->stagePlans[$type->value])) { throw new D\DomainFailure(D\Outcome::Incompatible,'unknown_job_type'); }
        // Bounded, unlocked planning. Every candidate gets a fresh short transaction and semantic locks.
        $candidates = $this->db->execute('SELECT * FROM '.$this->db->table('jobs').
            ' WHERE tenant_id=? AND kb_id=? AND type=? AND scope_json->>\'hostScopeHash\'=? AND NOT cancel_requested AND processing_digest IS NOT NULL
              AND ((state=\'pending\' AND available_at<=clock_timestamp()) OR (state=\'leased\' AND lease_until<=clock_timestamp()))
              ORDER BY available_at,id LIMIT 32',[$this->tenant(),$this->partition->kbId->value,$type->value,$this->partition->hostScopeHash->value])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($candidates as $seed) {
            $token = bin2hex(random_bytes(32)); $attempt = new D\AttemptId($this->ids->next()->value);
            $lease = $this->guarded($seed,function(?array $row,array $source) use($worker,$token,$attempt): ?D\Lease {
                if ($row===null || !$source['usable'] || !$this->durable($row) || (string)$row['source_epoch']!==(string)$source['lifecycle_epoch']) { return null; }
                if ($row['state']==='leased' && $this->db->row('SELECT 1 FROM '.$this->db->table('jobs').
                    ' WHERE tenant_id=? AND id=? AND lease_until<=clock_timestamp()',[$this->tenant(),$row['id']])!==null) {
                    $this->uncertainWriters($row);
                    $state=(int)$row['attempt_count']>=5?'failed':'pending';
                    $this->db->execute('UPDATE '.$this->db->table('jobs').' SET state=?,available_at=lease_until+make_interval(secs=>?),
                        last_error_code=\'lease_expired\',uncertain_external_effect=true WHERE tenant_id=? AND id=?',
                        [$state,self::backoff((int)$row['attempt_count']),$this->tenant(),$row['id']]);
                }
                $current = $this->db->row('SELECT * FROM '.$this->db->table('jobs').
                    ' WHERE tenant_id=? AND id=? AND state=\'pending\' AND NOT cancel_requested AND available_at<=clock_timestamp() AND attempt_count<5',[$this->tenant(),$row['id']]);
                if ($current===null) { return null; }
                $this->assertTenantQuota();
                $fence=(new D\Decimal((string)$current['fence']))->next();
                $claimed=$this->db->row('UPDATE '.$this->db->table('jobs').
                    ' SET state=\'leased\',attempt_count=attempt_count+1,fence=?,attempt_id=?,resume_verified_fence=NULL,lease_owner=?,lease_token_hash=?,
                      attempt_deadline=clock_timestamp()+interval \'900 seconds\',lease_until=clock_timestamp()+interval \'60 seconds\'
                      WHERE tenant_id=? AND id=? AND state=\'pending\' AND NOT cancel_requested RETURNING *',
                    [$fence->value,$attempt->value,$worker->value,hash('sha256',$token),$this->tenant(),$row['id']]);
                return $claimed===null?null:$this->leaseValue($claimed,$source,$token);
            },true);
            if ($lease!==null) { return $lease; }
        }
        return null;
    }

    public function heartbeat(D\Lease $lease): bool
    {
        return $this->withLease($lease,fn(array $row): bool => $this->db->execute('UPDATE '.$this->db->table('jobs').
            ' SET lease_until=LEAST(clock_timestamp()+interval \'60 seconds\',attempt_deadline) WHERE '.$this->predicate(),$this->capability($lease))->rowCount()===1);
    }

    private function assertTenantQuota(): void
    {
        // Lease admission holds tenant FOR UPDATE before KB/source/job locks. Counts include all KBs.
        $count = (int) $this->db->execute(
            sprintf(self::ACTIVE_TENANT_JOBS_SQL, $this->db->table('jobs')),
            [$this->tenant()],
        )->fetchColumn();
        if ($count >= $this->maxConcurrentIngest) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'quota_exceeded');
        }
    }

    public function checkpoint(D\Lease $lease, D\Checkpoint $checkpoint): bool
    {
        $this->partition($lease->scope);
        if (!$this->withLease($lease,fn(array $row): bool=>$this->current($lease))) { return false; }
        $manifest=($this->verifiedCheckpoint)($lease,$checkpoint); // external I/O before BEGIN
        if ($manifest->checkpoint()!=$checkpoint) { throw new D\DomainFailure(D\Outcome::Incompatible,'checkpoint_digest_mismatch'); }
        return $this->withLease($lease,function(array $row) use($lease,$manifest,$checkpoint): bool {
            if (!$this->current($lease) || !$this->manifestBinding($row,$manifest) || !$this->resources($row,$manifest)) { return false; }
            $wire=$manifest->toWire();
            $existing=$this->db->row('SELECT * FROM '.$this->db->table('job_checkpoints').
                ' WHERE tenant_id=? AND job_id=? AND checkpoint_digest=?',[$this->tenant(),$row['id'],$checkpoint->digest->value]);
            if ($existing!==null) { return Jcs::canonicalizeJson($existing['checkpoint_json'])===Jcs::canonicalize($wire); }
            if (((int)$row['completed_stage_count']>0 && (string)$row['resume_verified_fence']!==$lease->fence->value)
                || $row['stage']!==$wire->get('stage') || $row['stage_input_digest']!==$wire->get('inputDigest')
                || (int)$row['completed_stage_count']>=count(json_decode($row['stage_plan_json'],true,512,JSON_THROW_ON_ERROR))) { return false; }
            $this->db->execute('INSERT INTO '.$this->db->table('job_checkpoints').
                '(tenant_id,id,job_id,stage,input_digest,output_digest,resource_refs_json,fence,created_at,checkpoint_digest,checkpoint_json,uncertain_external_effect)
                 VALUES (?,?,?,?,?,?,?::jsonb,?,clock_timestamp(),?,?::jsonb,?)',
                [$this->tenant(),$this->ids->next()->value,$row['id'],$wire->get('stage'),$wire->get('inputDigest'),$wire->get('outputDigest'),
                 Jcs::canonicalize($wire->get('resources')),$lease->fence->value,$checkpoint->digest->value,Jcs::canonicalize($wire),$checkpoint->uncertainExternalEffect]);
            if ($checkpoint->uncertainExternalEffect) {
                $this->uncertainWriters($row);
                $sql=' SET uncertain_external_effect=true'; $values=[];
            } else {
                $plan=json_decode($row['stage_plan_json'],true,512,JSON_THROW_ON_ERROR); $completed=(int)$row['completed_stage_count']+1;
                $sql=' SET completed_stage_count=?,stage=?,stage_input_digest=?,checkpoint_digest=?,resume_verified_fence=?';
                $values=[$completed,$plan[min($completed,count($plan)-1)],$wire->get('outputDigest'),$checkpoint->digest->value,$lease->fence->value];
            }
            if ($this->db->execute('UPDATE '.$this->db->table('jobs').$sql.' WHERE '.$this->predicate(),array_merge($values,$this->capability($lease)))->rowCount()!==1) {
                throw new D\DomainFailure(D\Outcome::Incompatible,'stale_write'); // roll back the inserted checkpoint as well
            }
            return true;
        });
    }

    public function complete(D\Lease $lease, D\JobResult $result): bool { return $this->accept($lease,$result); }

    /** Trusted local SQL acceptance hook only; guards precede job, resource/outbox rows follow.
     *  No policy/provider/blob/process calls and no additional semantic locks are allowed in $sqlEffect.
     *  @param null|\Closure(PdoCatalogTransaction):void $sqlEffect */
    public function accept(D\Lease $lease, D\JobResult $result, ?\Closure $sqlEffect=null): bool
    {
        return $this->withLease($lease,function(array $row) use($lease,$result,$sqlEffect): bool {
            $coverage=$result->coverage===null?null:Jcs::canonicalize($result->coverage->toWire());
            if ($row['state']==='succeeded') {
                return $row['accepted_result_digest']===$result->resultDigest->value && $row['accepted_outcome']===$result->outcome->value
                    && ($row['accepted_coverage_json']===null?null:Jcs::canonicalizeJson($row['accepted_coverage_json']))===$coverage;
            }
            if (!$this->current($lease)) { return false; }
            $success=in_array($result->outcome,[D\Outcome::Success,D\Outcome::Empty],true);
            if ($success && ((int)$row['completed_stage_count']!==count(json_decode($row['stage_plan_json'],true,512,JSON_THROW_ON_ERROR))
                || (string)$row['resume_verified_fence']!==$lease->fence->value)) { return false; }
            if ($success) {
                $saved=$this->db->row('SELECT checkpoint_json FROM '.$this->db->table('job_checkpoints').
                    ' WHERE tenant_id=? AND job_id=? AND checkpoint_digest=? AND NOT uncertain_external_effect',[$this->tenant(),$row['id'],$row['checkpoint_digest']]);
                if ($saved===null || !$this->resources($row,new D\JobCheckpointManifest(StrictJson::decode($saved['checkpoint_json'])))) { return false; }
            }
            $retry=in_array($result->outcome,[D\Outcome::Unknown,D\Outcome::Unavailable],true) && (int)$row['attempt_count']<5;
            $state=$success?'succeeded':($retry?'pending':'failed');
            $openWriter=$this->db->row('SELECT id FROM '.$this->db->table('resource_writers').
                ' WHERE tenant_id=? AND job_id=? AND state<>\'closed\' LIMIT 1',[$this->tenant(),$row['id']])!==null;
            $this->uncertainWriters($row);
            $changed=$this->db->execute('UPDATE '.$this->db->table('jobs').
                ' SET state=?,available_at=clock_timestamp()+make_interval(secs=>?),accepted_result_digest=?,accepted_outcome=?,accepted_coverage_json=?::jsonb,
                  last_error_code=?,uncertain_external_effect=uncertain_external_effect OR ? WHERE '.$this->predicate(),
                array_merge([$state,self::backoff((int)$row['attempt_count']),$result->resultDigest->value,$result->outcome->value,$coverage,
                    $success?null:$result->outcome->value,$result->outcome===D\Outcome::Unknown || $openWriter],$this->capability($lease)))->rowCount()===1;
            if ($changed && $success && $sqlEffect!==null) { $sqlEffect($this->tx); }
            return $changed;
        },$sqlEffect!==null);
    }

    public function cancel(D\Scope $scope, D\JobId $jobId): bool
    {
        $this->partition($scope); $seed=$this->seed($jobId);
        if ($seed===null) { return false; }
        return $this->guarded($seed,function(?array $row) use($scope): bool {
            if ($row===null || $row['scope_json']===null || Jcs::canonicalizeJson($row['scope_json'])!==Jcs::canonicalize($scope->toWire())) { return false; }
            if ($row['state']==='cancelled') { return true; }
            if (!in_array($row['state'],['pending','leased'],true)) { return false; }
            $next=(new D\Decimal((string)$row['fence']))->next(); $writers=$this->uncertainWriters($row);
            return $this->db->execute('UPDATE '.$this->db->table('jobs').
                ' SET state=\'cancelled\',cancel_requested=true,fence=?,uncertain_external_effect=uncertain_external_effect OR state=\'leased\' OR ?
                  WHERE tenant_id=? AND id=? AND state IN (\'pending\',\'leased\')',[$next->value,$writers,$this->tenant(),$row['id']])->rowCount()===1;
        });
    }

    /** A retry reopens and hashes the checkpoint, then rechecks the capability in another short transaction. */
    public function resume(D\Lease $lease, D\ProcessingIdentity $processing): ?D\Checkpoint
    {
        $row=$this->withLease($lease,function(array $job) use($lease,$processing): array|false {
            if (!$this->current($lease)) { return false; }
            if ($processing->digest()->value!==$job['processing_digest']) { throw new D\DomainFailure(D\Outcome::Incompatible,'processing_identity_mismatch'); }
            return $job;
        });
        if ($row===false || $row['checkpoint_digest']===null) { return null; }
        $checkpoint=new D\Checkpoint(new D\Sha256($row['checkpoint_digest']),false);
        $manifest=($this->verifiedCheckpoint)($lease,$checkpoint);
        if ($manifest->checkpoint()!=$checkpoint) { throw new D\DomainFailure(D\Outcome::Incompatible,'checkpoint_digest_mismatch'); }
        $valid=$this->withLease($lease,function(array $job) use($lease,$manifest,$checkpoint): bool {
            $saved=$this->db->row('SELECT checkpoint_json FROM '.$this->db->table('job_checkpoints').
                ' WHERE tenant_id=? AND job_id=? AND checkpoint_digest=? AND NOT uncertain_external_effect',[$this->tenant(),$job['id'],$checkpoint->digest->value]);
            $verified=$this->current($lease) && $job['checkpoint_digest']===$checkpoint->digest->value && $saved!==null
                && $this->manifestBinding($job,$manifest) && Jcs::canonicalizeJson($saved['checkpoint_json'])===Jcs::canonicalize($manifest->toWire())
                && $this->resources($job,$manifest);
            return $verified && $this->db->execute('UPDATE '.$this->db->table('jobs').' SET resume_verified_fence=? WHERE '.$this->predicate(),
                array_merge([$lease->fence->value],$this->capability($lease)))->rowCount()===1;
        });
        if (!$valid) { return null; }
        return $checkpoint;
    }

    /** Safe supervisor metadata; never a substitute for resume's independent verification. */
    public function context(D\Lease $lease): ?JsonObject
    {
        $value=$this->withLease($lease,fn(array $row): JsonObject|false => !$this->current($lease)?false:new JsonObject([
            'stage'=>$row['stage'],'inputDigest'=>$row['stage_input_digest'],'processingIdentity'=>StrictJson::decode($row['processing_identity_json']),
            'uncertainExternalEffect'=>$row['uncertain_external_effect']]));
        return $value===false?null:$value;
    }

    public function workerContext(D\Lease $lease): ?D\WorkerJobContext
    {
        $context = $this->withLease($lease, function (array $row) use ($lease): D\WorkerJobContext|false {
            if (!$this->current($lease)) {
                return false;
            }
            $plan = json_decode($row['stage_plan_json'], true, 512, JSON_THROW_ON_ERROR);
            return new D\WorkerJobContext(
                new D\JobType($row['stage']),
                new D\Sha256($row['stage_input_digest']),
                new D\Fingerprint(HashDomain::ProcessingIdentity->digest(StrictJson::decode($row['processing_identity_json']))),
                $row['uncertain_external_effect'],
                (int) $row['completed_stage_count'] + 1 === count($plan),
            );
        });
        return $context === false ? null : $context;
    }

    public static function backoff(int $attempt): int
    { if ($attempt<1 || $attempt>5) { throw new \InvalidArgumentException('Attempt 1..5 required'); } return min(300,5*(2**($attempt-1))); }
    private function tenant(): string { return $this->partition->tenantId->value; }
    private function partition(D\Scope $scope): void
    {
        if ($scope->tenantId!=$this->partition->tenantId || $scope->kbId!=$this->partition->kbId || $scope->hostScopeHash!=$this->partition->hostScopeHash) {
            throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch');
        }
    }
    private function seed(D\JobId $id): ?array
    { return $this->db->row('SELECT * FROM '.$this->db->table('jobs').' WHERE tenant_id=? AND kb_id=? AND id=?',[$this->tenant(),$this->partition->kbId->value,$id->value]); }
    private function withLease(D\Lease $lease, \Closure $operation, bool $effect=false): mixed
    {
        $this->partition($lease->scope); $seed=$this->seed($lease->jobId);
        if ($seed===null) { return false; }
        return $this->guarded($seed,function(?array $row,array $source) use($lease,$operation): mixed {
            if ($row===null || !$source['usable'] || !$this->durable($row) || !$this->binding($row,$source,$lease)) { return false; }
            return $operation($row);
        },false,$effect);
    }
    private function guarded(array $seed, \Closure $operation, bool $skip=false, bool $effect=false): mixed
    {
        $prepared=false;
        try { return $this->tx->run(function() use($seed,$operation,$skip,$effect,&$prepared): mixed {
            $started=hrtime(true);
            $this->db->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->execute("SET LOCAL lock_timeout='500ms'"); $this->db->execute("SET LOCAL statement_timeout='2000ms'");
            $mode = $effect || $skip ? D\LockMode::Update : D\LockMode::Share;
            $this->tx->lockTenant($this->partition->tenantId,$mode);
            $this->tx->lockKb(new D\KbId($seed['kb_id']),$mode);
            $this->tx->lockSources([new D\SourceId($seed['source_id'])],$mode);
            if ($seed['generation_id']!==null) { $this->tx->lockGeneration(new D\GenerationId($seed['generation_id']),$mode); }
            // The helper's blocking lockJob is deliberately not used for candidate selection.
            $row=$this->db->row('SELECT * FROM '.$this->db->table('jobs').' WHERE tenant_id=? AND id=? FOR UPDATE'.($skip?' SKIP LOCKED':''),[$this->tenant(),$seed['id']]);
            if ($skip && $row===null) { return null; }
            $source=$this->db->row('SELECT s.lifecycle_epoch,v.source_digest,(t.serving_state=\'ready\' AND k.removed_at IS NULL AND s.lifecycle_state=\'active\'
                 AND vs.usability=\'usable\' AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('tombstones').' z WHERE z.tenant_id=s.tenant_id AND z.source_id=s.id)) AS usable
                 FROM '.$this->db->table('sources').' s JOIN '.$this->db->table('tenants').' t ON t.id=s.tenant_id
                 JOIN '.$this->db->table('knowledge_bases').' k ON k.tenant_id=s.tenant_id AND k.id=?
                 JOIN '.$this->db->table('source_versions').' v ON v.tenant_id=s.tenant_id AND v.source_id=s.id AND v.id=?
                 JOIN '.$this->db->table('source_version_state').' vs ON vs.tenant_id=v.tenant_id AND vs.id=v.id WHERE s.tenant_id=? AND s.id=?',
                 [$seed['kb_id'],$seed['source_version_id'],$this->tenant(),$seed['source_id']]);
            if ($source===null) { throw new D\DomainFailure(D\Outcome::Incompatible,'source_missing'); }
            if ($seed['generation_id']!==null && $this->db->row('SELECT id FROM '.$this->db->table('index_generations').
                ' WHERE tenant_id=? AND kb_id=? AND id=?',[$this->tenant(),$seed['kb_id'],$seed['generation_id']])===null) {
                throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch');
            }
            $result=$operation($row,$source);
            if (hrtime(true)-$started>2_000_000_000) { throw new D\DomainFailure(D\Outcome::Unavailable,'transaction_timeout'); }
            $prepared=true;
            return $result;
        }); } catch (D\DomainFailure $error) {
            if ($prepared && in_array($error->errorCode,['storage_unavailable','commit_unknown'],true)) { throw new D\DomainFailure(D\Outcome::Unknown,'job_commit_unknown'); }
            throw $error;
        }
    }
    private function durable(array $row): bool
    { return $row['scope_json']!==null && $row['processing_identity_json']!==null && $row['stage_plan_json']!==null && $row['processing_digest']!==null; }
    private function binding(array $row,array $source,D\Lease $lease): bool
    {
        return Jcs::canonicalizeJson($row['scope_json'])===Jcs::canonicalize($lease->scope->toWire())
            && $row['source_id']===$lease->sourceRef->sourceId->value && $row['source_version_id']===$lease->sourceRef->sourceVersionId->value
            && $source['source_digest']===$lease->sourceRef->sourceDigest->value && $row['generation_id']===$lease->generationId?->value
            && $row['attempt_id']===$lease->attemptId->value && (string)$row['fence']===$lease->fence->value
            && (string)$source['lifecycle_epoch']===$lease->sourceEpoch->value && (string)$row['source_epoch']===$lease->sourceEpoch->value
            && $row['lease_token_hash']!==null && hash_equals($row['lease_token_hash'],$lease->tokenHash()->value);
    }
    private function predicate(): string
    { return 'tenant_id=? AND id=? AND state=\'leased\' AND NOT cancel_requested AND fence=? AND lease_token_hash=? AND attempt_id=? AND source_epoch=? AND lease_until>clock_timestamp() AND attempt_deadline>clock_timestamp()'; }
    private function capability(D\Lease $lease): array
    { return [$this->tenant(),$lease->jobId->value,$lease->fence->value,$lease->tokenHash()->value,$lease->attemptId->value,$lease->sourceEpoch->value]; }
    private function current(D\Lease $lease): bool
    { return $this->db->row('SELECT 1 FROM '.$this->db->table('jobs').' WHERE '.$this->predicate(),$this->capability($lease))!==null; }
    private function leaseValue(array $row,array $source,string $token): D\Lease
    {
        $w=StrictJson::decode($row['scope_json']);
        $scope=new D\Scope(new D\TenantId($w->get('tenantId')),new D\KbId($w->get('kbId')),new D\ActorId($w->get('actorId')),
            $w->get('purpose'),new D\Decimal($w->get('policyEpoch')),new D\Sha256($w->get('hostScopeHash')));
        return new D\Lease($scope,new D\JobId($row['id']),new D\AttemptId($row['attempt_id']),
            new D\SourceVersionRef($scope->tenantId,new D\SourceId($row['source_id']),new D\SourceVersionId($row['source_version_id']),new D\SourceDigest($source['source_digest'])),
            $row['generation_id']===null?null:new D\GenerationId($row['generation_id']),new D\Decimal((string)$row['fence']),$token,
            self::instant($row['lease_until']),self::instant($row['attempt_deadline']),new D\Decimal((string)$row['source_epoch']));
    }
    private static function instant(string $value): D\UtcInstant
    { return new D\UtcInstant((new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')); }
    private function manifestBinding(array $row,D\JobCheckpointManifest $manifest): bool
    {
        $w=$manifest->toWire();
        return $w->get('tenantId')===$this->tenant() && $w->get('jobId')===$row['id'] && $w->get('kbId')===$row['kb_id']
            && $w->get('sourceId')===$row['source_id'] && $w->get('sourceVersionId')===$row['source_version_id']
            && $w->get('sourceEpoch')===(string)$row['source_epoch'] && $w->get('processingDigest')===$row['processing_digest'];
    }
    private function resources(array $job,D\JobCheckpointManifest $manifest): bool
    {
        foreach ($manifest->toWire()->get('resources') as $ref) {
            $r=$this->db->row('SELECT * FROM '.$this->db->table('resource_ledger').' WHERE tenant_id=? AND id=? FOR SHARE',[$this->tenant(),$ref->get('resourceId')]);
            if ($r===null || $r['source_id']!==$job['source_id'] || $r['digest']!==$ref->get('digest') || (string)$r['byte_length']!==$ref->get('byteLength')
                || !in_array($r['state'],['uploaded','accepted'],true)) { return false; }
            if (!$manifest->toWire()->get('uncertainExternalEffect') && $this->db->row('SELECT id FROM '.$this->db->table('resource_writers').
                ' WHERE tenant_id=? AND resource_id=? AND state<>\'closed\' LIMIT 1',[$this->tenant(),$r['id']])!==null) { return false; }
        }
        return true;
    }
    private function uncertainWriters(array $row): bool
    {
        return $this->db->execute('UPDATE '.$this->db->table('resource_writers').' SET state=\'uncertain\'
            WHERE tenant_id=? AND job_id=? AND fence=? AND state=\'active\'',[$this->tenant(),$row['id'],(string)$row['fence']])->rowCount()>0;
    }
}
