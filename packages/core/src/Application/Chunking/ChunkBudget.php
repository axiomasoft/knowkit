<?php
declare(strict_types=1);
namespace KnowKit\Application\Chunking;

use KnowKit\Domain\{AdapterId, ChunkerProfile, DomainFailure, Outcome, Sha256, TokenizerIdentity};
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Tokenizer;

/** Byte limits always apply; token limits require an exact, local tokenizer. */
final readonly class ChunkBudget
{
    public int $maxBytes;
    public int $overlapBlocks;
    public ?int $maxTokens;
    public ?TokenizerIdentity $identity;

    public function __construct(ChunkerProfile $profile, private ?Tokenizer $tokenizer)
    {
        if ($profile->signature->name !== 'structural' || $profile->signature->version !== '1') { self::fail('unsupported_capability'); }
        $options = $profile->options->toWire();
        foreach ($options->keys() as $key) {
            if (!in_array($key, ['maxBytes', 'overlapBlocks', 'maxCount', 'counter'], true)) { self::fail('unknown_field'); }
        }
        foreach (['maxBytes', 'overlapBlocks', 'maxCount'] as $key) {
            if ($options->has($key) && !is_int($options->get($key))) { self::fail('limit_exceeded'); }
        }
        $this->maxBytes = $options->get('maxBytes') ?? 1200;
        $this->overlapBlocks = $options->get('overlapBlocks') ?? 1;
        $this->maxTokens = $options->get('maxCount');
        if ($this->maxBytes < 1 || $this->maxBytes > 1048576 || !in_array($this->overlapBlocks, [0, 1], true)
            || ($this->maxTokens !== null && $this->maxTokens < 1)) { self::fail('limit_exceeded'); }
        $identity = $options->get('counter');
        if ($this->maxTokens !== null) {
            if (!$identity instanceof JsonObject || $tokenizer === null
                || $identity->keys() === [] || count($identity) !== 3
                || !is_string($identity->get('id')) || !is_string($identity->get('version'))
                || !is_string($identity->get('vocabularyDigest'))) { self::fail('tokenizer_required'); }
            $this->identity = new TokenizerIdentity(new AdapterId($identity->get('id')), $identity->get('version'), new Sha256($identity->get('vocabularyDigest')));
            $tokenizer->capabilities()->requireExact($this->identity);
        } else {
            if ($identity !== null) { self::fail('tokenizer_required'); }
            $this->identity = null;
        }
    }

    public function fits(string $text): bool
    {
        if (strlen($text) > $this->maxBytes) { return false; }
        if ($this->identity === null) { return true; }
        $count = $this->tokenizer->count($text, $this->identity);
        if ($count < 0) { self::fail('count_mismatch'); }
        return $count <= $this->maxTokens;
    }

    private static function fail(string $code): never { throw new DomainFailure(Outcome::Incompatible, $code); }
}
