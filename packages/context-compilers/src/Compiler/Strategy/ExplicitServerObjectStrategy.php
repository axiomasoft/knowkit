<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Compiler\Strategy;

use KnowKit\ContextCompilers\Compiler\PromptCacheCompiler;
use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CompiledContextEnvelope;
use KnowKit\Domain\Layers\ResolvedLayerStack;

final readonly class ExplicitServerObjectStrategy
{
    public function __construct(private ImplicitPrefixStrategy $fallback = new ImplicitPrefixStrategy())
    {
    }

    /** breakEvenCalls = ceil((write - 10000 + storage) / max(1, 10000 - read)) + 1, integer-only. */
    public static function breakEvenCalls(CacheProfile $profile): int
    {
        $numerator = max(0, $profile->writeCostBps - 10000 + $profile->hourlyStorageBps);
        $denominator = max(1, 10000 - $profile->readCostBps);

        return intdiv($numerator + $denominator - 1, $denominator) + 1;
    }

    public function apply(
        CompiledContextEnvelope $base,
        CacheProfile $profile,
        ResolvedLayerStack $stack,
        int $expectedCohortCallsPerHour,
    ): CompiledContextEnvelope {
        $prefix = PromptCacheCompiler::prefixTokens($base);
        if (
            $expectedCohortCallsPerHour < self::breakEvenCalls($profile)
            || $prefix < $profile->minCacheableTokens
        ) {
            return $this->fallback->apply($base, $profile, $stack, $expectedCohortCallsPerHour);
        }

        return PromptCacheCompiler::replace($base, [
            'externalCacheKey' => 'kk-cache:' . $stack->sessionPrefixDigest->value,
            'estimatedCachedTokens' => $prefix,
            'estimatedUncachedTokens' => PromptCacheCompiler::totalTokens($base) - $prefix,
        ]);
    }
}
