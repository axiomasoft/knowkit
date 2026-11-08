<?php

declare(strict_types=1);

namespace KnowKit\Domain\Embeddings;

/** Remote acceptance cannot be disproved. The transport must never retry internally. */
final class ProviderTimeout extends \RuntimeException
{
    public function __construct(public readonly ?string $providerRequestId = null)
    {
        RequestReceipt::assertRequestId($providerRequestId);
        parent::__construct('embedding_request_unknown');
    }
}
