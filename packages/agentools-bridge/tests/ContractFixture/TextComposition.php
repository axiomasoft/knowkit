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
        foreach ($this->evidenceContributors as $contributor) {
            if (!$contributor instanceof EvidenceContextContributor) {
                throw new RuntimeException('Typed evidence contributors required');
            }
        }
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
        // Contributors, bounds, and the compilation recheck precede native buildPlan.
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
        $compiled->dependencies();
        $compiled->authorize(context: $context, stage: BoundaryStage::Compilation);
        $bytes = self::withModelText(($this->legacyCompiler)(), $compiled->modelText());

        return ['fixture' => true, 'qualificationTier' => 'contract-scripted', 'upstream_seam' => 'pending',
            'requestBytes' => $bytes, 'requestHash' => hash('sha256', $bytes), 'compiled' => $compiled];
    }

    /** Append model text without turning JSON objects into lists. */
    private static function withModelText(string $bytes, string $modelText): string
    {
        if ($modelText === '') {
            return $bytes;
        }
        $wire = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        if (!$wire instanceof \stdClass
            || !isset($wire->messages[0])
            || !is_object($wire->messages[0])
            || !is_string($wire->messages[0]->content ?? null)) {
            throw new RuntimeException('Legacy request layout missing messages');
        }
        $wire->messages[0]->content .= $modelText;

        return json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
