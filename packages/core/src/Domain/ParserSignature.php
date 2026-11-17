<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class ParserSignature
{
    public function __construct(public string $name, public string $version, public Fingerprint $profileDigest)
    {
        ValueGuard::text($name, 128);
        ValueGuard::text($version, 64);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['name' => $this->name, 'version' => $this->version, 'profileDigest' => $this->profileDigest->value]);
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->version === $other->version
            && $this->profileDigest->equals($other->profileDigest);
    }
}
