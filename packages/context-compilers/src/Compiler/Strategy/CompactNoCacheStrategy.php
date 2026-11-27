<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Compiler\Strategy;

use KnowKit\ContextCompilers\Compiler\PromptCacheCompiler;
use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CompiledContextEnvelope;
use KnowKit\Domain\Layers\ResolvedLayerStack;

/** No server-side caching: compact fallback tier, elevated pruning, compaction signal. */
final readonly class CompactNoCacheStrategy
{
    public function __construct(private int $contextWindowTokens = 128000)
    {
        if ($contextWindowTokens < 1) {
            throw new \InvalidArgumentException('contextWindowTokens must be >= 1');
        }
    }

    public function apply(
        CompiledContextEnvelope $base,
        CacheProfile $profile,
        ResolvedLayerStack $stack,
        int $expectedCohortCallsPerHour,
    ): CompiledContextEnvelope {
        $history = 0;
        foreach ($base->historyBlocks as $block) {
            $history += $block->tokenEstimate;
        }
        $hints = $base->routingHints;
        $hints['layer_tier'] = 'compact_fallback';
        $hints['pruning'] = 'elevated';

        return PromptCacheCompiler::replace($base, [
            'externalCacheKey' => null,
            'routingHints' => $hints,
            'estimatedCachedTokens' => 0,
            'estimatedUncachedTokens' => PromptCacheCompiler::totalTokens($base),
            'compactionRecommended' => $history * 2 > $this->contextWindowTokens,
        ]);
    }
}
