<?php
declare(strict_types=1);
namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

/** One exact sealed generation; a changed pointer requires a newly authorized request. */
final readonly class ActivationRequest
{
    public function __construct(public Lease $lease, public ManifestCandidate $candidate,
        public Decimal $expectedPointerVersion, public IdempotencyKey $key)
    {
        $s = $lease->scope; $w = $candidate->manifest->toWire();
        if ($w->get('tenantId') !== $s->tenantId->value || $w->get('kbId') !== $s->kbId->value
            || ($lease->generationId !== null && $lease->generationId->value !== $w->get('generationId'))) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        foreach ($candidate->sets as $set) {
            if ($set->chunkSet->revisionRef->sourceRef == $lease->sourceRef) { return; }
        }
        throw new DomainFailure(Outcome::Incompatible, 'job_source_mismatch');
    }

    /** @return list<SourceId> Sorted complete semantic lock set. */
    public function sources(): array
    {
        $ids = [];
        foreach ($this->candidate->manifest->toWire()->get('members') as $m) { $ids[$m->get('sourceId')] = new SourceId($m->get('sourceId')); }
        ksort($ids, SORT_STRING); return array_values($ids);
    }

    public function fingerprint(): Fingerprint
    {
        return new Fingerprint(HashDomain::IdempotencyRequest->digest(new JsonObject([
            'operation'=>Operation::Publish->value, 'scope'=>$this->lease->scope->toWire(),
            'generationId'=>$this->candidate->manifest->toWire()->get('generationId'),
            'manifestDigest'=>$this->candidate->digest->value, 'jobId'=>$this->lease->jobId->value,
            'expectedPointerVersion'=>$this->expectedPointerVersion->value,
        ])));
    }

    /** Exact generations carry their immutable full member/chunk/resource manifests. No latest token. */
    public function snapshotDigest(): Fingerprint
    {
        $w = $this->candidate->manifest->toWire();
        return new Fingerprint(HashDomain::PublicationSnapshotManifest->digest(new JsonObject([
            'tenantId'=>$w->get('tenantId'), 'kbId'=>$w->get('kbId'),
            'generations'=>[new JsonObject(['generationId'=>$w->get('generationId'),
                'manifestDigest'=>$this->candidate->digest->value, 'members'=>$w->get('members')])],
        ])));
    }
}
