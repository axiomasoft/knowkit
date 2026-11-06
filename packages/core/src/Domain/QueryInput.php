<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class QueryInput
{
    public function __construct(private string $text)
    {
        ValueGuard::text($text, 16384);
    }
    public function text(): string { return $this->text; }
    public function digest(): Sha256 { return new Sha256(hash('sha256', $this->text)); }
    public function __debugInfo(): array { return ['digest' => $this->digest()]; }
}
