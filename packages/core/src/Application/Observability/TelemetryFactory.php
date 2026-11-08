<?php

declare(strict_types=1);

namespace KnowKit\Application\Observability;

use KnowKit\Domain\Scope;
use KnowKit\Domain\Ulid;
use KnowKit\Domain\Observability\TraceContext;
use KnowKit\Port\Clock;
use KnowKit\Port\ObservationSink;

final readonly class TelemetryFactory
{
    public function __construct(
        private ObservationSink $sink,
        private Clock $clock,
        #[\SensitiveParameter] private string $redactionKey,
    ) {
        if (strlen($redactionKey) < 32) {
            throw new \InvalidArgumentException('Host redaction key of at least 32 bytes required');
        }
    }

    public function start(Ulid $correlationId, Scope $scope, ?string $runRef = null): Telemetry
    {
        return new Telemetry(
            sink: $this->sink, clock: $this->clock,
            trace: TraceContext::forTenant(
                correlationId: $correlationId, tenant: $scope->tenantId,
                redactionKey: $this->redactionKey, runRef: $runRef,
            ),
        );
    }

    public function __debugInfo(): array
    {
        return ['telemetry' => 'configured'];
    }
}
