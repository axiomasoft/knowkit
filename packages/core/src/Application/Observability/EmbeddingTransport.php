<?php

declare(strict_types=1);

namespace KnowKit\Application\Observability;

use KnowKit\Domain as D;
use KnowKit\Domain\Embeddings\ProviderResponse;
use KnowKit\Domain\Embeddings\ProviderTimeout;
use KnowKit\Domain\Observability as O;
use KnowKit\Port\EmbeddingTransport as Transport;

/** Transport observation only; ConfiguredProvider still owns authorization, validation and receipts. */
final readonly class EmbeddingTransport implements Transport
{
    public function __construct(
        private Transport $transport,
        private Telemetry $telemetry,
    ) {}

    public function embed(array $texts, D\SpaceRef $space, string $role): ProviderResponse
    {
        $started = hrtime(true);
        try {
            $response = $this->transport->embed(texts: $texts, space: $space, role: $role);
        } catch (\Throwable $failure) {
            $this->telemetry->emit(
                event: O\Event::Provider, stage: O\Stage::Embed,
                outcome: $failure instanceof D\DomainFailure ? $failure->outcome : D\Outcome::Unknown,
                reason: $failure instanceof ProviderTimeout ? O\Reason::ProviderUncertain : O\Reason::InternalFailure,
                measurements: new O\Measurements(durationMs: intdiv(hrtime(true) - $started, 1000000)),
            );
            throw $failure;
        }
        $this->telemetry->emit(
            event: O\Event::Provider, stage: O\Stage::Embed, outcome: D\Outcome::Success,
            measurements: new O\Measurements(durationMs: intdiv(hrtime(true) - $started, 1000000), count: count($texts)),
        );

        return $response;
    }
}
