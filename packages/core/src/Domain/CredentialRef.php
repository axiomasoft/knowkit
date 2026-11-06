<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** An opaque host credential handle and presence flag. Never accepts a secret value. */
final readonly class CredentialRef
{
    public function __construct(public string $handle, public bool $present)
    {
        if (preg_match('/^[a-z][a-z0-9._:-]{0,127}$/D', $handle) !== 1) { throw new \InvalidArgumentException('Opaque credential handle required'); }
    }
    public function __debugInfo(): array { return ['present'=>$this->present]; }
}
