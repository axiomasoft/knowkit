<?php

declare(strict_types=1);

namespace KnowKit\Distill\Domain;

use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Domain\{Sha256, ValueGuard};

/** Immutable model and compression-budget profile for distillation jobs (integer-only bps, 0 float). */
final readonly class DistillProfile
{
    public const DEFAULT_MAX_INPUT_BYTES = 1048576;
    public const DEFAULT_MAX_OUTPUT_BYTES = 262144;
    public const DEFAULT_R0_MAX_RATIO_BPS = 200;
    public const DEFAULT_R1_MAX_RATIO_BPS = 1000;
    public const DEFAULT_R2_MAX_RATIO_BPS = 2200;

    public function __construct(
        public string $providerId,
        public string $accountId,
        public string $region,
        public string $modelId,
        public string $tokenizerId,
        public int $maxInputBytes = self::DEFAULT_MAX_INPUT_BYTES,
        public int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        public int $r0MaxRatioBps = self::DEFAULT_R0_MAX_RATIO_BPS,
        public int $r1MaxRatioBps = self::DEFAULT_R1_MAX_RATIO_BPS,
        public int $r2MaxRatioBps = self::DEFAULT_R2_MAX_RATIO_BPS,
        public bool $production = true,
    ) {
        ValueGuard::text($providerId, 64);
        ValueGuard::text($accountId, 64);
        ValueGuard::text($region, 64);
        ValueGuard::text($modelId, 256);
        ValueGuard::text($tokenizerId, 128);
        if ($maxInputBytes < 1 || $maxInputBytes > 16777216) {
            throw new \InvalidArgumentException('maxInputBytes must be 1..16777216');
        }
        if ($maxOutputBytes < 1 || $maxOutputBytes > 4194304) {
            throw new \InvalidArgumentException('maxOutputBytes must be 1..4194304');
        }
        foreach ([$r0MaxRatioBps, $r1MaxRatioBps, $r2MaxRatioBps] as $bps) {
            if ($bps < 1 || $bps > 10000) {
                throw new \InvalidArgumentException('Compression ratio ceiling bps must be 1..10000');
            }
        }
    }

    public function destination(): JsonObject
    {
        return new JsonObject([
            'providerId' => $this->providerId,
            'accountId' => $this->accountId,
            'region' => $this->region,
            'modelId' => $this->modelId,
        ]);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'providerId' => $this->providerId,
            'accountId' => $this->accountId,
            'region' => $this->region,
            'modelId' => $this->modelId,
            'tokenizerId' => $this->tokenizerId,
            'maxInputBytes' => $this->maxInputBytes,
            'maxOutputBytes' => $this->maxOutputBytes,
            'r0MaxRatioBps' => $this->r0MaxRatioBps,
            'r1MaxRatioBps' => $this->r1MaxRatioBps,
            'r2MaxRatioBps' => $this->r2MaxRatioBps,
            'production' => $this->production,
        ]);
    }

    public function digest(): Sha256
    {
        return new Sha256(hash('sha256', Jcs::canonicalize($this->toWire())));
    }
}
