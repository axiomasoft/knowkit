<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** 256 random bits. Only SHA256(raw token bytes) is retained by server storage. */
final readonly class SelectionToken
{
    public function __construct(private string $value)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new DomainFailure(Outcome::Denied, 'selection_invalid');
        }
    }

    public static function issue(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function hash(): Sha256
    {
        $bytes = hex2bin($this->value);
        if ($bytes === false) {
            throw new DomainFailure(Outcome::Denied, 'selection_invalid');
        }

        return new Sha256(hash('sha256', $bytes));
    }

    public function __debugInfo(): array
    {
        return ['token' => '[redacted]'];
    }
}
