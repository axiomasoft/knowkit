<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, JsonObject, ScopeBinding};
use KnowKit\Port as P;
use KnowKit\Registry\{HostProfile, TokenBudget};

/** Required baseline scenarios are owned by this SDK, not delegated to host assertions. */
final class CorpusContract extends AdapterContract
{
    public function execute(string $caseId, AdapterHost $host, HostProfile $profile, Fixture $f): void
    {
        if (in_array($caseId,['R36-A01','R36-A02','DT-R02','DT-R03','DT-R05','DT-R06'],true)) {
            (new RegistryCorpus())->execute($caseId,$host,$profile,$f); return;
        }
        $rig = $host->prepare($f,$caseId);
        switch ($caseId) {
            case 'SDK-POLICY':
                $policy = $rig->adapter('policy',P\AccessPolicy::class);
                $grant = $policy->authorizeQuery($f->scope());
                self::check($f->search('A',$grant)->allowedSourceVersionIds == $f->search()->allowedSourceVersionIds, 'grant_widened_scope');
                foreach ([D\Decision::Deny,D\Decision::Unknown,D\Decision::Allow] as $decision) {
                    $rig->control('policy',$decision);
                    $receipt = $policy->decide($f->scope(),$f->resource(),D\Operation::Disclose);
                    self::check($receipt->toWire()->get('decision') === $decision->value, 'policy_rewrote_decision');
                    $call = fn() => $receipt->requireAllow($f->scope(),$f->resource(),D\Operation::Disclose,$f->now(),$f->bindingDigest());
                    if ($decision === D\Decision::Allow) { $call(); }
                    else { self::failure($call,$decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown); }
                }
                $receipt = $policy->decide($f->scope(),$f->resource(),D\Operation::Disclose);
                self::failure(fn() => $receipt->requireAllow($f->scope(),$f->resource(),D\Operation::Disclose,
                    new D\UtcInstant('2026-11-06T12:00:05Z'),$f->bindingDigest()),D\Outcome::Denied);
                self::failure(fn() => $policy->decide($f->scope('B'),$f->resource(),D\Operation::Disclose),D\Outcome::Denied);
                break;
            case 'SDK-BLOB':
                $blob = $rig->adapter('blob',P\BlobStore::class);
                $ref = $blob->putImmutable($f->reservation(),new MemoryStream($f->text()));
                self::check($ref->digest->value === hash('sha256',$f->text()) && $ref->byteLength->value === (string) strlen($f->text()), 'blob_identity_changed');
                self::failure(fn() => $blob->putImmutable($f->reservation(),new MemoryStream('changed bytes')),D\Outcome::Incompatible);
                self::check(self::read($blob->open($ref)) === $f->text(), 'mutable_blob');
                $foreign = new D\BlobRef($f->scope('B')->tenantId,$ref->sourceId,$ref->digest,$ref->byteLength,$ref->storageId,$ref->objectVersion,$ref->ownerKind);
                self::failure(fn() => $blob->open($foreign),D\Outcome::Denied);
                self::failure(fn() => $blob->exists($foreign),D\Outcome::Denied);
                break;
            case 'SDK-PARSER':
                $parser = $rig->adapter('parser',P\DocumentParser::class);
                $parser->capabilities()->require(D\Capability::ParserText,'ir-1.0');
                $document = $parser->parse($f->bytes(),$f->parserProfile());
                self::check($document->canonical() === $f->ir()->canonical(), 'canonical_ir_or_provenance_changed');
                $span = D\TextLocator::segment(new D\LocalId('s1'),new D\LocalId('b1'),0,strlen($f->text()));
                self::check($span->slice($document->toWire()->get('segments')[0]->get('text')) === $f->text(), 'locator_not_canonical');
                self::failure(fn() => $parser->parse($f->bytes(),new D\ParserProfile('new','text','2',new JsonObject())),D\Outcome::Incompatible);
                break;
            case 'SDK-SEARCH':
            case 'R41-A02':
                $index = $rig->adapter('search',P\LexicalIndex::class);
                $policy = $rig->adapter('policy',P\AccessPolicy::class);
                // Prepare seeds higher-scoring excluded and foreign rows BEFORE the eligible row.
                $list = $index->search($f->search('A',$policy->authorizeQuery($f->scope())),new D\Query($f->data->get('query')),1);
                self::check(count($list->candidates) === 1 && $list->candidates[0]->ref == $f->chunk(), 'postfilter_only_or_foreign_result');
                if ($caseId === 'R41-A02') {
                    self::failure(fn() => $rig->adapter('embedding',P\EmbeddingProvider::class),D\Outcome::Unavailable);
                    self::failure(fn() => $rig->adapter('dependencies',P\DependencyStore::class),D\Outcome::Unavailable);
                    $selection = new JsonObject(['selectionId'=>Fixture::id(91),'tenantId'=>$f->scope()->tenantId->value,
                        'hostScopeHash'=>$f->scope()->hostScopeHash->value,'policyBinding'=>$f->binding(),
                        'scopeBindingDigest'=>$f->scope()->bindingDigest($f->bindingDigest())->value,
                        'snapshotId'=>$f->snapshot()->snapshotId->value,'candidateRefs'=>[$f->chunk()->toWire()],'runRef'=>null]);
                    $packet = $f->artifacts($f->scope(),$f->snapshot(),$selection);
                    self::check($packet['evidence']->get('fragments')[0]->get('citation')->get('origin') === 'source','core_evidence_requires_extension');
                }
                $rig->control('policy',D\Decision::Unknown);
                self::failure(fn() => $policy->decide($f->scope(),$f->resource(),D\Operation::Disclose)
                    ->requireAllow($f->scope(),$f->resource(),D\Operation::Disclose,$f->now(),$f->bindingDigest()),D\Outcome::Unknown);
                break;
            case 'SDK-SPACE':
                $vector = $rig->adapter('vector',P\VectorIndex::class);
                self::check(count($vector->search($f->search(),new D\Query('q'),1,$f->space())->candidates) === 1,'vector_missing');
                self::failure(fn() => $vector->search($f->search(),new D\Query('q'),1,$f->space('changed')),D\Outcome::Incompatible,'space_mismatch');
                break;
            case 'SDK-TOKENIZER':
                $tokenizer = $rig->adapter('tokenizer',P\Tokenizer::class);
                $tokenizer->capabilities()->requireExact($f->tokenizer());
                foreach ($f->data->get('tokenVectors') as $vector) {
                    self::check($tokenizer->count($vector->get('text'),$f->tokenizer()) === $vector->get('count'),'inexact_token_count');
                }
                $wrong = new D\TokenizerIdentity($f->tokenizer()->id,$f->tokenizer()->version . '-changed',$f->tokenizer()->vocabularyDigest);
                self::failure(fn() => $tokenizer->count('x',$wrong),D\Outcome::Incompatible,'tokenizer_mismatch');
                self::failure(fn() => $tokenizer->count("\xff",$f->tokenizer()),D\Outcome::Incompatible);
                break;
            case 'SDK-BUDGET':
                TokenBudget::require('😀',4);
                self::failure(fn() => TokenBudget::require('😀',3),D\Outcome::Incompatible,'byte_budget_exceeded');
                self::failure(fn() => TokenBudget::require('a',4,1),D\Outcome::Incompatible,'tokenizer_required');
                $tokenizer = $rig->adapter('tokenizer',P\Tokenizer::class);
                $positive = null;
                foreach ($f->data->get('tokenVectors') as $vector) { if ($vector->get('count') > 0) { $positive = $vector; break; } }
                if (!$positive instanceof JsonObject) { throw new \RuntimeException('positive_token_vector_required'); }
                $text = (string) $positive->get('text'); $count = (int) $positive->get('count');
                TokenBudget::require($text,strlen($text),$count,$tokenizer,$f->tokenizer());
                self::failure(fn() => TokenBudget::require($text,strlen($text),$count - 1,$tokenizer,$f->tokenizer()),D\Outcome::Incompatible,'token_budget_exceeded');
                break;
            case 'SDK-JOBS':
                $queue = $rig->adapter('jobs',P\JobQueue::class);
                $lease = $queue->lease(new D\JobType('ingest'),new D\WorkerId(Fixture::id(62)));
                if ($lease === null) { throw new \RuntimeException('no_fixture_lease'); }
                foreach ([$f->lease(1),$f->lease(2,'B'),$f->lease(2,'A',str_repeat('x',32)),$f->lease(2,'A',null,'2')] as $stale) {
                    self::check(!$queue->heartbeat($stale) && !$queue->checkpoint($stale,new D\Checkpoint(new D\Sha256(str_repeat('a',64)),false))
                        && !$queue->complete($stale,new D\JobResult(D\Outcome::Success,new D\Sha256(str_repeat('b',64)))),'stale_fence_accepted');
                }
                self::check($queue->heartbeat($lease),'current_lease_rejected');
                $rig->control('now',new D\UtcInstant('2026-11-06T12:00:05Z'));
                self::check(!$queue->heartbeat($lease) && !$queue->checkpoint($lease,new D\Checkpoint(new D\Sha256(str_repeat('a',64)),false))
                    && !$queue->complete($lease,new D\JobResult(D\Outcome::Success,new D\Sha256(str_repeat('b',64)))),'expired_lease_accepted');
                self::failure(fn() => D\DomainFailure::requireWrite(false),D\Outcome::Incompatible);
                break;
            case 'SDK-OUTBOX':
                $outbox = $rig->adapter('outbox',P\Outbox::class); $delivery = $rig->adapter('delivery',P\OutboxDelivery::class);
                $outbox->append($f->event()); $outbox->append($f->event());
                $leases = $delivery->leaseDelivery(10);
                self::check(count($leases) === 1,'duplicate_outbox_effect'); $lease = $leases[0];
                $forged = new D\DeliveryLease($lease->tenantId,$lease->eventId,$lease->fence,str_repeat('x',32),$lease->expiresAt);
                self::check(!$delivery->ack($forged),'forged_ack');
                $rig->control('now',new D\UtcInstant('2026-11-06T12:00:06Z'));
                $retry = $delivery->leaseDelivery(10);
                self::check(count($retry) === 1 && $retry[0]->fence != $lease->fence && !$delivery->ack($lease),'lost_delivery_not_fenced');
                $firstAck = $delivery->ack($retry[0]);
                /** @var bool $secondAck */
                $secondAck = $delivery->ack($retry[0]);
                self::check($firstAck && !$secondAck && $delivery->leaseDelivery(10) === [],'duplicate_ack');
                self::failure(fn() => $outbox->append($f->event(false,'B')),D\Outcome::Denied);
                break;
            case 'SDK-DELETION':
                $blob = $rig->adapter('blob',P\BlobStore::class);
                $ref = $blob->putImmutable($f->reservation(),new MemoryStream($f->text()));
                foreach (['unknown','unsupported'] as $state) {
                    $rig->control('deletion',$state);
                    $receipt = $blob->delete($f->reservation()->resource,new D\AttemptId(Fixture::id(71)))->toWire();
                    self::check($receipt->get('result') === $state && $blob->exists($ref),'uncertain_delete_reported_success');
                }
                $rig->control('deletion','deleted');
                $receipt = $blob->delete($f->reservation()->resource,new D\AttemptId(Fixture::id(72)))->toWire();
                self::check($receipt->get('result') === 'deleted' && !$blob->exists($ref) && $rig->observe('blobCount') === 0,'misleading_delete_receipt');
                self::failure(fn() => $blob->delete($f->reservation('B')->resource,new D\AttemptId(Fixture::id(72))),D\Outcome::Denied);
                break;
            case 'SDK-EGRESS':
                $provider = $rig->adapter('embedding',P\EmbeddingProvider::class);
                $request = new D\EgressRequest(new JsonObject(['operation'=>'retrieve',
                    'destination'=>new JsonObject(['providerId'=>'recorded','accountId'=>'test','region'=>'local','modelId'=>'fixture']),
                    'items'=>[new JsonObject(['category'=>'QUERY','contentDigest'=>hash('sha256','provider input'),'sourceVersionRefs'=>[]])],
                    'manifestDigest'=>hash('sha256','complete synthetic outbound manifest')]),$f->validator);
                $space = new D\SpaceRef('recorded','fixture','1',3,'none','1','document','query');
                $documentRequest = new D\EgressRequest($request->toWire()->with('operation','ingest')->with('items',[
                    new JsonObject(['category'=>'DOCUMENT_CONTENT','contentDigest'=>hash('sha256','provider input'),
                        'sourceVersionRefs'=>[$f->source()->toWire()]])]),$f->validator);
                $documentAccess = new D\EmbeddingAccess($f->scope(),$documentRequest);
                $inputs = [new D\EmbeddingInput($f->chunk(),'provider input')];
                foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
                    $rig->control('egress',$decision);
                    $result = $provider->embedQuery(new D\EmbeddingAccess($f->scope(),$request),new D\QueryInput('provider input'),$space);
                    self::check($result->outcome === ($decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown)
                        && $rig->observe('sends') === 0,'egress_leak');
                    self::check($provider->embedDocuments($documentAccess,$inputs,$space)->outcome ===
                        ($decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown) && $rig->observe('sends') === 0,'document_egress_leak');
                }
                $rig->control('egress',D\Decision::Allow);
                self::check($provider->embedQuery(new D\EmbeddingAccess($f->scope(),$request),new D\QueryInput('provider input'),$space)->outcome === D\Outcome::Success
                    && $rig->observe('sends') === 1,'allowed_egress_missing');
                self::check($provider->embedDocuments($documentAccess,$inputs,$space)->outcome === D\Outcome::Success
                    && $rig->observe('sends') === 2,'allowed_document_egress_missing');
                self::check($provider->embedQuery(new D\EmbeddingAccess($f->scope(),$request),new D\QueryInput('changed outbound bytes'),$space)->outcome === D\Outcome::Incompatible
                    && $rig->observe('sends') === 2,'outbound_manifest_omission');
                $rig->control('egress',D\Decision::Unknown);
                self::check($provider->embedQuery(new D\EmbeddingAccess($f->scope(),$request),new D\QueryInput('provider input'),$space)->outcome === D\Outcome::Unknown
                    && $rig->observe('sends') === 2,'cached_egress_allow');
                break;
            case 'R41-A01':
                $dependencies = $rig->adapter('dependencies',P\DependencyStore::class);
                $node = new D\ResourceRef($f->scope()->tenantId,'document_revision',new D\DocumentRevisionId(Fixture::id(90)));
                $parent = $f->resource();
                $dependencies->register(new D\Node($node,new D\Fingerprint(str_repeat('a',64))),new D\Parents($f->scope()->tenantId,[$parent]));
                $refs = new D\NodeRefs($f->scope()->tenantId,[$node]);
                self::check($dependencies->checkAncestors($refs)->decision === D\Decision::Allow,'active_parent_missing');
                $dependencies->invalidate(new D\LifecycleEvent($f->event(true)));
                $report = $dependencies->checkAncestors($refs);
                self::check($report->decision !== D\Decision::Allow && in_array($parent,$report->blockedAncestors->refs,false),'revoked_parent_confirmed');
                break;
            case 'SDK-ISOLATION':
                $index = $rig->adapter('search',P\LexicalIndex::class);
                $artifacts = [];
                foreach (['A','B'] as $project) {
                    $scope = $f->scope($project); $search = $f->search($project);
                    $result = $index->search($search,new D\Query($f->data->get('query')),1);
                    self::check(count($result->candidates) === 1 && $result->candidates[0]->ref == $f->chunk($project),'cross_project_candidate');
                    $selection = new JsonObject(['selectionId'=>Fixture::id(91),'tenantId'=>$scope->tenantId->value,'hostScopeHash'=>$scope->hostScopeHash->value,
                        'policyBinding'=>$f->binding(),'scopeBindingDigest'=>$scope->bindingDigest($f->bindingDigest())->value,
                        'snapshotId'=>$search->snapshotId->value,'candidateRefs'=>[$f->chunk($project)->toWire()],'runRef'=>null]);
                    self::check(ScopeBinding::checkSelection($scope->toWire(),$selection)['outcome'] === 'success','own_selection_failed');
                    $artifacts[$project] = $selection;
                    $rig->control('storeArtifacts',[$scope,$f->snapshot($project),$f->artifacts($scope,$f->snapshot($project),$selection),$profile->digest()]);
                }
                self::check(ScopeBinding::checkSelection($f->scope('B')->toWire(),$artifacts['A'])['outcome'] === 'denied','cross_host_selection');
                // Vary each binding axis independently, retaining tenant and all local IDs.
                $a = $f->scope(); $snapshot = $f->snapshot();
                $variations = [
                    [new D\Scope($a->tenantId,$a->kbId,$f->scope('B')->actorId,$a->purpose,$a->policyEpoch,$a->hostScopeHash),$snapshot,$profile->digest()],
                    [new D\Scope($a->tenantId,$a->kbId,$a->actorId,$f->scope('B')->purpose,$a->policyEpoch,$a->hostScopeHash),$snapshot,$profile->digest()],
                    [new D\Scope($a->tenantId,$a->kbId,$a->actorId,$a->purpose,$a->policyEpoch,$f->scope('B')->hostScopeHash),$snapshot,$profile->digest()],
                    [$a,new D\SnapshotRef($a->tenantId,$a->kbId,$f->snapshot('B')->snapshotId,$snapshot->manifestDigest),$profile->digest()],
                    [$a,$snapshot,new D\Fingerprint(str_repeat('f',64))],
                ];
                $expected = [$f->artifacts($f->scope('A'),$f->snapshot('A'),$artifacts['A']),
                    $f->artifacts($f->scope('B'),$f->snapshot('B'),$artifacts['B'])];
                $expectedScopes = [$f->scope('A'),$f->scope('B')];
                foreach ($variations as [$scope,$snap,$fingerprint]) {
                    $selection = $artifacts['A']->with('scopeBindingDigest',$scope->bindingDigest($f->bindingDigest())->value)
                        ->with('hostScopeHash',$scope->hostScopeHash->value)->with('snapshotId',$snap->snapshotId->value);
                    if ($scope != $a) { self::check(ScopeBinding::checkSelection($scope->toWire(),$artifacts['A'])['outcome'] === 'denied','binding_axis_ignored'); }
                    $packet = $f->artifacts($scope,$snap,$selection);
                    $rig->control('storeArtifacts',[$scope,$snap,$packet,$fingerprint]);
                    $expected[] = $packet;
                    $expectedScopes[] = $scope;
                }
                $stored = $rig->observe('artifacts');
                self::check(is_array($stored) && count($stored) === count($expected),'cross_project_cache_collision');
                foreach ($expected as $index => $expectedPacket) {
                    $packet = $stored[$index];
                    $selection = $expectedPacket['selection'];
                    self::check($packet == $expectedPacket
                        && $packet['usage']->get('tenantId') === $selection->get('tenantId')
                        && $packet['usage']->get('actorId') === $expectedScopes[$index]->actorId->value
                        && $packet['usage']->get('purpose') === $expectedScopes[$index]->purpose
                        && $packet['usage']->get('hostScopeHash') === $selection->get('hostScopeHash')
                        && $packet['usage']->get('fragmentRefs')[0]->get('chunkRef') == $selection->get('candidateRefs')[0],
                        'cross_project_artifact_leak');
                }
                break;
            default: throw new \InvalidArgumentException('Unknown required corpus case');
        }
    }

    private static function read(P\ReadableStream $stream): string
    {
        $bytes = '';
        try {
            while (!$stream->eof()) {
                $part = $stream->read(4096);
                if ($part === '' || strlen($bytes) + strlen($part) > 1048576) { throw new \RuntimeException('unbounded_stream'); }
                $bytes .= $part;
            }
        } finally { $stream->close(); }
        return $bytes;
    }
}
