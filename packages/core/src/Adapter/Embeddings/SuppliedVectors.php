<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Embeddings;

use KnowKit\Domain\Embeddings\ProviderResponse;
use KnowKit\Domain\SpaceRef;
use KnowKit\Port\EmbeddingTransport;

/** Host-produced vectors enter the same ConfiguredProvider validation/acceptance path. No IO. */
final readonly class SuppliedVectors implements EmbeddingTransport
{
    public function __construct(private ProviderResponse $response) {}

    public function embed(array $texts, SpaceRef $space, string $role): ProviderResponse
    {
        return $this->response;
    }
}
