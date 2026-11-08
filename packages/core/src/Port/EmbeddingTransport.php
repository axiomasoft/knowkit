<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Embeddings\ProviderResponse;
use KnowKit\Domain\SpaceRef;

/** Host-supplied transport, not an authorized public entry point. No redirects/retries/fallback. */
interface EmbeddingTransport
{
    /** @param list<string> $texts Credentials belong to the transport, never to the space. */
    public function embed(array $texts, SpaceRef $space, string $role): ProviderResponse;
}
