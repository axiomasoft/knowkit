<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class SelectionLimits
{
    private const PROFILES = [
        'compact' => [4, 4096, 1024, 0],
        'document' => [8, 16384, 4096, 0],
        'table' => [4, 32768, 16384, 0],
        'visual' => [4, 8192, 2048, 2],
        'agentools-v1' => [3, 2048, 512, 0],
    ];

    private function __construct(
        public string $profile,
        public int $maxFragments,
        public int $maxBytes,
        public int $maxBytesPerFragment,
        public int $maxAssets,
        public ?int $maxTokens,
        public ?TokenizerIdentity $tokenizer,
    ) {}

    public static function profile(
        string $profile,
        ?int $maxFragments = null,
        ?int $maxBytes = null,
        ?int $maxBytesPerFragment = null,
        ?int $maxAssets = null,
        ?int $maxTokens = null,
        ?TokenizerIdentity $tokenizer = null,
    ): self {
        $defaults = self::PROFILES[$profile]
            ?? throw new DomainFailure(Outcome::Incompatible, 'profile_incompatible');
        [$fragments, $bytes, $fragmentBytes, $assets] = $defaults;
        $ceiling = $profile === 'agentools-v1' ? [8, 8192, 1024, 0] : $defaults;
        $values = [$maxFragments ?? $fragments, $maxBytes ?? $bytes,
            $maxBytesPerFragment ?? $fragmentBytes, $maxAssets ?? $assets];
        $minimumBytes = $profile === 'agentools-v1' ? 32 : 1;
        foreach ($values as $index => $value) {
            $minimum = $index === 3 ? 0 : ($index === 0 ? 1 : $minimumBytes);
            if ($value < $minimum || $value > $ceiling[$index]) {
                throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
            }
        }
        if ($values[2] > $values[1] || ($maxTokens !== null && ($maxTokens < 1 || $maxTokens > 1048576))) {
            throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
        }
        if ($maxTokens !== null && $tokenizer === null) {
            throw new DomainFailure(Outcome::Incompatible, 'tokenizer_required');
        }

        return new self($profile, ...[...$values, $maxTokens, $tokenizer]);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'profile' => $this->profile,
            'maxFragments' => $this->maxFragments,
            'maxBytes' => $this->maxBytes,
            'maxBytesPerFragment' => $this->maxBytesPerFragment,
            'maxAssets' => $this->maxAssets,
            'tokenBudget' => $this->maxTokens === null || $this->tokenizer === null ? null
                : $this->tokenizer->toWire()->with('maxTokens', $this->maxTokens),
        ]);
    }
}
