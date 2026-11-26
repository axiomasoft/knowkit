<?php

declare(strict_types=1);

namespace KnowKit\Application\Observability;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Observability as O;
use KnowKit\Port\DiagnosticState;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Registry\HostProfile;
use KnowKit\Registry\ReadinessReport;

/** Adapts DeepTutor value-free metadata/readiness. Never resolves factories or silently changes profile. */
final readonly class Diagnostics
{
    public function __construct(
        private AdapterRegistry $registry,
        private HostProfile $profile,
        private DiagnosticState $state,
    ) {}

    public function snapshot(D\Scope $scope, ?Telemetry $telemetry = null): JsonObject
    {
        $telemetry?->trace->assertTenant($scope->tenantId);
        $state = $this->state->current($scope);
        if (!$state->scope->equals($scope)) {
            throw new D\DomainFailure(outcome: D\Outcome::Denied, errorCode: 'scope_mismatch');
        }
        if (!$state->profile->equals($this->profile->digest())) {
            throw new D\DomainFailure(outcome: D\Outcome::Incompatible, errorCode: 'profile_mismatch');
        }
        [$dependencies, $capabilities, $requiredReady, $degraded] = $this->dependencies($state);
        $ready = $state->metadataSchemaReady && $state->tombstonesReplayed && $requiredReady;
        $telemetry?->emit(
            event: O\Event::Index, stage: O\Stage::Readiness, outcome: $state->indexOutcome,
            reason: $state->indexOutcome === D\Outcome::Success ? O\Reason::None : O\Reason::NotVerified,
        );

        return new JsonObject([
            'schemaVersion' => 'diagnostics:1',
            'health' => new JsonObject([
                'live' => true, 'ready' => $ready, 'degraded' => $degraded,
                'metadataSchemaReady' => $state->metadataSchemaReady, 'tombstonesReplayed' => $state->tombstonesReplayed,
            ]),
            'dependencies' => $dependencies,
            'capabilities' => $capabilities,
            'effectiveProfile' => new JsonObject([
                'fingerprint' => $this->profile->digest()->value,
                'factoryFingerprint' => $this->profile->effectiveProfile()->digest()->value,
                'requiredSatisfied' => $ready, 'degraded' => $degraded,
            ]),
            'index' => new JsonObject(['outcome' => $state->indexOutcome->value]),
            'coverage' => $state->coverage?->operationalSummary() ?? new JsonObject(['state' => 'unknown']),
        ]);
    }

    /** @return array{list<JsonObject>, list<string>, bool, bool} */
    private function dependencies(O\RuntimeState $state): array
    {
        $observations = [];
        foreach ($state->adapters as $observation) {
            $observations[$observation->adapterId->value] = $observation;
        }
        foreach ($this->profile->effectiveProfile()->adapterBindings as $id) {
            $observations[$id->value] ??= new O\AdapterObservation(adapterId: $id, required: true, enabled: true);
        }
        ksort($observations, SORT_STRING);
        $rows = $capabilities = [];
        $requiredReady = true;
        $degraded = false;
        foreach ($observations as $observation) {
            [$row, $available] = $this->dependency(observation: $observation, indexOutcome: $state->indexOutcome);
            $rows[] = $row;
            $requiredReady = $requiredReady && (!$observation->required || $row->get('ready'));
            $degraded = $degraded || (!$observation->required && $observation->enabled && !$row->get('ready'));
            array_push($capabilities, ...$available);
        }
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities, SORT_STRING);

        return [$rows, $capabilities, $requiredReady, $degraded];
    }

    /** @return array{JsonObject, list<string>} */
    private function dependency(O\AdapterObservation $observation, D\Outcome $indexOutcome): array
    {
        $descriptor = null;
        $outcome = $observation->outcome;
        try {
            $descriptor = $this->registry->describe($observation->adapterId);
            $this->registry->validate(id: $observation->adapterId, profile: $this->profile->effectiveProfile());
            $configured = true;
        } catch (D\DomainFailure $failure) {
            $configured = false;
            $outcome = $failure->outcome;
        }
        $verified = $configured && $observation->verifiedProfile?->equals($this->profile->digest()) === true;
        if ($outcome === D\Outcome::Success && !$verified) {
            $outcome = D\Outcome::Unknown;
        }
        // Index health must not hide an unverified lexical adapter as a dependency outage.
        if ($descriptor?->kind === D\AdapterKind::Lexical && $outcome === D\Outcome::Success) {
            $outcome = $indexOutcome;
        }
        $report = new ReadinessReport(
            installed: $descriptor !== null, enabled: $observation->enabled, configured: $configured,
            verified: $verified, failed: !in_array($outcome, [D\Outcome::Success, D\Outcome::Unknown], true),
            unknown: $outcome === D\Outcome::Unknown,
        );
        $row = $report->toWire()->with('adapterRef', hash('sha256', $observation->adapterId->value))
            ->with('required', $observation->required)->with('outcome', $outcome->value)
            ->with('reason', match ($outcome) {
                D\Outcome::Success => O\Reason::None->value,
                D\Outcome::Denied => O\Reason::AccessDenied->value,
                D\Outcome::Unknown => O\Reason::NotVerified->value,
                D\Outcome::Incompatible => O\Reason::Incompatible->value,
                default => O\Reason::DependencyUnavailable->value,
            })
            ->with('packageFingerprint', $descriptor?->packageFingerprint?->value)
            ->with('weightsFingerprint', $descriptor?->weightsFingerprint?->value);
        $capabilities = $report->ready && $descriptor !== null ? array_map(
            static fn (D\CapabilityDeclaration $capability): string => $capability->id->value,
            $descriptor->capabilities,
        ) : [];

        return [$row, $capabilities];
    }
}
