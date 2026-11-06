<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, Jcs};

final readonly class Parents extends NodeRefs
{
    public function digest(): Fingerprint
    {
        $refs = array_map(static fn(ResourceRef $ref) => $ref->toWire(), $this->refs);
        usort($refs, static fn($a, $b): int => strcmp(Jcs::canonicalize($a), Jcs::canonicalize($b)));
        return new Fingerprint(HashDomain::DependencyParentSet->digest($refs));
    }
}
