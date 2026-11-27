<?php

declare(strict_types=1);

namespace KnowKit\Distill\Application;

use KnowKit\Application\Access\{AccessContext, PolicyChecks};
use KnowKit\Application\Artifacts\AtomicSpanGuard;
use KnowKit\Distill\Domain\{DistillJobKinds, DistillProfile, DistillRequest};
use KnowKit\Distill\Port\DistillModelTransport;
use KnowKit\Domain\Artifacts\{PropositionItem, PropositionSetArtifact, ProtectedAtom};
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject, StrictJson};
use KnowKit\Domain\{DocumentRevisionRef, DomainFailure, IrDocument, LocalId, Outcome, ResourceRef, Scope, TextLocator};

/** Distills R2 atomic propositions with a 15-25 token context header and exact R3 TextLocator bindings. */
final readonly class R2PropositionDistiller
{
    public function __construct(
        private PolicyChecks $policy,
        private ContractValidator $validator,
        private DistillModelTransport $transport,
        private AtomicSpanGuard $guard = new AtomicSpanGuard(),
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
    ): PropositionSetArtifact {
        $atoms = $this->guard->extractRequiredAtoms($ir);
        $extracted = DistillationOrchestrator::extractIrBlocks($ir);
        $nonce = R1DigestDistiller::selectCollisionFreeNonce($revisionRef, $atoms, $extracted['corpusText']);

        /** @var array<string, string> $markerByBlockId */
        $markerByBlockId = [];
        /** @var list<array{marker: string, atom: ProtectedAtom}> $indexedAtoms */
        $indexedAtoms = [];
        foreach ($atoms as $index => $atom) {
            $marker = R1DigestDistiller::markerFor($nonce, $index);
            $markerByBlockId[$atom->atomId->value] = $marker;
            $indexedAtoms[] = ['marker' => $marker, 'atom' => $atom];
        }

        $maskedBlocks = [];
        /** @var array<string, list<TextLocator>> $blockLocators */
        $blockLocators = [];
        foreach ($extracted['blocks'] as $block) {
            $blockId = $block['blockId'];
            $blockLocators[$blockId] = $block['locators'];
            $maskedBlocks[] = new JsonObject([
                'blockId' => $blockId,
                'kind' => $block['kind'],
                'text' => $markerByBlockId[$blockId] ?? $block['text'],
            ]);
        }

        $promptPayload = new JsonObject([
            'job' => DistillJobKinds::DISTILL_R2_PROPOSITIONS,
            'contextHeaderTokens' => '15-25',
            'blocks' => $maskedBlocks,
        ]);
        $request = new DistillRequest(
            DistillJobKinds::r2Propositions(),
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
        if (!$decoded instanceof JsonObject || !is_array($decoded->get('propositions'))) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_r2_propositions_output');
        }

        $rawProps = $decoded->get('propositions');
        $combinedStatements = [];
        foreach ($rawProps as $prop) {
            if (!$prop instanceof JsonObject
                || !is_string($prop->get('propositionId'))
                || !is_string($prop->get('statement'))
                || !is_array($prop->get('blockIds'))) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_r2_propositions_output');
            }
            $combinedStatements[] = $prop->get('statement');
        }
        R1DigestDistiller::assertExactMarkers($nonce, $indexedAtoms, implode("\n", $combinedStatements));

        $items = [];
        foreach ($rawProps as $prop) {
            /** @var JsonObject $prop */
            $restored = R1DigestDistiller::restoreMarkers((string) $prop->get('statement'), $indexedAtoms);
            $locators = [];
            foreach ($prop->get('blockIds') as $blockId) {
                if (!is_string($blockId) || !isset($blockLocators[$blockId])) {
                    throw new DomainFailure(Outcome::Incompatible, 'invalid_r2_propositions_output');
                }
                foreach ($blockLocators[$blockId] as $locator) {
                    $locators[] = $locator;
                }
            }
            $items[] = new PropositionItem(
                propositionId: new LocalId((string) $prop->get('propositionId')),
                statement: $restored['text'],
                verbatimAtoms: $restored['atoms'],
                supportingSpans: $locators,
            );
        }

        $artifact = new PropositionSetArtifact(
            documentRevisionRef: $revisionRef,
            propositions: $items,
        );
        $this->guard->assertPreserved($ir, $artifact);

        return $artifact;
    }
}
