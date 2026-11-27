<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Artifacts\ProtectedAtom;

interface AtomVerifier
{
    public function equivalent(ProtectedAtom $source, ProtectedAtom $derived): bool;
}
