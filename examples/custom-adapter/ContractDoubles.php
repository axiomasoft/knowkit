<?php
declare(strict_types=1);

namespace KnowKit\Examples\CustomAdapter;

use KnowKit\Conformance\{AdapterRig, Fixture, MemoryStream};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Port as P;

/** Synthetic state: no database, filesystem storage, provider or production qualification. */
final class FakeState
{
    public D\Decision $policy = D\Decision::Allow;
    public D\Decision $egress = D\Decision::Allow;
    public D\UtcInstant $now;
    public string $deletion = 'deleted';
    public array $blobs = [];
    public array $refs = [];
    public int $sends = 0;
    public bool $jobDone = false;
    public array $events = [];
    public ?D\DeliveryLease $delivery = null;
    public bool $acked = false;
    public int $deliveryFence = 0;
    public array $parents = [];
    public bool $revoked = false;
    public array $artifacts = [];
    public function __construct(public readonly Fixture $f, public readonly string $fault) { $this->now = $f->now(); }
    public function tenant(D\TenantId $tenant): void { $this->f->scope()->assertTenant($tenant); }
}

final class FakePolicy implements P\AccessPolicy, P\EgressPolicy
{
    public function __construct(private FakeState $s) {}
    public function authorizeQuery(D\Scope $scope): D\QueryGrant
    {
        if ($this->s->policy !== D\Decision::Allow) {
            throw new D\DomainFailure($this->s->policy === D\Decision::Unknown ? D\Outcome::Unknown : D\Outcome::Denied,'query_not_allowed');
        }
        return $this->s->f->grant();
    }
    public function decide(D\Scope $scope,D\ResourceRef $resource,D\Operation $operation): D\DecisionReceipt
    {
        $scope->assertTenant($resource->tenantId);
        $decision = $this->s->fault === 'unknown-allow' && $this->s->policy === D\Decision::Unknown ? D\Decision::Allow : $this->s->policy;
        return $this->s->f->receipt($decision,$scope,$resource,$operation);
    }
    public function mayEgress(D\Scope $scope,D\EgressRequest $request): D\DecisionReceipt
    {
        $sources = $request->toWire()->get('items')[0]->get('sourceVersionRefs');
        $resource = $sources === [] ? new D\ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId)
            : new D\ResourceRef($scope->tenantId,'source_version',new D\SourceVersionId($sources[0]->get('sourceVersionId')));
        return $this->s->f->receipt($this->s->egress,$scope,$resource,D\Operation::from($request->toWire()->get('operation')));
    }
}

final class FakeBlob implements P\BlobStore
{
    public function __construct(private FakeState $s) {}
    public function putImmutable(D\BlobWriteReservation $reservation,P\ReadableStream $stream): D\BlobRef
    {
        $this->s->tenant($reservation->resource->tenantId); $bytes = '';
        try { while (!$stream->eof()) { $part = $stream->read(4096); if ($part === '' || strlen($bytes) + strlen($part) > 1048576) { throw new \RuntimeException('stream_bound'); } $bytes .= $part; } }
        finally { $stream->close(); }
        $key = $reservation->resource->id->value;
        if ($this->s->fault !== 'mutable' && ($reservation->digest?->value !== hash('sha256',$bytes)
            || $reservation->byteLength?->value !== (string) strlen($bytes) || (isset($this->s->blobs[$key]) && $this->s->blobs[$key] !== $bytes))) {
            throw new D\DomainFailure(D\Outcome::Incompatible,'immutable_conflict');
        }
        $ref = new D\BlobRef($reservation->resource->tenantId,$reservation->sourceId,new D\Sha256(hash('sha256',$bytes)),
            new D\Uint64((string) strlen($bytes)),'fixture',$key,'owned');
        $this->s->blobs[$key] = $bytes; $this->s->refs[$key] = $ref; return $ref;
    }
    public function open(D\BlobRef $ref): P\ReadableStream
    {
        if (!$this->exists($ref)) { throw new D\DomainFailure(D\Outcome::Unavailable,'blob_absent'); }
        return new MemoryStream($this->s->blobs[$ref->objectVersion]);
    }
    public function exists(D\BlobRef $ref): bool
    {
        $this->s->tenant($ref->tenantId);
        if (!isset($this->s->blobs[$ref->objectVersion])) { return false; }
        if ($this->s->refs[$ref->objectVersion] != $ref) { throw new D\DomainFailure(D\Outcome::Incompatible,'immutable_conflict'); }
        return true;
    }
    public function listUnreferenced(D\UtcInstant $before,D\Cursor $cursor): D\ResourcePage
    { $this->s->tenant($cursor->tenantId); return new D\ResourcePage($cursor->tenantId,[],null); }
    public function delete(D\ResourceRef $resource,D\AttemptId $attemptId): D\DeletionReceipt
    {
        $this->s->tenant($resource->tenantId);
        $result = $this->s->deletion;
        if ($result === 'deleted') {
            if (!isset($this->s->blobs[$resource->id->value])) { $result = 'absent'; }
            if ($this->s->fault !== 'misleading-delete') { unset($this->s->blobs[$resource->id->value]); }
        }
        return $this->s->f->deletion($result,$attemptId,$resource);
    }
}

