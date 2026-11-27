<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Domain;

final readonly class TrainingRightsDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {
    }

    public static function allowed(): self
    {
        return new self(true, null);
    }

    public static function denied(string $reason = 'training_rights_denied'): self
    {
        if (preg_match('/^([a-z][a-z0-9_]{0,63}|[A-Z][A-Z0-9_]{0,63})$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Typed error code required for denial reason');
        }

        return new self(false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}
