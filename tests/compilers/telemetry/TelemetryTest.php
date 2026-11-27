<?php
declare(strict_types=1);

namespace KnowKit\Tests\Compilers\Telemetry;

use KnowKit\ContextCompilers\Telemetry\CacheTelemetryNormalizer;
use KnowKit\Domain\Sha256;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('compilers:strategies')]
final class TelemetryTest extends TestCase
{
    public function testNormalizesProviderShapes(): void
    {
        $n = new CacheTelemetryNormalizer();
        $a = $n->normalize(['input_tokens' => 50, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 100]);
        self::assertSame([900, 100, 50], [$a->cacheReadTokens, $a->cacheWriteTokens, $a->uncachedInputTokens]);

        $o = $n->normalize(['prompt_tokens' => 1000, 'prompt_tokens_details' => ['cached_tokens' => 768]]);
        self::assertSame([768, null, 232], [$o->cacheReadTokens, $o->cacheWriteTokens, $o->uncachedInputTokens]);

        $g = $n->normalize(['promptTokenCount' => 500, 'cachedContentTokenCount' => 200]);
        self::assertSame([200, 300], [$g->cacheReadTokens, $g->uncachedInputTokens]);

        $none = $n->normalize([]);
        self::assertNull($none->cacheReadTokens);
        self::assertNull($none->uncachedInputTokens);
        self::assertNull($none->prefixDriftDetected);
    }

    public function testDetectsPrefixDriftAndRejectsFloats(): void
    {
        $n = new CacheTelemetryNormalizer();
        $h = new Sha256(str_repeat('a', 64));
        self::assertFalse($n->normalize([], $h, str_repeat('a', 64))->prefixDriftDetected);
        self::assertTrue($n->normalize([], $h, str_repeat('b', 64))->prefixDriftDetected);
        self::assertNull($n->normalize(['cache_read_input_tokens' => 1.5])->cacheReadTokens);
    }
}
