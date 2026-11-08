<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

/** Secret-free serialized model body; transport adds separately resolved credentials. */
final readonly class TransportRequest
{
    public function __construct(
        public ModelCallIdentity $identity,
        public string $bytes,
    ) {
        $identity->requireBytes($bytes);
    }

    public function __debugInfo(): array
    {
        return ['identityDigest' => $this->identity->digest()->value, 'byteLength' => strlen($this->bytes)];
    }
}
