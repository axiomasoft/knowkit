<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Rewards;

use KnowKit\Domain\Contract\JsonObject;

final readonly class RewardScore
{
    public const MIN_PERMILLE = -10000;
    public const MAX_PERMILLE = 10000;

    public int $permille;

    /** @param array<string, int> $breakdown */
    public function __construct(
        int $permille,
        public array $breakdown = [],
    ) {
        $this->permille = self::clamp($permille);
    }

    public static function clamp(int $permille): int
    {
        return max(self::MIN_PERMILLE, min(self::MAX_PERMILLE, $permille));
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'permille' => $this->permille,
            'breakdown' => new JsonObject($this->breakdown),
        ]);
    }
}
