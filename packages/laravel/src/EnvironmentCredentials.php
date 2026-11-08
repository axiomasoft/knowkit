<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use KnowKit\Domain\CredentialRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Config stores only handle => environment-variable names. Values never enter profiles. */
final readonly class EnvironmentCredentials
{
    /** @param array<array-key, mixed> $names Validated portable host configuration. */
    public function __construct(
        private array $names,
    ) {
        foreach ($names as $handle => $name) {
            if (!is_string($handle) || !is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $name) !== 1) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_environment_reference');
            }
            new CredentialRef(handle: $handle, present: false);
        }
    }

    public function get(CredentialRef $reference): string
    {
        $name = $this->names[$reference->handle] ?? null;
        $value = $name === null ? false : getenv($name);
        if (!$reference->present || $value === false || $value === '') {
            throw new DomainFailure(outcome: Outcome::Unavailable, errorCode: 'credential_unavailable');
        }

        return $value;
    }

    public function __debugInfo(): array
    {
        return ['credentials' => 'environment'];
    }
}