final class FakeParser implements P\DocumentParser
{
    public function __construct(private FakeState $s) {}
    public function capabilities(): D\ParserCapabilities { return new D\ParserCapabilities([D\Capability::ParserText],['ir-1.0']); }
    public function parse(D\SourceBytes $source,D\ParserProfile $profile): D\IrDocument
    {
        if ($profile->signature != $this->s->f->parserProfile()->signature) { throw new D\DomainFailure(D\Outcome::Incompatible,'parser_profile_mismatch'); }
        $this->s->tenant($source->ref->tenantId);
        if ($source->ref != $this->s->f->source()) { throw new D\DomainFailure(D\Outcome::Incompatible,'source_identity_mismatch'); }
        $ir = $this->s->f->ir();
        if ($this->s->fault === 'retarget-parser') {
            return new D\IrDocument($ir->toWire()->with('sourceVersionId',Fixture::id(4)),$this->s->f->validator);
        }
        return $ir;
    }
}

final class FakeSearch implements P\LexicalIndex
{
    public function __construct(private FakeState $s) {}
    public function index(D\GenerationId $generationId,D\ChunkSet $chunkSet): D\IndexReceipt
    { throw new D\DomainFailure(D\Outcome::Unavailable,'fixture_preseeded'); }
    public function search(D\AuthorizedSearchScope $scope,D\Query $query,int $k): D\CandidateList
    {
        if ($k < 1) { throw new \InvalidArgumentException('Positive k required'); }
        $f = $this->s->f;
        $project = $scope->tenantId == $f->scope()->tenantId ? 'A' : 'B';
        if ($scope->tenantId != $f->scope($project)->tenantId || $scope->snapshotId != $f->snapshot($project)->snapshotId
            || $scope->hostScopeHash != $f->scope($project)->hostScopeHash) { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); }
        $rows = [$f->chunk('A',4),$f->chunk('B',4),$f->chunk('A'),$f->chunk('B')];
        if ($this->s->fault === 'postfilter') { $rows = array_slice($rows,0,$k); }
        $eligible = [];
        foreach ($rows as $ref) { try { $scope->assertCandidate($ref); $eligible[] = $ref; } catch (D\DomainFailure) {} }
        return new D\CandidateList($scope,array_map(static fn(D\ChunkRef $ref): D\Candidate => new D\Candidate($ref,1),array_slice($eligible,0,$k)));
    }
}

final class FakeVector implements P\VectorIndex
{
    public function __construct(private FakeState $s,private FakeSearch $search) {}
    public function index(D\GenerationId $generationId,D\ChunkSet $chunkSet,D\EmbeddingSpace $space): D\IndexReceipt
    { throw new D\DomainFailure(D\Outcome::Unavailable,'fixture_preseeded'); }
    public function search(D\AuthorizedSearchScope $scope,D\Query $query,int $k,D\EmbeddingSpace $space): D\CandidateList
    {
        if ($this->s->fault !== 'space-mismatch') { $this->s->f->space()->assertCompatible($space); }
        return $this->search->search($scope,$query,$k);
    }
}

final class FakeTokenizer implements P\Tokenizer
{
    public function __construct(private FakeState $s) {}
    public function capabilities(): D\TokenizerCapabilities { return new D\TokenizerCapabilities($this->s->f->tokenizer(),true); }
    public function count(string $utf8,D\TokenizerIdentity $identity): int
    {
        $this->capabilities()->requireExact($identity);
        if (preg_match('//u',$utf8) !== 1) { throw new D\DomainFailure(D\Outcome::Incompatible,'invalid_utf8'); }
        return $this->s->fault === 'inexact-tokenizer' ? intdiv(strlen($utf8),4) : mb_strlen($utf8,'UTF-8');
    }
}

