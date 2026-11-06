<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class ParserCapabilities
{
    /**
     * @param list<Capability> $capabilities
     * @param list<string> $schemaVersions
     */
    public function __construct(public array $capabilities, public array $schemaVersions)
    {
        ValueGuard::objects($capabilities, Capability::class, 64);
        ValueGuard::strings($schemaVersions, true);
    }
    public function require(Capability $capability, string $schemaVersion): void
    {
        if (!in_array($capability, $this->capabilities, true) || !in_array($schemaVersion, $this->schemaVersions, true)) {
            throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability');
        }
    }
}
