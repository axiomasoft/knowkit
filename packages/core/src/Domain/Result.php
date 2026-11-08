<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** @template T of object */
final readonly class Result
{
    /**
     * @param T|null $data
     * @param list<string> $warnings
     */
    private function __construct(public Outcome $outcome, public ?object $data, public ?string $errorCode,
        public bool $retryable, public bool $degraded, public ?string $effectiveProfile, public array $warnings) {}

    /**
     * @template V of object
     * @param V $data
     * @param list<string> $warnings
     * @return self<V>
     */
    public static function success(object $data, bool $degraded = false, ?string $effectiveProfile = null, array $warnings = []): self
    {
        if (!(new \ReflectionClass($data))->isReadOnly()) { throw new \InvalidArgumentException('Immutable result required'); }
        if ($degraded && ($effectiveProfile === null || $warnings === [])) {
            throw new \InvalidArgumentException('Explicit degraded profile and warnings required');
        }
        foreach ($warnings as $warning) { ValueGuard::text($warning, 128); }
        return new self(Outcome::Success, $data, null, false, $degraded, $effectiveProfile, $warnings);
    }
    /** @param list<string> $warnings */
    public static function empty(?string $effectiveProfile = null, array $warnings = [], bool $degraded = false): self
    {
        if ($degraded && ($effectiveProfile === null || $warnings === [])) {
            throw new \InvalidArgumentException('Explicit degraded profile and warnings required');
        }
        foreach ($warnings as $warning) {
            ValueGuard::text($warning, 128);
        }

        return new self(Outcome::Empty, null, null, false, $degraded, $effectiveProfile, $warnings);
    }
    public static function failure(DomainFailure $failure, bool $retryable = false): self
    {
        return new self($failure->outcome, null, $failure->errorCode, $retryable, false, null, []);
    }
}