final class FakeJobs implements P\JobQueue
{
    public function __construct(private FakeState $s) {}
    public function enqueue(D\JobCommand $command,D\IdempotencyKey $key): D\JobId
    { $this->s->tenant($command->scope->tenantId); return $this->s->f->lease()->jobId; }
    public function lease(D\JobType $type,D\WorkerId $worker): ?D\Lease { return $this->s->jobDone ? null : $this->s->f->lease(); }
    private function current(D\Lease $lease): bool
    { return $this->s->fault === 'stale-fence' || (!$this->s->jobDone && $this->s->f->lease()->isCurrent($lease,$this->s->now)); }
    public function heartbeat(D\Lease $lease): bool { return $this->current($lease); }
    public function checkpoint(D\Lease $lease,D\Checkpoint $checkpoint): bool { return $this->current($lease); }
    public function complete(D\Lease $lease,D\JobResult $result): bool
    { if (!$this->current($lease)) { return false; } $this->s->jobDone = true; return true; }
    public function cancel(D\Scope $scope,D\JobId $jobId): bool
    { $this->s->tenant($scope->tenantId); if ($jobId != $this->s->f->lease()->jobId || $this->s->jobDone) { return false; } $this->s->jobDone = true; return true; }
}

final class FakeOutbox implements P\Outbox, P\OutboxDelivery
{
    public function __construct(private FakeState $s) {}
    public function append(D\DomainEvent $event): void
    {
        $this->s->tenant(new D\TenantId($event->toWire()->get('tenantId'))); $id = $event->toWire()->get('eventId');
        if (isset($this->s->events[$id]) && $this->s->events[$id] !== $event->canonical()) { throw new D\DomainFailure(D\Outcome::Incompatible,'immutable_conflict'); }
        if ($this->s->fault === 'duplicate-outbox') { $id .= count($this->s->events); }
        $this->s->events[$id] = $event->canonical();
    }
    public function leaseDelivery(int $limit): array
    {
        if ($limit < 1 || $this->s->acked || $this->s->events === []) { return []; }
        if ($this->s->delivery !== null && $this->s->delivery->expiresAt->compare($this->s->now) > 0) { return []; }
        $this->s->delivery = new D\DeliveryLease($this->s->f->scope()->tenantId,new D\EventId(Fixture::id(80)),
            new D\Decimal((string) ++$this->s->deliveryFence),str_repeat('d',32),$this->s->now->plusSeconds(4));
        return $this->s->fault === 'duplicate-outbox' ? [$this->s->delivery,$this->s->delivery] : [$this->s->delivery];
    }
    public function ack(D\DeliveryLease $lease): bool
    {
        $current = $this->s->delivery;
        if ($current === null || $this->s->acked || $current->tenantId != $lease->tenantId || $current->eventId != $lease->eventId
            || $current->fence != $lease->fence || $current->tokenHash() != $lease->tokenHash() || $current->expiresAt->compare($this->s->now) <= 0) { return false; }
        $this->s->acked = true; return true;
    }
}

final class FakeDependencies implements P\DependencyStore
{
    public function __construct(private FakeState $s) {}
    public function register(D\Node $node,D\Parents $parents): void
    { $this->s->tenant($node->ref->tenantId); $node->assertParents($parents); $this->s->parents[$node->ref->id->value] = $parents; }
    public function checkAncestors(D\NodeRefs $refs): D\UsabilityReport
    {
        $this->s->tenant($refs->tenantId); $parents = [];
        foreach ($refs->refs as $ref) {
            if (!isset($this->s->parents[$ref->id->value])) { return new D\UsabilityReport(D\Decision::Unknown,new D\NodeRefs($refs->tenantId,[])); }
            foreach ($this->s->parents[$ref->id->value]->refs as $parent) { $parents[] = $parent; }
        }
        $revoked = $this->s->revoked && $this->s->fault !== 'revoked-parent';
        return new D\UsabilityReport($revoked ? D\Decision::Deny : D\Decision::Allow,new D\NodeRefs($refs->tenantId,$revoked ? $parents : []));
    }
    public function recordUsage(D\Usage $usage): void { $this->s->tenant(new D\TenantId($usage->toWire()->get('tenantId'))); }
    public function invalidate(D\LifecycleEvent $event): void
    { $this->s->tenant(new D\TenantId($event->event->toWire()->get('tenantId'))); $this->s->revoked = true; }
}

