<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Domain;

use KnowKit\Domain\Sha256;

final readonly class CompiledContextEnvelope
{
    /**
     * @param list<array<string, mixed>> $canonicalTools
     * @param list<CompiledBlock> $systemBlocks ONLY TrustTier::TrustedDirective
     * @param list<CompiledBlock> $staticDataPrefixBlocks CuratedReference / pinned R0-R1 (role='user', NEVER 'system')
     * @param list<CompiledBlock> $historyBlocks
     * @param list<CompiledBlock> $volatileSuffixBlocks UntrustedEvidence & DynamicOverlay ONLY here
     * @param array<string, string> $routingHints e.g. ['cache_salt' => 'tenant:...']
     */
    public function __construct(
        public Sha256 $targetProfileDigest,
        public ?string $externalCacheKey,
        public Sha256 $expectedPrefixHash,
        public array $canonicalTools,
        public array $systemBlocks,
        public array $staticDataPrefixBlocks,
        public array $historyBlocks,
        public array $volatileSuffixBlocks,
        public array $routingHints,
        public CitationAliasMap $citationAliasMap,
        public int $estimatedCachedTokens,
        public int $estimatedUncachedTokens,
        public bool $compactionRecommended,
    ) {
    }
}
