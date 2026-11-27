<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Compiler\Strategy;

use KnowKit\ContextCompilers\Compiler\PromptCacheCompiler;
use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CompiledContextEnvelope;
use KnowKit\Domain\Layers\ResolvedLayerStack;

/** Bit-stable prefix; the provider caches automatically. expectedPrefixHash is verified each turn. */
final readonly class ImplicitPrefixStrategy
{
    public function apply(
        CompiledContextEnvelope $base,
        CacheProfile $profile,
        ResolvedLayerStack $stack,
        int $expectedCohortCallsPerHour,
    ): CompiledContextEnvelope {
        $prefix = PromptCacheCompiler::prefixTokens($base);
        $cached = $prefix >= $profile->minCacheableTokens ? $prefix : 0;

        return PromptCacheCompiler::replace($base, [
            'externalCacheKey' => null,
            'estimatedCachedTokens' => $cached,
            'estimatedUncachedTokens' => PromptCacheCompiler::totalTokens($base) - $cached,
        ]);
    }
}
