<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject};

/** Synthetic, immutable fixture identities. No framework/test-runner dependency. */
final readonly class Fixture
{
    public function __construct(public JsonObject $data, public ContractValidator $validator) {}
    public static function id(int $n): string { return '01J' . str_pad((string) $n, 23, '0', STR_PAD_LEFT); }
    public function now(): D\UtcInstant { return new D\UtcInstant($this->data->get('now')); }
    public function text(): string { return $this->data->get('text'); }
    /** @return 'A'|'B' Unknown tenants must not be labeled as project B. */
    private function projectOf(D\Scope $scope): string
    {
        foreach (['A', 'B'] as $project) {
            if ($scope->tenantId->equals($this->scope($project)->tenantId)) {
                return $project;
            }
        }
        throw new \InvalidArgumentException('Unknown fixture project');
    }
    public function scope(string $project = 'A'): D\Scope
    {
        $row = $this->data->get('projects')->get($project);
        return new D\Scope(new D\TenantId($row->get('tenantId')), new D\KbId(self::id(100)), new D\ActorId($row->get('actorId')),
            $row->get('purpose'), new D\Decimal('42'), new D\Sha256($row->get('hostScopeHash')));
    }
    public function source(string $project = 'A', int $version = 3): D\SourceVersionRef
    {
        return new D\SourceVersionRef($this->scope($project)->tenantId, new D\SourceId(self::id(2)),
            new D\SourceVersionId(self::id($version)), new D\SourceDigest(hash('sha256', $this->text())));
    }
    public function bytes(string $project = 'A'): D\SourceBytes { return new D\SourceBytes($this->source($project), $this->text(), 'text/plain'); }
    public function parserProfile(): D\ParserProfile { return new D\ParserProfile('plain', 'text', '1', new JsonObject()); }
    public function ir(string $project = 'A', int $version = 3): D\IrDocument
    {
        $text = $this->text();
        return new D\IrDocument(new JsonObject(['schemaVersion'=>'ir-1.0', 'tenantId'=>$this->scope($project)->tenantId->value,
            'sourceVersionId'=>$this->source($project, $version)->sourceVersionId->value, 'parserSignature'=>$this->parserProfile()->signature->toWire(),
            'pages'=>[], 'segments'=>[new JsonObject(['segmentId'=>'s1', 'text'=>$text, 'textDigest'=>hash('sha256', $text), 'status'=>'ok'])],
            'blocks'=>[new JsonObject(['blockId'=>'b1', 'kind'=>'text', 'parentId'=>null, 'order'=>0, 'origin'=>'source',
                'spans'=>[new JsonObject(['kind'=>'segment', 'segmentId'=>'s1', 'blockId'=>'b1', 'byteStart'=>0, 'byteEnd'=>strlen($text)])],
                'provenance'=>new JsonObject(['kind'=>'verbatim', 'supportingRefs'=>[]])])], 'assets'=>[],
            'coverage'=>new JsonObject(['state'=>'complete', 'counts'=>new JsonObject(['expected'=>1,'processed'=>1,'empty'=>0,'failed'=>0,'skipped'=>0]),
                'missingFeatures'=>[], 'diagnostics'=>[]])]), $this->validator);
    }
    public function chunk(string $project = 'A', int $version = 3): D\ChunkRef
    {
        return new D\ChunkRef(new D\DocumentRevisionRef($this->source($project, $version), new D\DocumentRevisionId(self::id(10)),
            new D\IrDigest(hash('sha256', $this->ir($project, $version)->canonical())), $this->parserProfile()->signature),
            new D\ChunkSetId(self::id(12)), new D\ChunkId(self::id($version === 3 ? 13 : 999)), new D\Sha256(hash('sha256', $this->text())));
    }
    public function binding(): JsonObject
    {
        return new JsonObject(['tenantPolicyEpoch'=>'7','kbPolicyEpoch'=>'42',
            'sources'=>[new JsonObject(['sourceId'=>self::id(2),'sourcePolicyEpoch'=>'3','lifecycleEpoch'=>'1'])]]);
    }
    public function bindingDigest(): D\Sha256 { return new D\Sha256(HashDomain::PolicyBinding->digest($this->binding())); }
    public function grant(): D\QueryGrant
    {
        return new D\QueryGrant(new JsonObject(['allowedCollectionIds'=>[], 'allowedSourceVersionIds'=>[self::id(3),self::id(4)],
            'grantEpoch'=>'42', 'policyBindingDigest'=>$this->bindingDigest()->value,'expiresAt'=>'2026-11-06T12:00:05Z']), $this->validator);
    }
    public function snapshot(string $project = 'A'): D\SnapshotRef
    {
        $scope = $this->scope($project);
        return new D\SnapshotRef($scope->tenantId, $scope->kbId, new D\SnapshotId($this->data->get('projects')->get($project)->get('snapshotId')),
            new D\Fingerprint(str_repeat('b',64)));
    }
    public function search(string $project = 'A', ?D\QueryGrant $grant = null): D\AuthorizedSearchScope
    {
        return D\AuthorizedSearchScope::materialize($this->scope($project), $grant ?? $this->grant(), $this->snapshot($project),
            new D\GenerationId(self::id(14)), [new D\SourceVersionId(self::id(3))], $this->now());
    }
    public function resource(string $project = 'A'): D\ResourceRef
    {
        return new D\ResourceRef($this->scope($project)->tenantId, 'source_version', $this->source($project)->sourceVersionId);
    }
    public function receipt(D\Decision $decision, D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $wire = new JsonObject(['decision'=>$decision->value, 'operation'=>$operation->value, 'resourceRefs'=>[$resource->toWire()],
            'binding'=>$this->binding(), 'ownerRevision'=>null,'checkedAt'=>'2026-11-06T12:00:00Z',
            'expiresAt'=>'2026-11-06T12:00:05Z','hostScopeHash'=>$scope->hostScopeHash->value]);
        return new D\DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), $this->validator);
    }
    public function reservation(string $project = 'A'): D\BlobWriteReservation
    {
        return new D\BlobWriteReservation(new D\ResourceRef($this->scope($project)->tenantId,'resource',new D\ResourceId(self::id(50))),
            $this->source($project)->sourceId,new D\WriterId(self::id(51)),new D\Decimal('2'),new D\Decimal('1'),
            new D\Sha256(hash('sha256',$this->text())),new D\Uint64((string) strlen($this->text())),null);
    }
    public function lease(int $fence = 2, string $project = 'A', ?string $token = null, string $epoch = '1'): D\Lease
    {
        return new D\Lease($this->scope($project), new D\JobId(self::id(60)),new D\AttemptId(self::id(61)), $this->source($project),
            new D\GenerationId(self::id(14)),new D\Decimal((string) $fence),$token ?? str_repeat('t',32),
            new D\UtcInstant('2026-11-06T12:00:04Z'),new D\UtcInstant('2026-11-06T12:00:05Z'),new D\Decimal($epoch));
    }
    public function tokenizer(): D\TokenizerIdentity
    {
        return new D\TokenizerIdentity(new D\AdapterId($this->data->get('tokenizerId')), $this->data->get('tokenizerVersion'),
            new D\Sha256($this->data->get('vocabularyDigest')));
    }
    public function deletion(string $result, D\AttemptId $attempt, D\ResourceRef $resource): D\DeletionReceipt
    {
        return new D\DeletionReceipt(new JsonObject(['attemptId'=>$attempt->value,'taskId'=>self::id(70),'resourceId'=>$resource->id->value,
            'inFlight'=>false,'result'=>$result,'providerReceipt'=>null,'knownExpiry'=>null,'observedAt'=>'2026-11-06T12:00:01Z']), $this->validator);
    }
    public function event(bool $revoke = false, string $project = 'A'): D\DomainEvent
    {
        $payload = $revoke ? new JsonObject(['sourceId'=>self::id(2),'reason'=>'source_revoked','lifecycleEpoch'=>'2','appliesTo'=>'future_versions'])
            : new JsonObject(['sourceId'=>self::id(2),'lifecycleState'=>'active','lifecycleEpoch'=>'1','sourcePolicyEpoch'=>'3','retentionHold'=>false]);
        return new D\DomainEvent(new JsonObject(['eventId'=>self::id($revoke ? 81 : 80),'schemaVersion'=>'event-1.0',
            'tenantId'=>$this->scope($project)->tenantId->value,'aggregateType'=>'source','aggregateId'=>self::id(2), 'aggregateVersion'=>$revoke ? '2' : '1',
            'type'=>$revoke ? 'source.revoked' : 'source.registered','occurredAt'=>'2026-11-06T12:00:01Z','causationId'=>null,'correlationId'=>null,
            'payload'=>$payload,'payloadDigest'=>HashDomain::DomainEventPayload->digest($payload)]), $this->validator);
    }
    public function space(string $revision = '1'): D\EmbeddingSpace
    { return new D\EmbeddingSpace('recorded','fixture',$revision,3,'none','1','document','query'); }

    public function citation(string $project = 'A'): D\CitationRef
    {
        $ref = $this->chunk($project); $textDigest = hash('sha256',$this->text());
        $span = D\TextLocator::segment(new D\LocalId('s1'),new D\LocalId('b1'),0,strlen($this->text()));
        return new D\CitationRef(new JsonObject(['citationId'=>self::id(94),'tenantId'=>$ref->revisionRef->sourceRef->tenantId->value,
            'sourceId'=>$ref->revisionRef->sourceRef->sourceId->value,'sourceVersionId'=>$ref->revisionRef->sourceRef->sourceVersionId->value,
            'documentRevisionId'=>$ref->revisionRef->documentRevisionId->value,'documentId'=>self::id(11),
            'sourceDigest'=>$ref->revisionRef->sourceRef->sourceDigest->value,'irDigest'=>$ref->revisionRef->irDigest->value,
            'spans'=>[$span->toWire()->with('textDigest',$textDigest)],'contentDigest'=>$textDigest,'origin'=>'source']),$this->validator);
    }

    /** Schema-valid packets for exercising a host's isolated test cache/usage persistence. */
    public function artifacts(D\Scope $scope,D\SnapshotRef $snapshot,JsonObject $selection): array
    {
        $project = $this->projectOf($scope);
        $selection = new D\Selection($selection->with('queryDigest',hash('sha256',$this->data->get('query')))
            ->with('generationId',self::id(14))->with('expiresAt','2026-11-06T12:00:05Z')
            ->with('limits',new JsonObject(['profile'=>'compact','maxFragments'=>4,'maxBytes'=>4096,
                'maxBytesPerFragment'=>1024,'maxAssets'=>0,'tokenBudget'=>null])),$this->validator);
        $fragments = [new JsonObject(['chunkRef'=>$this->chunk($project)->toWire(),'fragmentDigest'=>hash('sha256',$this->text())])];
        $usage = new D\Usage(new JsonObject(['usageId'=>self::id(92),'tenantId'=>$scope->tenantId->value,'disclosureAttemptId'=>self::id(93),
            'selectionId'=>self::id(91),'actorId'=>$scope->actorId->value,'purpose'=>$scope->purpose,'hostScopeHash'=>$scope->hostScopeHash->value,
            'runRef'=>null,'fragmentRefs'=>$fragments,'checkedAt'=>'2026-11-06T12:00:01Z',
            'policyBindingDigest'=>$this->bindingDigest()->value,'status'=>'prepared_disclosure']),$this->validator);
        $citation = $this->citation($project)->toWire();
        $evidence = new JsonObject(['tenantId'=>$scope->tenantId->value,'selectionId'=>self::id(91),'disclosureAttemptId'=>self::id(93),
            'usageId'=>self::id(92),'hostScopeHash'=>$scope->hostScopeHash->value,
            'scopeBindingDigest'=>$scope->bindingDigest($this->bindingDigest())->value,'snapshotRef'=>$snapshot->toWire(),
            'fragments'=>[new JsonObject(['citation'=>$citation,'text'=>$this->text(),'joiner'=>null,
                'fragmentDigest'=>hash('sha256',$this->text()),'provenanceKind'=>'verbatim'])],'totalBytes'=>strlen($this->text())]);
        $evidence = new D\EvidenceSet($evidence->with('evidenceSetDigest',HashDomain::EvidenceSet->digest($evidence)),$this->validator);
        return ['selection'=>$selection->toWire(),'cache'=>$selection->toWire(),'citations'=>[$citation],
            'usage'=>$usage->toWire(),'evidence'=>$evidence->toWire()];
    }
}
