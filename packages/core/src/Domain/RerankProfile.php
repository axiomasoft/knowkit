<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class RerankProfile
{
    public function __construct(public string $id, public AdapterId $adapterId, public Fingerprint $fingerprint)
    { ValueGuard::text($id, 128); }
}
