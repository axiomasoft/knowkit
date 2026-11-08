<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** Decimal strings preserve exact settings under the core integer-only JCS contract. */
final readonly class GenerationSettings
{
    /** @param list<string> $stop */
    public function __construct(
        public int $maxOutputTokens = 2048,
        public ?string $temperature = null,
        public ?string $topP = null,
        public ?int $seed = null,
        public array $stop = [],
    ) {
        Guard::orderedList($stop, 16);
        if ($maxOutputTokens < 1 || $maxOutputTokens > 2048
            || ($seed !== null && ($seed < -9007199254740991 || $seed > 9007199254740991))) {
            throw new \InvalidArgumentException('Bounded generation settings required');
        }
        foreach ([$temperature, $topP] as $decimal) {
            if ($decimal !== null && preg_match('/^(0|1|2)(\\.[0-9]{1,8})?$/D', $decimal) !== 1) {
                throw new \InvalidArgumentException('Exact nonnegative decimal setting required');
            }
        }
        if (($temperature !== null && (float) $temperature > 2)
            || ($topP !== null && (float) $topP > 1)) {
            throw new \InvalidArgumentException('Sampling setting out of range');
        }
        foreach ($stop as $text) {
            Guard::utf8($text, 256, false);
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(get_object_vars($this));
    }
}
