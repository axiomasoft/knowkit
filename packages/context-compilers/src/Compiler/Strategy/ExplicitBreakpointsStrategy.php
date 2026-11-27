<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Compiler\Strategy;

use KnowKit\ContextCompilers\Compiler\PromptCacheCompiler;
use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CompiledBlock;
use KnowKit\ContextCompilers\Domain\CompiledContextEnvelope;
use KnowKit\Domain\Layers\ResolvedLayerStack;

/** At most maxBreakpoints markers, in order: tools, system, static data, stable history. */
final readonly class ExplicitBreakpointsStrategy
{
    public function apply(
        CompiledContextEnvelope $base,
        CacheProfile $profile,
        ResolvedLayerStack $stack,
        int $expectedCohortCallsPerHour,
    ): CompiledContextEnvelope {
        $prefix = PromptCacheCompiler::prefixTokens($base);
        if ($prefix < $profile->minCacheableTokens || $profile->maxBreakpoints < 1) {
            return PromptCacheCompiler::replace($base, [
                'estimatedCachedTokens' => 0,
                'estimatedUncachedTokens' => PromptCacheCompiler::totalTokens($base),
            ]);
        }

        $budget = $profile->maxBreakpoints;
        $hints = $base->routingHints;

        if ($base->canonicalTools !== []) {
            $hints['cache_breakpoint_tools'] = 'ephemeral';
            --$budget;
        }
        $system = self::mark($base->systemBlocks, $budget);
        $static = self::mark($base->staticDataPrefixBlocks, $budget);

        $history = $base->historyBlocks;
        $stableCount = max(0, count($history) - 1);
        $stable = self::mark(array_slice($history, 0, $stableCount), $budget);
        $history = [...$stable, ...array_slice($history, $stableCount)];

        $cached = $prefix;
        foreach ($stable as $block) {
            $cached += $block->tokenEstimate;
        }

        return PromptCacheCompiler::replace($base, [
            'routingHints' => $hints,
            'systemBlocks' => $system,
            'staticDataPrefixBlocks' => $static,
            'historyBlocks' => $history,
            'estimatedCachedTokens' => $cached,
            'estimatedUncachedTokens' => PromptCacheCompiler::totalTokens($base) - $cached,
        ]);
    }

    /**
     * Marks the last block of a group if budget remains.
     *
     * @param list<CompiledBlock> $blocks
     * @return list<CompiledBlock>
     */
    private static function mark(array $blocks, int &$budget): array
    {
        if ($blocks === [] || $budget < 1) {
            return $blocks;
        }
        --$budget;
        $last = array_pop($blocks);
        $blocks[] = new CompiledBlock(
            $last->role,
            $last->trustTier,
            $last->content,
            'ephemeral',
            $last->tokenEstimate,
            $last->contentDigest,
        );

        return $blocks;
    }
}
