<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

use KnowKit\Domain\Contract\JsonObject;

final readonly class LayerBudgetPolicy
{
    public function __construct(
        public int $minGuaranteedTokens,
        public int $maxCeilingTokens,
        public int $maxBytes,
        public int $weightBps,
        public ResolutionTier $preferredTier,
        public ResolutionTier $compactFallbackTier,
    ) {
        if ($minGuaranteedTokens < 0 || $minGuaranteedTokens > 1048576) {
            throw new \InvalidArgumentException('minGuaranteedTokens out of bounds');
        }
        if ($maxCeilingTokens < 1 || $maxCeilingTokens > 1048576 || $maxCeilingTokens < $minGuaranteedTokens) {
            throw new \InvalidArgumentException('maxCeilingTokens out of bounds');
        }
        if ($maxBytes < 1 || $maxBytes > 4194304) {
            throw new \InvalidArgumentException('maxBytes out of bounds');
        }
        if ($weightBps < 1 || $weightBps > 10000) {
            throw new \InvalidArgumentException('weightBps must be integer 1..10000');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'minGuaranteedTokens' => $this->minGuaranteedTokens,
            'maxCeilingTokens' => $this->maxCeilingTokens,
            'maxBytes' => $this->maxBytes,
            'weightBps' => $this->weightBps,
            'preferredTier' => $this->preferredTier->value,
            'compactFallbackTier' => $this->compactFallbackTier->value,
        ]);
    }
}
