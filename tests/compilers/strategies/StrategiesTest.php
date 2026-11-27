<?php
declare(strict_types=1);

namespace KnowKit\Tests\Compilers\Strategies;

require_once __DIR__ . '/StrategyFixtures.php';

use KnowKit\ContextCompilers\Compiler\PromptCacheCompiler;
use KnowKit\ContextCompilers\Compiler\Strategy\ExplicitServerObjectStrategy;
use KnowKit\ContextCompilers\Domain\CacheSemantics as S;
use KnowKit\Domain\Layers\{DeliveryMode, TrustTier};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('compilers:strategies')]
final class StrategiesTest extends TestCase
{
    private function compile(S $s, ?array $nodes = null, int $calls = 5, array $history = [], array $tools = [], int $min = 0, int $maxBp = 4, int $read = 1000, int $write = 10000, int $storage = 2500, bool $strip = false)
    {
        $c = new PromptCacheCompiler();
        $p = StrategyFixtures::profile($s, $min, $maxBp, $read, $write, $storage, $strip);
        return $c->compile(StrategyFixtures::scope(), StrategyFixtures::stack($nodes ?? StrategyFixtures::standardNodes()), StrategyFixtures::evidence('hello'), $p, $history, $tools, $calls);
    }

    public function testExplicitServerObjectBreakEvenIsIntegerAndEmitsKey(): void
    {
        self::assertSame(2, ExplicitServerObjectStrategy::breakEvenCalls(StrategyFixtures::profile(S::ExplicitServerObject, 0, 0, 1000, 10000, 2500)));
        // read=10000 => denominator clamps to 1, no division by zero
        self::assertSame(2501, ExplicitServerObjectStrategy::breakEvenCalls(StrategyFixtures::profile(S::ExplicitServerObject, 0, 0, 10000, 10000, 2500)));
        self::assertSame(1, ExplicitServerObjectStrategy::breakEvenCalls(StrategyFixtures::profile(S::ExplicitServerObject, 0, 0, 1000, 10000, 0)));

        $e = $this->compile(S::ExplicitServerObject, calls: 2, min: 10);
        self::assertSame('kk-cache:' . str_repeat('2', 64), $e->externalCacheKey);
        self::assertGreaterThan(0, $e->estimatedCachedTokens);

        $low = $this->compile(S::ExplicitServerObject, calls: 1, min: 10);
        self::assertNull($low->externalCacheKey);
        $small = $this->compile(S::ExplicitServerObject, calls: 9, min: 100000);
        self::assertNull($small->externalCacheKey);
        self::assertSame(0, $small->estimatedCachedTokens);
    }

    public function testExplicitBreakpointsOrderAndLimit(): void
    {
        $tools = [['name' => 'b'], ['name' => 'a']];
        $history = [['role' => 'user', 'content' => 'q1'], ['role' => 'assistant', 'content' => 'a1'], ['role' => 'user', 'content' => 'q2']];
        $e = $this->compile(S::ExplicitBreakpoints, history: $history, tools: $tools, maxBp: 4);
        self::assertSame('ephemeral', $e->routingHints['cache_breakpoint_tools']);
        self::assertSame('ephemeral', $e->systemBlocks[0]->cacheControlHint);
        self::assertSame('ephemeral', $e->staticDataPrefixBlocks[0]->cacheControlHint);
        self::assertSame('ephemeral', $e->historyBlocks[1]->cacheControlHint);
        self::assertNull($e->historyBlocks[0]->cacheControlHint);
        self::assertNull($e->historyBlocks[2]->cacheControlHint);
        self::assertSame([['name' => 'a'], ['name' => 'b']], $e->canonicalTools);

        $two = $this->compile(S::ExplicitBreakpoints, history: $history, tools: $tools, maxBp: 2);
        self::assertSame('ephemeral', $two->routingHints['cache_breakpoint_tools']);
        self::assertSame('ephemeral', $two->systemBlocks[0]->cacheControlHint);
        self::assertNull($two->staticDataPrefixBlocks[0]->cacheControlHint);
        self::assertNull($two->historyBlocks[1]->cacheControlHint);
    }

    public function testImplicitPrefixIsStableAndDriftFreeAcrossTurns(): void
    {
        $a = $this->compile(S::ImplicitPrefix, history: [['role' => 'user', 'content' => 'one']]);
        $b = $this->compile(S::ImplicitPrefix, history: [['role' => 'user', 'content' => 'two']]);
        self::assertTrue($a->expectedPrefixHash->equals($b->expectedPrefixHash));
        self::assertNull($a->externalCacheKey);
        self::assertSame($a->estimatedCachedTokens + $a->estimatedUncachedTokens, PromptCacheCompiler::totalTokens($a));
    }

    public function testDynamicValueAbovePrefixIsRejected(): void
    {
        $nodes = [StrategyFixtures::node('l0.core', TrustTier::TrustedDirective, DeliveryMode::PinnedPrefix, ['today' => '2026-01-01T10:00:00Z'])];
        $this->expectException(\LogicException::class);
        $this->compile(S::ImplicitPrefix, $nodes);
    }

    public function testLocalRadixCacheSalt(): void
    {
        $catalog = $this->compile(S::LocalRadixPrefix, StrategyFixtures::standardNodes('catalog_mount'));
        self::assertSame('catalog:' . str_repeat('1', 64), $catalog->routingHints['cache_salt']);

        $private = $this->compile(S::LocalRadixPrefix, StrategyFixtures::standardNodes('directive'));
        self::assertSame('tenant:' . hash('sha256', StrategyFixtures::TENANT . ':' . str_repeat('1', 64)), $private->routingHints['cache_salt']);
        self::assertNull($private->externalCacheKey);
    }

    public function testCompactNoCacheRecommendsCompactionOverHalfWindow(): void
    {
        $big = [['role' => 'user', 'content' => str_repeat('x', 4 * 70000)]];
        $e = $this->compile(S::CompactNoCache, history: $big);
        self::assertTrue($e->compactionRecommended);
        self::assertSame(0, $e->estimatedCachedTokens);
        self::assertSame('elevated', $e->routingHints['pruning']);
        $small = $this->compile(S::CompactNoCache, history: [['role' => 'user', 'content' => 'hi']]);
        self::assertFalse($small->compactionRecommended);
    }

    public function testThinkingBlocksStrippedOnlyWhenProfileSaysSo(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => 'secret'], ['type' => 'text', 'text' => 'answer']]],
            ['role' => 'assistant', 'content' => '<thinking>hmm</thinking>final'],
        ];
        $on = $this->compile(S::LocalRadixPrefix, history: $history, strip: true);
        self::assertStringNotContainsString('secret', $on->historyBlocks[1]->content);
        self::assertStringContainsString('answer', $on->historyBlocks[1]->content);
        self::assertSame('final', $on->historyBlocks[2]->content);
        $off = $this->compile(S::LocalRadixPrefix, history: $history, strip: false);
        self::assertStringContainsString('secret', $off->historyBlocks[1]->content);
    }
}
