<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use KnowKit\Domain\CredentialRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Config stores only handle => environment-variable names. Values never enter profiles. */
final readonly class EnvironmentCredentials
{
    /** @var array<string, string> */
    private array $names;

    /** @param array<array-key, mixed> $names Validated portable host configuration. */
    public function __construct(array $names)
    {
        $validated = [];
        foreach ($names as $handle => $name) {
            if (!is_string($handle) || !is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $name) !== 1) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_environment_reference');
            }
            new CredentialRef(handle: $handle, present: false);
            $validated[$handle] = $name;
        }
        $this->names = $validated;
    }

    public function get(CredentialRef $reference): string
    {
        $name = $this->names[$reference->handle] ?? null;
        if (!$reference->present || $name === null) {
            throw new DomainFailure(outcome: Outcome::Unavailable, errorCode: 'credential_unavailable');
        }
        // getenv misses variables when putenv is disabled. $_SERVER is not a fallback:
        // HTTP_* entries are request headers and must not satisfy a credential lookup.
        $value = getenv($name);
        if ($value === false) {
            $fromEnv = $_ENV[$name] ?? false;
            $value = is_string($fromEnv) ? $fromEnv : false;
        }
        if ($value === false || $value === '') {
            throw new DomainFailure(outcome: Outcome::Unavailable, errorCode: 'credential_unavailable');
        }

        return $value;
    }

    public function __debugInfo(): array
    {
        return ['credentials' => 'environment'];
    }
}
