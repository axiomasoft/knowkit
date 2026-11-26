<?php

declare(strict_types=1);

namespace KnowKit\Application\Observability;

use KnowKit\Application\Disclosure as Disclose;
use KnowKit\Domain as D;
use KnowKit\Domain\Observability as O;

final readonly class Disclosure implements Disclose
{
    public function __construct(
        private Disclose $disclosure,
        private Telemetry $telemetry,
    ) {}

    public function disclose(D\Scope $scope, D\DisclosureRequest $request): D\DisclosureOutcome
    {
        $this->telemetry->trace->assertTenant($scope->tenantId);
        $started = hrtime(true);
        try {
            $outcome = $this->disclosure->disclose(scope: $scope, request: $request);
        } catch (\Throwable $failure) {
            $this->telemetry->emit(
                event: O\Event::Disclosure, stage: O\Stage::Disclose,
                outcome: $failure instanceof D\DomainFailure ? $failure->outcome : D\Outcome::Unavailable,
                reason: $failure instanceof D\DomainFailure ? O\Reason::forError($failure->errorCode) : O\Reason::InternalFailure,
            );
            throw $failure;
        }
        $telemetry = $this->telemetry;
        $data = $outcome->result->data;
        if ($data instanceof D\EvidenceSet) {
            $telemetry = $telemetry->withUsage($data->usageRef());
        } elseif ($data instanceof D\DisclosureReplay) {
            $telemetry = $telemetry->withUsage($data->usageRef);
        } elseif ($data instanceof D\Navigation\UnitDisclosure) {
            $telemetry = $telemetry->withUsage($data->usageRef);
        }
        $telemetry->emit(
            event: O\Event::Disclosure, stage: O\Stage::Disclose, outcome: $outcome->result->outcome,
            reason: $outcome->result->outcome === D\Outcome::Denied ? O\Reason::AccessDenied : O\Reason::forError($outcome->result->errorCode),
            measurements: new O\Measurements(durationMs: intdiv(hrtime(true) - $started, 1000000)),
        );

        return $outcome;
    }
}
