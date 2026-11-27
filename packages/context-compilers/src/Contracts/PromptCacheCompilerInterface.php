<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Contracts;

use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CompiledContextEnvelope;
use KnowKit\Domain\EvidenceSet;
use KnowKit\Domain\Layers\ResolvedLayerStack;
use KnowKit\Domain\Scope;

interface PromptCacheCompilerInterface
{
    /**
     * @param list<array<string, mixed>> $history
     * @param list<array<string, mixed>> $tools
     */
    public function compile(
        Scope $scope,
        ResolvedLayerStack $stack,
        EvidenceSet $evidence,
        CacheProfile $profile,
        array $history = [],
        array $tools = [],
        int $expectedCohortCallsPerHour = 1,
        ?string $previousPrefixHash = null,
    ): CompiledContextEnvelope;
}
