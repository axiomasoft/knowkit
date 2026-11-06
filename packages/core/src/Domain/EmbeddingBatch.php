<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class EmbeddingBatch
{
    /** @param list<Vector> $vectors */
    public function __construct(public array $vectors, public SpaceRef $space)
    {
        ValueGuard::objects($vectors, Vector::class);
        foreach ($vectors as $vector) { $space->assertCompatible($vector->space); }
    }
}
