<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Telemetry;

use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Observability\Measurements;
use KnowKit\Domain\Sha256;

/** Maps provider usage payloads (Anthropic / OpenAI-compatible / Gemini / vLLM) to integer Measurements. */
final readonly class CacheTelemetryNormalizer
{
    /** @param array<string, mixed> $usage */
    public function normalize(
        array $usage,
        ?Sha256 $expectedPrefixHash = null,
        ?string $previousPrefixHash = null,
        int $durationMs = 0,
    ): Measurements {
        $details = $usage['prompt_tokens_details'] ?? $usage['input_tokens_details'] ?? [];
        $details = is_array($details) ? $details : [];

        $read = self::firstInt([
            $usage['cache_read_input_tokens'] ?? null,
            $details['cached_tokens'] ?? null,
            $usage['cachedContentTokenCount'] ?? null,
            $usage['cached_tokens'] ?? null,
        ]);
        $write = self::firstInt([
            $usage['cache_creation_input_tokens'] ?? null,
            $usage['cache_write_tokens'] ?? null,
            $details['cache_write_tokens'] ?? null,
        ]);

        $anthropicStyle = array_key_exists('cache_read_input_tokens', $usage)
            || array_key_exists('cache_creation_input_tokens', $usage);
        $uncached = null;
        if ($anthropicStyle) {
            // Anthropic reports input_tokens as the uncached remainder.
            $uncached = self::firstInt([$usage['input_tokens'] ?? null]);
        } else {
            $total = self::firstInt([
                $usage['prompt_tokens'] ?? null,
                $usage['promptTokenCount'] ?? null,
                $usage['input_tokens'] ?? null,
            ]);
            if ($total !== null) {
                $uncached = max(0, $total - ($read ?? 0) - ($write ?? 0));
            }
        }

        $drift = null;
        if ($expectedPrefixHash !== null && $previousPrefixHash !== null) {
            $drift = !hash_equals($previousPrefixHash, $expectedPrefixHash->value);
        }

        return new Measurements(
            durationMs: $durationMs,
            cacheReadTokens: $read,
            cacheWriteTokens: $write,
            uncachedInputTokens: $uncached,
            prefixDriftDetected: $drift,
        );
    }

    /** @param list<mixed> $candidates */
    private static function firstInt(array $candidates): ?int
    {
        foreach ($candidates as $value) {
            if (is_int($value) && $value >= 0 && $value <= StrictJson::SAFE_INTEGER) {
                return $value;
            }
        }

        return null;
    }
}
