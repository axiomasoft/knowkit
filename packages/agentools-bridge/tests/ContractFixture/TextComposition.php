<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use AgenToolsPlatform\Core\Security\ExecutionContext;
use Closure;
use RuntimeException;

/** Scripted contract compiler only. No Agentools journal, runtime, relay or persistence. */
final readonly class TextComposition
{
    /** @param list<EvidenceContextContributor> $evidenceContributors */
    public function __construct(
        private Closure $legacyCompiler,
        private array $evidenceContributors = [],
    ) {
    }

    public function compile(
        ExecutionContext $context,
        IncomingInput $input,
        EvidenceContextRequest $request,
        EffectiveProfile $profile,
        array $authorities,
    ): array {
        if ($request->hostScopeHash !== Contract::hostScopeHash($context)) {
            throw new RuntimeException('Trusted hostScopeHash mismatch');
        }
        $bytes = ($this->legacyCompiler)();
        $contributions = [];
        foreach ($this->evidenceContributors as $contributor) {
            $contribution = $contributor->collect(context: $context, input: $input, request: $request);
            if ($contribution->pin->digest() !== $contributor->pin()->digest()) {
                throw new RuntimeException('Collected contributor pin changed');
            }
            $contributions[] = $contribution;
        }
        self::assertBounds(contributions: $contributions, request: $request, profile: $profile);
        $compiled = new CompiledEvidence(contributions: $contributions, authorities: $authorities);
        $compiled->authorize(context: $context, stage: BoundaryStage::Compilation);
        $compiled->dependencies();
        if ($contributions !== []) {
            $wire = json_decode($bytes, associative: true, flags: JSON_THROW_ON_ERROR);
            $wire['messages'][0]['content'] .= $compiled->modelText();
            $bytes = json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return ['fixture' => true, 'qualificationTier' => 'contract-scripted', 'upstream_seam' => 'pending',
            'requestBytes' => $bytes, 'requestHash' => hash('sha256', $bytes), 'compiled' => $compiled];
    }

    private static function assertBounds(array $contributions, EvidenceContextRequest $request, EffectiveProfile $profile): void
    {
        $fragments = 0;
        $bytes = 0;
        foreach ($contributions as $contribution) {
            foreach ($contribution->blocks as $block) {
                $fragments++;
                $bytes += strlen($block->modelView);
            }
        }
        if (count($contributions) > 32 || $fragments > $profile->fragmentLimit($request)
            || $bytes > $profile->byteLimit($request)) {
            throw new RuntimeException('Aggregate evidence limits exceeded');
        }
    }
}
