<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\TokenizerIdentity;
use KnowKit\Port\Tokenizer;

final readonly class AnswerLimits
{
    public function __construct(
        public int $questionBytes = 4096,
        public int $priorTurns = 12,
        public int $priorBytes = 16384,
        public int $inputBytes = 32768,
        public int $evidenceBytes = 16384,
        public int $outputTokens = 2048,
        public int $outputBytes = 65536,
        public int $deadlineSeconds = 120,
        public ?int $inputTokens = null,
    ) {
        $ceilings = [4096, 12, 16384, 32768, 16384, 2048, 65536, 120];
        $values = [$questionBytes, $priorTurns, $priorBytes, $inputBytes, $evidenceBytes,
            $outputTokens, $outputBytes, $deadlineSeconds];
        foreach ($values as $i => $value) {
            if ($value < ($i === 1 || $i === 2 ? 0 : 1) || $value > $ceilings[$i]) {
                throw new \InvalidArgumentException('Host limits may only lower the defaults');
            }
        }
        if ($inputTokens !== null && ($inputTokens < 1 || $inputTokens > 9007199254740991)) {
            throw new \InvalidArgumentException('Positive explicit token ceiling required');
        }
    }

    public function assertInput(
        string $completeInput,
        string $evidence,
        ?Tokenizer $tokenizer = null,
        ?TokenizerIdentity $identity = null,
    ): void {
        Guard::utf8($completeInput, $this->inputBytes);
        Guard::utf8($evidence, $this->evidenceBytes);
        $this->assertTokens($completeInput, $this->inputTokens, $tokenizer, $identity);
    }

    public function assertOutput(
        string $text,
        ?Tokenizer $tokenizer = null,
        ?TokenizerIdentity $identity = null,
    ): void {
        Guard::utf8($text, $this->outputBytes);
        $this->assertTokens($text, $this->outputTokens, $tokenizer, $identity);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(get_object_vars($this));
    }

    private function assertTokens(
        string $text,
        ?int $limit,
        ?Tokenizer $tokenizer,
        ?TokenizerIdentity $identity,
    ): void {
        if ($limit === null) {
            return;
        }
        if ($tokenizer === null || $identity === null) {
            throw new DomainFailure(Outcome::Incompatible, 'tokenizer_required');
        }
        $tokenizer->capabilities()->requireExact($identity);
        $count = $tokenizer->count($text, $identity);
        if ($count < 0 || $count > $limit) {
            throw new DomainFailure(Outcome::Incompatible, 'token_limit');
        }
    }
}
