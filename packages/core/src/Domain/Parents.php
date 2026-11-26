<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};

final readonly class Parents extends NodeRefs
{
    public function digest(): Fingerprint
    {
        $pairs = [];
        foreach ($this->refs as $ref) {
            $wire = $ref->toWire();
            $pairs[] = [Jcs::canonicalize($wire), $wire];
        }
        usort($pairs, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
        $refs = array_map(static fn(array $pair): JsonObject => $pair[1], $pairs);
        return new Fingerprint(HashDomain::DependencyParentSet->digest($refs));
    }
}
