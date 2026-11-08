<?php

declare(strict_types=1);

namespace KnowKit\Application\Observability;

use KnowKit\Domain as D;
use KnowKit\Domain\Observability as O;
use KnowKit\Port\Clock;
use KnowKit\Port\ObservationSink;

/** Optional operational telemetry cannot turn a committed operation into a retry. */
final readonly class Telemetry
{
    public function __construct(
        private ObservationSink $sink,
        private Clock $clock,
        public O\TraceContext $trace,
    ) {}

    public function withLease(D\Lease $lease): self
    {
        return new self(sink: $this->sink, clock: $this->clock, trace: $this->trace->withLease($lease));
    }

    public function withUsage(D\UsageId $usage): self
    {
        return new self(sink: $this->sink, clock: $this->clock, trace: $this->trace->withUsage($usage));
    }

    public function emit(
        O\Event $event,
        O\Stage $stage,
        D\Outcome $outcome,
        O\Reason $reason = O\Reason::None,
        ?O\Measurements $measurements = null,
    ): void {
        try {
            $this->sink->emit(new O\Observation(
                timestamp: $this->clock->now(), trace: $this->trace, event: $event,
                stage: $stage, outcome: $outcome, reason: $reason,
                measurements: $measurements ?? new O\Measurements(),
            ));
        } catch (\Throwable) {
            // No fallback logger: exception text and sink configuration can contain secrets.
        }
    }

    /** @template T of object
     * @param \Closure(): D\Result<T> $operation
     * @return D\Result<T>
     */
    public function measure(O\Stage $stage, \Closure $operation): D\Result
    {
        $started = hrtime(true);
        try {
            $result = $operation();
        } catch (\Throwable $failure) {
            $this->emit(
                event: O\Event::Stage, stage: $stage,
                outcome: $failure instanceof D\DomainFailure ? $failure->outcome : D\Outcome::Unavailable,
                reason: $failure instanceof D\DomainFailure ? O\Reason::forError($failure->errorCode) : O\Reason::InternalFailure,
                measurements: new O\Measurements(durationMs: intdiv(hrtime(true) - $started, 1000000)),
            );
            throw $failure;
        }
        $this->emit(
            event: O\Event::Stage, stage: $stage, outcome: $result->outcome,
            reason: O\Reason::forError($result->errorCode),
            measurements: new O\Measurements(durationMs: intdiv(hrtime(true) - $started, 1000000)),
        );

        return $result;
    }
}
