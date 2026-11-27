<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Compiler;

use KnowKit\ContextCompilers\Compiler\Strategy\CompactNoCacheStrategy;
use KnowKit\ContextCompilers\Compiler\Strategy\ExplicitBreakpointsStrategy;
use KnowKit\ContextCompilers\Compiler\Strategy\ExplicitServerObjectStrategy;
use KnowKit\ContextCompilers\Compiler\Strategy\ImplicitPrefixStrategy;
use KnowKit\ContextCompilers\Compiler\Strategy\LocalRadixPrefixStrategy;
use KnowKit\ContextCompilers\Contracts\PromptCacheCompilerInterface;
use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CacheSemantics;
use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\ContextCompilers\Domain\CompiledBlock;
use KnowKit\ContextCompilers\Domain\CompiledContextEnvelope;
use KnowKit\ContextCompilers\Sanitizer\ThinkingBlockStripper;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\EvidenceSet;
use KnowKit\Domain\Layers\DeliveryMode;
use KnowKit\Domain\Layers\LayerNode;
use KnowKit\Domain\Layers\ResolvedLayerStack;
use KnowKit\Domain\Layers\TrustTier;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;

final readonly class PromptCacheCompiler implements PromptCacheCompilerInterface
{
    private const DYNAMIC_PATTERN = '/[0-7][0-9A-HJKMNP-TV-Z]{25}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/';

    public function __construct(
        private ThinkingBlockStripper $stripper = new ThinkingBlockStripper(),
        private CompactNoCacheStrategy $compact = new CompactNoCacheStrategy(),
    ) {
    }

    public function compile(
        Scope $scope,
        ResolvedLayerStack $stack,
        EvidenceSet $evidence,
        CacheProfile $profile,
        array $history = [],
        array $tools = [],
        int $expectedCohortCallsPerHour = 1,
        ?string $previousPrefixHash = null,
    ): CompiledContextEnvelope {
        $scope->assertTenant($stack->tenantId);
        if ($expectedCohortCallsPerHour < 0) {
            throw new \InvalidArgumentException('expectedCohortCallsPerHour must be >= 0');
        }

        $system = [];
        $static = [];
        $volatile = [];
        foreach ($stack->nodes as $node) {
            $this->route($node, $system, $static, $volatile);
        }
        $evidenceWire = $evidence->canonical();
        if ($evidence->toWire()->get('fragments') !== []) {
            $volatile[] = self::block('user', TrustTier::UntrustedEvidence, $evidenceWire);
        }

        $canonicalTools = self::canonicalTools($tools);
        $historyBlocks = [];
        foreach ($this->stripper->strip($history, $profile->stripPriorThinkingBlocks) as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';
            $text = is_string($content) ? $content : Jcs::canonicalize($content);
            $historyBlocks[] = self::block(
                $role === 'assistant' ? 'assistant' : 'user',
                TrustTier::UntrustedEvidence,
                $text,
            );
        }

        $prefixHash = new Sha256(hash('sha256', Jcs::canonicalize([
            'tools' => $canonicalTools,
            'system' => array_map(static fn(CompiledBlock $b): string => $b->contentDigest->value, $system),
            'static' => array_map(static fn(CompiledBlock $b): string => $b->contentDigest->value, $static),
        ])));

        $base = new CompiledContextEnvelope(
            new Sha256(hash('sha256', Jcs::canonicalize([
                'profileId' => $profile->profileId,
                'semantics' => $profile->semantics->value,
                'minCacheableTokens' => $profile->minCacheableTokens,
                'maxBreakpoints' => $profile->maxBreakpoints,
                'readCostBps' => $profile->readCostBps,
                'writeCostBps' => $profile->writeCostBps,
                'hourlyStorageBps' => $profile->hourlyStorageBps,
                'ttlSeconds' => $profile->ttlSeconds,
                'preferredCodec' => $profile->preferredCodec,
            ]))),
            null,
            $prefixHash,
            $canonicalTools,
            $system,
            $static,
            $historyBlocks,
            $volatile,
            [],
            CitationAliasMap::fromEvidenceSet($evidence),
            0,
            0,
            false,
        );
        self::assertDynamicFreePrefix($base);

        $envelope = match ($profile->semantics) {
            CacheSemantics::ExplicitServerObject => (new ExplicitServerObjectStrategy())
                ->apply($base, $profile, $stack, $expectedCohortCallsPerHour),
            CacheSemantics::ExplicitBreakpoints => (new ExplicitBreakpointsStrategy())
                ->apply($base, $profile, $stack, $expectedCohortCallsPerHour),
            CacheSemantics::ImplicitPrefix => (new ImplicitPrefixStrategy())
                ->apply($base, $profile, $stack, $expectedCohortCallsPerHour),
            CacheSemantics::LocalRadixPrefix => (new LocalRadixPrefixStrategy())
                ->apply($base, $profile, $stack, $expectedCohortCallsPerHour),
            CacheSemantics::CompactNoCache => $this->compact
                ->apply($base, $profile, $stack, $expectedCohortCallsPerHour),
        };

        self::assertTrustBoundary($envelope);

        return $envelope;
    }

    /** ARCH-UNTRUSTED invariant, enforced on every strategy output. */
    public static function assertTrustBoundary(CompiledContextEnvelope $e): void
    {
        foreach ($e->systemBlocks as $b) {
            if ($b->trustTier !== TrustTier::TrustedDirective || $b->role !== 'system') {
                throw new \LogicException('ARCH-UNTRUSTED: systemBlocks accept only TrustedDirective');
            }
        }
        foreach ($e->staticDataPrefixBlocks as $b) {
            if ($b->trustTier !== TrustTier::CuratedReference || $b->role === 'system') {
                throw new \LogicException('ARCH-UNTRUSTED: static prefix accepts only CuratedReference as non-system');
            }
        }
        foreach ([...$e->historyBlocks, ...$e->volatileSuffixBlocks] as $b) {
            if ($b->role === 'system' || $b->trustTier === TrustTier::TrustedDirective) {
                throw new \LogicException('ARCH-UNTRUSTED: trusted/system content outside the prefix');
            }
        }
        foreach ($e->volatileSuffixBlocks as $b) {
            if ($b->trustTier !== TrustTier::UntrustedEvidence && $b->trustTier !== TrustTier::DynamicOverlay) {
                throw new \LogicException('ARCH-UNTRUSTED: volatile suffix accepts only untrusted/dynamic tiers');
            }
        }
    }

    public static function assertDynamicFreePrefix(CompiledContextEnvelope $e): void
    {
        foreach ([...$e->systemBlocks, ...$e->staticDataPrefixBlocks] as $b) {
            if (preg_match(self::DYNAMIC_PATTERN, $b->content) === 1) {
                throw new \LogicException('Dynamic value (date/UUID/ULID) above VolatileSuffix breaks prefix stability');
            }
        }
        if (preg_match(self::DYNAMIC_PATTERN, Jcs::canonicalize($e->canonicalTools)) === 1) {
            throw new \LogicException('Dynamic value (date/UUID/ULID) in tools breaks prefix stability');
        }
    }

    public static function prefixTokens(CompiledContextEnvelope $e): int
    {
        $sum = intdiv(strlen(Jcs::canonicalize($e->canonicalTools)) + 3, 4);
        if ($e->canonicalTools === []) {
            $sum = 0;
        }
        foreach ([...$e->systemBlocks, ...$e->staticDataPrefixBlocks] as $b) {
            $sum += $b->tokenEstimate;
        }

        return $sum;
    }

    public static function totalTokens(CompiledContextEnvelope $e): int
    {
        $sum = self::prefixTokens($e);
        foreach ([...$e->historyBlocks, ...$e->volatileSuffixBlocks] as $b) {
            $sum += $b->tokenEstimate;
        }

        return $sum;
    }

    /**
     * @param array{
     *   externalCacheKey?: ?string,
     *   routingHints?: array<string, string>,
     *   systemBlocks?: list<CompiledBlock>,
     *   staticDataPrefixBlocks?: list<CompiledBlock>,
     *   historyBlocks?: list<CompiledBlock>,
     *   estimatedCachedTokens?: int,
     *   estimatedUncachedTokens?: int,
     *   compactionRecommended?: bool
     * } $o
     */
    public static function replace(CompiledContextEnvelope $e, array $o): CompiledContextEnvelope
    {
        return new CompiledContextEnvelope(
            $e->targetProfileDigest,
            array_key_exists('externalCacheKey', $o) ? $o['externalCacheKey'] : $e->externalCacheKey,
            $e->expectedPrefixHash,
            $e->canonicalTools,
            $o['systemBlocks'] ?? $e->systemBlocks,
            $o['staticDataPrefixBlocks'] ?? $e->staticDataPrefixBlocks,
            $o['historyBlocks'] ?? $e->historyBlocks,
            $e->volatileSuffixBlocks,
            $o['routingHints'] ?? $e->routingHints,
            $e->citationAliasMap,
            $o['estimatedCachedTokens'] ?? $e->estimatedCachedTokens,
            $o['estimatedUncachedTokens'] ?? $e->estimatedUncachedTokens,
            $o['compactionRecommended'] ?? $e->compactionRecommended,
        );
    }

    /**
     * @param list<CompiledBlock> $system
     * @param list<CompiledBlock> $static
     * @param list<CompiledBlock> $volatile
     */
    private function route(LayerNode $node, array &$system, array &$static, array &$volatile): void
    {
        $content = Jcs::canonicalize($node->ruleValues);
        $pinned = $node->deliveryMode === DeliveryMode::PinnedPrefix || $node->deliveryMode === DeliveryMode::SessionPinned;
        switch ($node->trustTier) {
            case TrustTier::TrustedDirective:
                if (!$pinned) {
                    throw new \LogicException('ARCH-UNTRUSTED: TrustedDirective must be pinned');
                }
                $system[] = self::block('system', TrustTier::TrustedDirective, $content);
                break;
            case TrustTier::CuratedReference:
                if ($pinned) {
                    $static[] = self::block('user', TrustTier::CuratedReference, $content);
                }
                break;
            case TrustTier::UntrustedEvidence:
            case TrustTier::DynamicOverlay:
                if ($node->deliveryMode === DeliveryMode::VolatileSuffix) {
                    $volatile[] = self::block('user', $node->trustTier, $content);
                }
                break;
        }
    }

    private static function block(string $role, TrustTier $tier, string $content): CompiledBlock
    {
        return new CompiledBlock(
            $role,
            $tier,
            $content,
            null,
            intdiv(strlen($content) + 3, 4),
            new Sha256(hash('sha256', $content)),
        );
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private static function canonicalTools(array $tools): array
    {
        $keyed = [];
        foreach ($tools as $tool) {
            $keyed[Jcs::canonicalize($tool)] = $tool;
        }
        ksort($keyed, SORT_STRING);

        return array_values($keyed);
    }
}
