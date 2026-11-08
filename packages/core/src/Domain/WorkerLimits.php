<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class WorkerLimits
{
    private const CEILINGS = [
        'wallSeconds' => 600,
        'cpuSeconds' => 480,
        'memoryBytes' => 2147483648,
        'tempBytes' => 4294967296,
        'maxPages' => 500,
        'maxOutputBytes' => 268435456,
        'maxAssets' => 2000,
    ];

    public function __construct(
        public int $wallSeconds,
        public int $cpuSeconds,
        public int $memoryBytes,
        public int $tempBytes,
        public int $maxPages,
        public int $maxOutputBytes,
        public int $maxAssets,
    ) {
        foreach (self::CEILINGS as $name => $ceiling) {
            if ($this->{$name} < ($name === 'maxAssets' ? 0 : 1) || $this->{$name} > $ceiling) {
                throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
            }
        }
    }

    public static function fromWire(JsonObject $limits): self
    {
        return new self(
            $limits->get('wallSeconds'),
            $limits->get('cpuSeconds'),
            (int) $limits->get('memoryBytes'),
            (int) $limits->get('tempBytes'),
            $limits->get('maxPages'),
            (int) $limits->get('maxOutputBytes'),
            $limits->get('maxAssets'),
        );
    }

    public function toWire(int $assetCeiling = 2000): JsonObject
    {
        return new JsonObject([
            'wallSeconds' => $this->wallSeconds,
            'cpuSeconds' => $this->cpuSeconds,
            'memoryBytes' => (string) $this->memoryBytes,
            'tempBytes' => (string) $this->tempBytes,
            'maxPages' => $this->maxPages,
            'maxOutputBytes' => (string) $this->maxOutputBytes,
            'maxAssets' => min($this->maxAssets, $assetCeiling),
        ]);
    }
}
