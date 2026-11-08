<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use KnowKit\Domain\TenantId;
use KnowKit\Domain\ValueGuard;

/** Exact legacy identity, never a canonical source/IR/content digest. */
final readonly class ReleaseIdentity
{
    public function __construct(
        public TenantId $tenantId,
        public string $corpus,
        public string $releaseId,
        public string $legacyHashKind,
        public string $legacyHash,
    ) {
        ValueGuard::text(value: $corpus, max: 128);
        ValueGuard::text(value: $releaseId, max: 128);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $legacyHashKind) !== 1
            || preg_match('/^[0-9a-f]{16,128}$/D', $legacyHash) !== 1) {
            throw new \InvalidArgumentException('Typed legacy hash required');
        }
    }

    public function key(): string
    {
        return json_encode([
            $this->tenantId->value,
            $this->corpus,
            $this->releaseId,
            $this->legacyHashKind,
            $this->legacyHash,
        ], JSON_THROW_ON_ERROR);
    }
}
