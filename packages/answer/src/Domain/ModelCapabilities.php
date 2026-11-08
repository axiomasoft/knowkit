<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use DateTimeImmutable;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TokenizerIdentity;

final readonly class ModelCapabilities
{
    public function __construct(
        public Sha256 $digest,
        public DateTimeImmutable $expiresAt,
        public bool $textGeneration,
        public ?int $inputTokenLimit = null,
        public ?int $contextTokenLimit = null,
        public ?TokenizerIdentity $tokenizer = null,
    ) {
        foreach ([$inputTokenLimit, $contextTokenLimit] as $limit) {
            if ($limit !== null && ($limit < 1 || $limit > 9007199254740991)) {
                throw new \InvalidArgumentException('Positive model window limits required');
            }
        }
        if (($inputTokenLimit !== null || $contextTokenLimit !== null) && $tokenizer === null) {
            throw new \KnowKit\Domain\DomainFailure(\KnowKit\Domain\Outcome::Incompatible, 'tokenizer_required');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'digest' => $this->digest->value,
            'expiresAt' => $this->expiresAt->format('Y-m-d\\TH:i:s.uP'),
            'textGeneration' => $this->textGeneration,
            'inputTokenLimit' => $this->inputTokenLimit,
            'contextTokenLimit' => $this->contextTokenLimit,
            'tokenizer' => $this->tokenizer?->toWire(),
        ]);
    }

    public static function fromWire(JsonObject $wire): self
    {
        $tokenizer = $wire->get('tokenizer');

        return new self(
            new Sha256($wire->get('digest')),
            new DateTimeImmutable($wire->get('expiresAt')),
            $wire->get('textGeneration'),
            $wire->get('inputTokenLimit'),
            $wire->get('contextTokenLimit'),
            $tokenizer === null ? null : new TokenizerIdentity(
                new \KnowKit\Domain\AdapterId($tokenizer->get('tokenizerId')),
                $tokenizer->get('tokenizerVersion'),
                new Sha256($tokenizer->get('vocabularyDigest')),
            ),
        );
    }
}
