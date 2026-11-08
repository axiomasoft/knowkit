<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\UtcInstant;

final readonly class Observation
{
    public function __construct(
        public UtcInstant $timestamp,
        public TraceContext $trace,
        public Event $event,
        public Stage $stage,
        public Outcome $outcome,
        public Reason $reason,
        public Measurements $measurements,
    ) {}

    public function toWire(): JsonObject
    {
        return $this->trace->toWire()->with('schemaVersion', 'observation:1')
            ->with('timestamp', $this->timestamp->value)
            ->with('level', in_array($this->outcome, [Outcome::Success, Outcome::Empty], true) ? 'info' : 'warning')
            ->with('event', $this->event->value)->with('stage', $this->stage->value)
            ->with('outcome', $this->outcome->value)->with('errorCode', $this->reason->value)
            ->with('durationMs', $this->measurements->durationMs)->with('count', $this->measurements->count)
            ->with('digestRef', $this->measurements->digestRef?->value);
    }

    /** Finite label vocabulary. Correlation/tenant/run/usage IDs are deliberately not metric labels.
     * @return list<JsonObject>
     */
    public function metrics(): array
    {
        $labels = new JsonObject([
            'event' => $this->event->value, 'stage' => $this->stage->value,
            'outcome' => $this->outcome->value, 'reason' => $this->reason->value,
        ]);
        $metrics = [
            $this->metric(name: 'knowkit_events_total', type: 'counter', value: $this->measurements->count, labels: $labels),
            $this->metric(name: 'knowkit_duration_ms', type: 'histogram', value: $this->measurements->durationMs, labels: $labels),
        ];
        foreach ([
            'knowkit_outbox_lag_seconds' => $this->measurements->outboxLagSeconds,
            'knowkit_object_orphans' => $this->measurements->orphanCount,
            'knowkit_quota_used' => $this->measurements->quotaUsed,
            'knowkit_quota_limit' => $this->measurements->quotaLimit,
        ] as $name => $value) {
            if ($value !== null) {
                $metrics[] = $this->metric(name: $name, type: 'gauge', value: $value, labels: $labels);
            }
        }

        return $metrics;
    }

    private function metric(string $name, string $type, int $value, JsonObject $labels): JsonObject
    {
        return new JsonObject(['name' => $name, 'type' => $type, 'value' => $value, 'labels' => $labels]);
    }
}
