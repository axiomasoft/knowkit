<?php
declare(strict_types=1);

namespace KnowKit\Port;

/**
 * AgenTools CanonicalJson interface pattern, (c) 2026 Dmitry Vostrikov.
 * Adapted namespace and KnowKit integer-only JCS profile under private reuse authority.
 * Pin/qualification: tests/contract/ports/evidence/P1.5-reuse.json.
 */
interface CanonicalJson
{
    public function canonicalize(mixed $value): string;
    public function canonicalizeJson(string $json): string;
}
