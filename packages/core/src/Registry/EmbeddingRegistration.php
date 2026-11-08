<?php

declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\SpaceRef;
use KnowKit\Port\EmbeddingProvider;

final readonly class EmbeddingRegistration
{
    public function __construct(
        public SpaceRef $space,
        public EmbeddingProvider $provider,
    ) {}
}
