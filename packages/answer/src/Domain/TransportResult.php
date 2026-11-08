<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

/** Raw final provider observation, never an Answered outcome or publication grant. */
final readonly class TransportResult
{
    public function __construct(
        public DispatchCertainty $certainty,
        public ?string $text,
        public bool $complete,
        public UsageObservation $usage,
        public ?string $observedModelRef = null,
        public bool $fixture = false,
    ) {
        if ($observedModelRef !== null) {
            Guard::reference($observedModelRef);
        }
        if ($text !== null) {
            Guard::utf8($text, 65536);
        }
        if (($complete && $certainty !== DispatchCertainty::Sent)
            || (!$complete && $text !== null)) {
            throw new \InvalidArgumentException('Partial or unknown output cannot carry answer text');
        }
    }

    public function __debugInfo(): array
    {
        return ['certainty' => $this->certainty->value, 'complete' => $this->complete, 'fixture' => $this->fixture];
    }
}
