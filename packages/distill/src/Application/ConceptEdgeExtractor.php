<?php

declare(strict_types=1);

namespace KnowKit\Distill\Application;

use KnowKit\Application\Access\{AccessContext, PolicyChecks};
use KnowKit\Distill\Domain\{DistillJobKinds, DistillProfile, DistillRequest};
use KnowKit\Distill\Port\DistillModelTransport;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Domain\{ChunkId, ConceptEdge, DocumentRevisionRef, DomainFailure, Outcome, ResourceRef, Scope, TenantId, Ulid};

/** Extracts directed ConceptEdge relations under EgressPolicy and validates against concept-edges.schema.json. */
final readonly class ConceptEdgeExtractor
{
    public function __construct(
        private PolicyChecks $policy,
        private ContractValidator $validator,
        private DistillModelTransport $transport,
    ) {
    }

    /**
     * @param list<array{chunkId: ChunkId, text: string}> $chunks
     * @param \Closure(Scope, \KnowKit\Domain\EgressRequest): JsonObject $currentBinding
     * @param list<ResourceRef> $collections
     */
    public function extract(
        AccessContext $context,
        TenantId $tenantId,
        Ulid $kbId,
        Ulid $snapshotId,
        DocumentRevisionRef $revisionRef,
        array $chunks,
        DistillProfile $profile,
        \Closure $currentBinding,
        array $collections = [],
    ): JsonObject {
        $promptPayload = new JsonObject([
            'job' => DistillJobKinds::EXTRACT_CONCEPT_EDGES,
            'chunks' => array_map(
                static fn (array $chunk): JsonObject => new JsonObject([
                    'chunkId' => $chunk['chunkId']->value,
                    'text' => $chunk['text'],
                ]),
                $chunks,
            ),
        ]);
        $request = new DistillRequest(
            DistillJobKinds::extractConceptEdges(),
            $profile,
            $revisionRef,
            Jcs::canonicalize($promptPayload),
        );

        $output = DistillationOrchestrator::transmitGated(
            $this->policy,
            $this->validator,
            $this->transport,
            $context,
            $request,
            $currentBinding,
            $collections,
        );

        $raw = $output->assertUsable($profile->maxOutputBytes);
        $decoded = StrictJson::decode($raw, $profile->maxOutputBytes);
        if (!$decoded instanceof JsonObject || !is_array($decoded->get('edges'))) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_concept_edges');
        }

        /** @var list<ConceptEdge> $edges */
        $edges = [];
        foreach ($decoded->get('edges') as $item) {
            if (!$item instanceof JsonObject) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_concept_edges');
            }
            $from = $item->get('fromChunkId');
            $to = $item->get('toChunkId');
            $relation = $item->get('relation');
            $weightBps = $item->get('weightBps');
            $origin = $item->get('origin');
            if (!is_string($from) || !is_string($to) || !is_string($relation) || !is_int($weightBps) || !is_string($origin)) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_concept_edges');
            }
            try {
                $edges[] = new ConceptEdge(
                    fromChunkId: new ChunkId($from),
                    toChunkId: new ChunkId($to),
                    relation: $relation,
                    weightBps: $weightBps,
                    origin: $origin,
                );
            } catch (\InvalidArgumentException) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_concept_edges');
            }
        }

        usort($edges, static function (ConceptEdge $a, ConceptEdge $b): int {
            $cmp = strcmp($a->fromChunkId->value, $b->fromChunkId->value);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a->relation, $b->relation);
            return $cmp !== 0 ? $cmp : strcmp($a->toChunkId->value, $b->toChunkId->value);
        });

        $edgeWires = array_map(static fn (ConceptEdge $edge): JsonObject => $edge->toWire(), $edges);
        $body = new JsonObject([
            'schemaVersion' => 'concept-edges:1',
            'tenantId' => $tenantId->value,
            'kbId' => $kbId->value,
            'snapshotId' => $snapshotId->value,
            'edges' => $edgeWires,
        ]);
        $wire = $body->with('edgeSetDigest', HashDomain::ConceptEdgeSet->digest($body));

        $errors = $this->validator->validateValue($wire, 'urn:knowkit:schema:concept-edges:1.0');
        if ($errors !== []) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_concept_edges');
        }

        return $wire;
    }
}
