<?php
declare(strict_types=1);
namespace KnowKit\Application\Publication\Manifest;

use KnowKit\Domain as D;
use KnowKit\Domain\ManifestCandidate as Candidate;
use KnowKit\Domain\Contract\{ContractValidator, JsonObject};

/** Preserves the approved index-manifest-1.0 wire format. Chunk digests live in bound chunkset manifests. */
final readonly class Builder
{
    public function __construct(private ContractValidator $validator) {}
    /** @param list<D\ChunkingResult> $sets @param list<JsonObject> $resources */
    public function build(D\Scope $scope, D\GenerationId $id, array $sets, array $resources, JsonObject $coverage,
        string $profile = 'postgres-fts-simple-v1', ?D\EmbeddingSpace $space = null): Candidate
    {
        if ($sets === []) { throw new D\DomainFailure(D\Outcome::Incompatible, 'manifest_empty'); }
        $members = []; $total = 0;
        foreach ($sets as $result) {
            $set = $result->chunkSet; $r = $set->revisionRef; $s = $r->sourceRef; $scope->assertTenant($s->tenantId);
            $members[] = new JsonObject(['sourceId'=>$s->sourceId->value,'sourceVersionId'=>$s->sourceVersionId->value,
                'sourceDigest'=>$s->sourceDigest->value,'documentRevisionId'=>$r->documentRevisionId->value,'irDigest'=>$r->irDigest->value,
                'chunkSetId'=>$set->chunkSetId->value,'chunkSetDigest'=>$result->manifestDigest->value,'chunkCount'=>count($set->chunks)]);
            $total += count($set->chunks);
        }
        usort($members, Candidate::compareMembers(...));
        usort($resources, static fn(JsonObject $a, JsonObject $b): int => strcmp($a->get('resourceId'), $b->get('resourceId')));
        $first = $sets[0]->chunkSet;
        $wire = new JsonObject(['schemaVersion'=>'index-manifest-1.0','tenantId'=>$scope->tenantId->value,'kbId'=>$scope->kbId->value,
            'generationId'=>$id->value,'profile'=>new JsonObject(['id'=>$profile,'kind'=>$space === null ? 'lexical' : 'vector',
                'parserSignature'=>$first->revisionRef->parserSignature->toWire(),'chunkerSignature'=>$first->chunkerSignature->toWire()]),
            'members'=>$members,'resources'=>$resources,'coverage'=>$coverage,
            'counts'=>new JsonObject(['members'=>count($members),'chunks'=>$total,'resources'=>count($resources)]),
            'embeddingSpaceDigest'=>$space?->digest()->value]);
        return new Candidate(new D\IndexManifest($wire, $this->validator), $sets, $resources);
    }
}