final class FakeProvider extends P\PolicyCheckedEmbeddingProvider
{
    public function __construct(private FakeState $s,FakePolicy $policy,P\Clock $clock) { parent::__construct($policy,$policy,$clock); }
    public function capabilities(): D\EmbeddingCapabilities { return new D\EmbeddingCapabilities([D\Capability::EmbeddingQuery,D\Capability::EmbeddingDocuments],['ir-1.0']); }
    protected function performDocuments(D\EmbeddingAccess $access,array $inputs,D\SpaceRef $space): D\Result
    { $this->s->sends++; return D\Result::success(new D\EmbeddingBatch(array_map(static fn() => new D\Vector([1,2,3],$space),$inputs),$space)); }
    protected function performQuery(D\EmbeddingAccess $access,D\QueryInput $input,D\SpaceRef $space): D\Result
    { $this->s->sends++; return D\Result::success(new D\Vector([1,2,3],$space)); }
}

final class UnsafeProvider implements P\EmbeddingProvider
{
    public function __construct(private FakeState $s) {}
    public function capabilities(): D\EmbeddingCapabilities { return new D\EmbeddingCapabilities([D\Capability::EmbeddingQuery,D\Capability::EmbeddingDocuments],['ir-1.0']); }
    public function embedDocuments(D\EmbeddingAccess $access,array $inputs,D\SpaceRef $space): D\Result
    { $this->s->sends++; return D\Result::success(new D\EmbeddingBatch(array_map(static fn() => new D\Vector([1,2,3],$space),$inputs),$space)); }
    public function embedQuery(D\EmbeddingAccess $access,D\QueryInput $input,D\SpaceRef $space): D\Result
    { $this->s->sends++; return D\Result::success(new D\Vector([1,2,3],$space)); }
}

final class ExampleAdapters
{
    public static function prepare(Fixture $f,string $fault,bool $lexicalOnly = false): AdapterRig
    {
        $s = new FakeState($f,$fault); $policy = new FakePolicy($s); $search = new FakeSearch($s); $outbox = new FakeOutbox($s);
        $clock = new class($s) implements P\Clock {
            public function __construct(private FakeState $s) {}
            public function now(): D\UtcInstant { return $this->s->now; }
        };
        $adapters = $lexicalOnly ? ['policy'=>$policy,'search'=>$search] : ['policy'=>$policy,'egress'=>$policy,'blob'=>new FakeBlob($s),'parser'=>new FakeParser($s),'search'=>$search,
            'vector'=>new FakeVector($s,$search),'tokenizer'=>new FakeTokenizer($s),'jobs'=>new FakeJobs($s),
            'outbox'=>$outbox,'delivery'=>$outbox,'dependencies'=>new FakeDependencies($s),
            'embedding'=>$fault === 'unguarded-egress' ? new UnsafeProvider($s) : new FakeProvider($s,$policy,$clock)];
        return new AdapterRig($adapters,static function (string $name,mixed $value) use ($s): void {
            switch ($name) {
                case 'policy': $s->policy = $value; break;
                case 'egress': $s->egress = $value; break;
                case 'now': $s->now = $value; break;
                case 'deletion': $s->deletion = $value; break;
                case 'storeArtifacts':
                    [$scope,$snapshot,$packet,$profile] = $value;
                    $key = $s->fault === 'leaky-artifacts' ? 'same-local-id' : $scope->bindingDigest($s->f->bindingDigest())->value . ':' . $snapshot->snapshotId->value . ':' . $profile->value;
                    $s->artifacts[$key] = $packet;
                    break;
                default: throw new \InvalidArgumentException('Unknown fixture control');
            }
        },static function (string $name) use ($s): mixed {
            return match ($name) {
                'sends'=>$s->sends, 'blobCount'=>count($s->blobs), 'artifacts'=>array_values($s->artifacts),
                default=>throw new \InvalidArgumentException('Unknown observation'),
            };
        });
    }
}
