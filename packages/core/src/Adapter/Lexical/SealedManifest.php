<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Lexical;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** @internal One immutable schema-validation result; never an authorization or search-result cache. */
final class SealedManifest
{
    private string $key = '';
    private ?JsonObject $value = null;

    public function load(string $bytes, string $expectedDigest, ContractValidator $validator): JsonObject
    {
        $key = $expectedDigest . ':' . hash('sha256', $bytes);
        if ($key === $this->key && $this->value !== null) {
            return $this->value;
        }
        $wire = $validator->decodeValid(bytes: $bytes, schemaId: 'urn:knowkit:schema:index-manifest:1.0');
        if (HashDomain::IndexManifest->digest($wire) !== $expectedDigest) {
            throw new DomainFailure(Outcome::Incompatible, 'manifest_mismatch');
        }
        $this->key = $key;
        $this->value = $wire;

        return $wire;
    }
}
