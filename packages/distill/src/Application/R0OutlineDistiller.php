<?php

declare(strict_types=1);

namespace KnowKit\Distill\Application;

use KnowKit\Application\Access\{AccessContext, PolicyChecks};
use KnowKit\Application\Artifacts\DeterministicOutlineBuilder;
use KnowKit\Distill\Domain\{DistillJobKinds, DistillProfile, DistillRequest};
use KnowKit\Distill\Port\DistillModelTransport;
use KnowKit\Domain\Artifacts\{NavigationTreeArtifact, NavigationTreeNode};
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject, StrictJson};
use KnowKit\Domain\{DocumentRevisionRef, DomainFailure, IrDocument, Outcome, ResourceRef, Scope};

/** Enriches R0 navigation outline nodes with 20-35 token semantic signatures under EgressPolicy. */
final readonly class R0OutlineDistiller
{
    public function __construct(
        private PolicyChecks $policy,
        private ContractValidator $validator,
        private DistillModelTransport $transport,
        private DeterministicOutlineBuilder $outlineBuilder = new DeterministicOutlineBuilder(),
    ) {
    }

    /**
     * @param \Closure(Scope, \KnowKit\Domain\EgressRequest): JsonObject $currentBinding
     * @param list<ResourceRef> $collections
     */
    public function distill(
        AccessContext $context,
        IrDocument $ir,
        DocumentRevisionRef $revisionRef,
        DistillProfile $profile,
        \Closure $currentBinding,
        array $collections = [],
    ): NavigationTreeArtifact {
        $base = $this->outlineBuilder->build($ir, $revisionRef);

        $promptPayload = new JsonObject([
            'job' => DistillJobKinds::DISTILL_R0_OUTLINE,
            'targetTokensPerNode' => '20-35',
            'nodes' => array_map(
                static fn (NavigationTreeNode $node): JsonObject => $node->toWire(),
                $base->nodes,
            ),
        ]);
        $request = new DistillRequest(
            DistillJobKinds::r0Outline(),
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
        if (!$decoded instanceof JsonObject || !is_array($decoded->get('nodes'))) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_r0_outline_output');
        }

        /** @var array<string, array{title: string, terms: list<string>}> $updates */
        $updates = [];
        foreach ($decoded->get('nodes') as $item) {
            if (!$item instanceof JsonObject) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_r0_outline_output');
            }
            $nodeId = $item->get('nodeId');
            $title = $item->get('title');
            $terms = $item->get('terms');
            if (!is_string($nodeId) || !is_string($title) || !is_array($terms)) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_r0_outline_output');
            }
            $cleanTerms = [];
            foreach ($terms as $term) {
                if (!is_string($term) || trim($term) === '') {
                    throw new DomainFailure(Outcome::Incompatible, 'invalid_r0_outline_output');
                }
                if (!in_array($term, $cleanTerms, true)) {
                    $cleanTerms[] = $term;
                }
            }
            $updates[$nodeId] = [
                'title' => substr($title, 0, NavigationTreeNode::MAX_TITLE_BYTES),
                'terms' => array_slice($cleanTerms, 0, NavigationTreeNode::MAX_TERMS),
            ];
        }

        $enrichedNodes = [];
        foreach ($base->nodes as $node) {
            $update = $updates[$node->nodeId->value] ?? null;
            $enrichedNodes[] = new NavigationTreeNode(
                nodeId: $node->nodeId,
                parentId: $node->parentId,
                title: $update !== null ? $update['title'] : $node->title,
                depth: $node->depth,
                unitRange: $node->unitRange,
                formulaCount: $node->formulaCount,
                codeCount: $node->codeCount,
                tableCount: $node->tableCount,
                terms: $update !== null ? $update['terms'] : $node->terms,
            );
        }

        return new NavigationTreeArtifact(
            documentRevisionRef: $base->documentRevisionRef,
            nodes: $enrichedNodes,
            sourceByteLength: $base->sourceByteLength,
        );
    }
}
