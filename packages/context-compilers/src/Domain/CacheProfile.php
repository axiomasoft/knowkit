<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Domain;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;

final readonly class CacheProfile
{
    private const CODECS = ['toon', 'compact_markdown', 'canonical_json'];

    public function __construct(
        public string $profileId,
        public CacheSemantics $semantics,
        public int $minCacheableTokens,
        public int $maxBreakpoints,
        public int $readCostBps,
        public int $writeCostBps,
        public int $hourlyStorageBps,
        public int $ttlSeconds,
        public bool $supportsSessionAffinity,
        public bool $discountsToolTokens,
        public bool $stripPriorThinkingBlocks,
        public string $preferredCodec,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}[a-z0-9]$/D', $profileId) !== 1) {
            throw new \InvalidArgumentException('Invalid cache profileId');
        }
        self::assertRange($minCacheableTokens, 0, 1048576, 'minCacheableTokens');
        self::assertRange($maxBreakpoints, 0, 16, 'maxBreakpoints');
        self::assertRange($readCostBps, 0, 10000, 'readCostBps');
        self::assertRange($writeCostBps, 10000, 50000, 'writeCostBps');
        self::assertRange($hourlyStorageBps, 0, 100000, 'hourlyStorageBps');
        self::assertRange($ttlSeconds, 0, 86400, 'ttlSeconds');
        if (!in_array($preferredCodec, self::CODECS, true)) {
            throw new \InvalidArgumentException('Invalid preferredCodec');
        }
    }

    public static function fromWire(JsonObject $wire, ?ContractValidator $validator = null): self
    {
        if ($validator !== null) {
            $errors = $validator->validateValue($wire, 'urn:knowkit:schema:cache-profile:1.0');
            if ($errors !== []) {
                throw new ContractViolation($errors[0]['code'], $errors[0]['path']);
            }
        } elseif ($wire->get('schemaVersion') !== 'cache-profile:1') {
            throw new \InvalidArgumentException('Invalid cache-profile schemaVersion');
        }

        $profileId = $wire->get('profileId');
        $semantics = $wire->get('semantics');
        $minCacheableTokens = $wire->get('minCacheableTokens');
        $maxBreakpoints = $wire->get('maxBreakpoints');
        $readCostBps = $wire->get('readCostBps');
        $writeCostBps = $wire->get('writeCostBps');
        $hourlyStorageBps = $wire->get('hourlyStorageBps');
        $ttlSeconds = $wire->get('ttlSeconds');
        $supportsSessionAffinity = $wire->get('supportsSessionAffinity');
        $discountsToolTokens = $wire->get('discountsToolTokens');
        $stripPriorThinkingBlocks = $wire->get('stripPriorThinkingBlocks');
        $preferredCodec = $wire->get('preferredCodec');

        if (
            !is_string($profileId)
            || !is_string($semantics)
            || !is_int($minCacheableTokens)
            || !is_int($maxBreakpoints)
            || !is_int($readCostBps)
            || !is_int($writeCostBps)
            || !is_int($hourlyStorageBps)
            || !is_int($ttlSeconds)
            || !is_bool($supportsSessionAffinity)
            || !is_bool($discountsToolTokens)
            || !is_bool($stripPriorThinkingBlocks)
            || !is_string($preferredCodec)
        ) {
            throw new \InvalidArgumentException('Invalid cache-profile wire payload');
        }

        return new self(
            profileId: $profileId,
            semantics: CacheSemantics::from($semantics),
            minCacheableTokens: $minCacheableTokens,
            maxBreakpoints: $maxBreakpoints,
            readCostBps: $readCostBps,
            writeCostBps: $writeCostBps,
            hourlyStorageBps: $hourlyStorageBps,
            ttlSeconds: $ttlSeconds,
            supportsSessionAffinity: $supportsSessionAffinity,
            discountsToolTokens: $discountsToolTokens,
            stripPriorThinkingBlocks: $stripPriorThinkingBlocks,
            preferredCodec: $preferredCodec,
        );
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'schemaVersion' => 'cache-profile:1',
            'profileId' => $this->profileId,
            'semantics' => $this->semantics->value,
            'minCacheableTokens' => $this->minCacheableTokens,
            'maxBreakpoints' => $this->maxBreakpoints,
            'readCostBps' => $this->readCostBps,
            'writeCostBps' => $this->writeCostBps,
            'hourlyStorageBps' => $this->hourlyStorageBps,
            'ttlSeconds' => $this->ttlSeconds,
            'supportsSessionAffinity' => $this->supportsSessionAffinity,
            'discountsToolTokens' => $this->discountsToolTokens,
            'stripPriorThinkingBlocks' => $this->stripPriorThinkingBlocks,
            'preferredCodec' => $this->preferredCodec,
        ]);
    }

    public function digest(): Sha256
    {
        return new Sha256(HashDomain::CompiledContext->digest($this->toWire()));
    }

    private static function assertRange(int $value, int $min, int $max, string $field): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('%s must be between %d and %d', $field, $min, $max));
        }
    }
}
