<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Compiler\Strategy;

use KnowKit\ContextCompilers\Compiler\PromptCacheCompiler;
use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CompiledContextEnvelope;
use KnowKit\Domain\Layers\ResolvedLayerStack;

/** Local radix/KV-cache engines: isolates the KV cache with cache_salt (no cross-tenant sharing). */
final readonly class LocalRadixPrefixStrategy
{
    public function apply(
        CompiledContextEnvelope $base,
        CacheProfile $profile,
        ResolvedLayerStack $stack,
        int $expectedCohortCallsPerHour,
    ): CompiledContextEnvelope {
        $pureCatalog = $stack->nodes !== [];
        foreach ($stack->nodes as $node) {
            if ($node->backing->kind !== 'catalog_mount') {
                $pureCatalog = false;
                break;
            }
        }
        $static = $stack->staticPrefixDigest->value;
        $salt = $pureCatalog
            ? 'catalog:' . $static
            : 'tenant:' . hash('sha256', $stack->tenantId->value . ':' . $static);

        $hints = $base->routingHints;
        $hints['cache_salt'] = $salt;
        $prefix = PromptCacheCompiler::prefixTokens($base);
        $cached = $prefix >= $profile->minCacheableTokens ? $prefix : 0;

        return PromptCacheCompiler::replace($base, [
            'externalCacheKey' => null,
            'routingHints' => $hints,
            'estimatedCachedTokens' => $cached,
            'estimatedUncachedTokens' => PromptCacheCompiler::totalTokens($base) - $cached,
        ]);
    }
}
