<?php

declare(strict_types=1);

namespace KnowKit\Domain\Reading;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\Jcs;

/** Private until confirmed COMMIT. Metadata is bounded and carries no storage locator. */
final readonly class ReadBody
{
    private D\FrozenJson $metadata;
    public D\Sha256 $digest;

    public function __construct(
        private string $bytes,
        public string $mediaType,
        JsonObject $metadata,
        public int $fragments = 1,
        public int $assets = 0,
    ) {
        if (strlen($bytes) > 8388608 || strlen(Jcs::canonicalize($metadata)) > 262144) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        $this->digest = new D\Sha256(hash('sha256', $bytes));
        $this->metadata = new D\FrozenJson($metadata);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function metadata(): JsonObject
    {
        return $this->metadata->toWire();
    }

    public function __debugInfo(): array
    {
        return ['digest' => $this->digest, 'byteLength' => strlen($this->bytes)];
    }
}
