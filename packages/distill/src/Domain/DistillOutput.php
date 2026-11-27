<?php

declare(strict_types=1);

namespace KnowKit\Distill\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\{DomainFailure, Outcome};

/** Untrusted raw model output returned by DistillModelTransport before schema and atom checks. */
final readonly class DistillOutput
{
    public function __construct(
        public bool $complete,
        public ?string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public bool $fixture = false,
    ) {
        if ($text !== null && preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('DistillOutput text must be valid UTF-8');
        }
        if ($inputTokens < 0 || $outputTokens < 0) {
            throw new \InvalidArgumentException('Token counts must be non-negative');
        }
    }

    public function assertUsable(int $maxOutputBytes): string
    {
        if (!$this->complete || $this->text === null || trim($this->text) === '') {
            throw new DomainFailure(Outcome::Incompatible, 'distill_output_incomplete');
        }
        if (strlen($this->text) > $maxOutputBytes) {
            throw new DomainFailure(Outcome::Incompatible, 'distill_output_too_large');
        }
        return $this->text;
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'complete' => $this->complete,
            'text' => $this->text,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'fixture' => $this->fixture,
        ]);
    }
}
