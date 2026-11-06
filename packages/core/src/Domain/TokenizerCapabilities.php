<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class TokenizerCapabilities
{
    public function __construct(public TokenizerIdentity $identity, public bool $exact) {}

    public function requireExact(TokenizerIdentity $expected): void
    {
        $expected->assertCompatible($this->identity);
        if (!$this->exact) { throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability'); }
    }
}
