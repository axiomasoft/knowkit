<?php
declare(strict_types=1);

namespace KnowKit\Application\Runtime;

use KnowKit\Domain\{DomainFailure, InstanceId, IsolationPreset, Outcome};
use KnowKit\Registry\{HostProfile, ReadinessReport};

/** Multi-instance registry with lazy instantiation, frozen boot state, and per-instance fault containment. */
final class InstanceRegistry
{
    /** @var array<string, array{id: InstanceId, profile: HostProfile, preset: IsolationPreset, factory: \Closure(): KnowledgeInstance}> */
    private array $entries = [];

    /** @var array<string, KnowledgeInstance> */
    private array $instances = [];

    private bool $booted = false;

    /** @param \Closure(): KnowledgeInstance $factory */
    public function register(
        InstanceId $id,
        HostProfile $profile,
        IsolationPreset $preset,
        \Closure $factory,
    ): void {
        if ($this->booted) {
            throw new DomainFailure(Outcome::Incompatible, 'registry_frozen');
        }
        if (isset($this->entries[$id->value])) {
            throw new DomainFailure(Outcome::Incompatible, 'duplicate_identifier');
        }
        $this->entries[$id->value] = [
            'id' => $id,
            'profile' => $profile,
            'preset' => $preset,
            'factory' => $factory,
        ];
    }

    public function boot(): void
    {
        $this->booted = true;
    }

    public function get(InstanceId $id): KnowledgeInstance
    {
        if (!$this->booted) {
            throw new DomainFailure(Outcome::Incompatible, 'registry_not_booted');
        }
        $entry = $this->entries[$id->value] ?? throw new DomainFailure(Outcome::Incompatible, 'instance_not_found');
        if (!isset($this->instances[$id->value])) {
            $instance = ($entry['factory'])();
            if (!$instance->id->equals($id)) {
                throw new DomainFailure(Outcome::Incompatible, 'instance_contract_violation');
            }
            $this->instances[$id->value] = $instance;
        }

        return $this->instances[$id->value];
    }

    /** @return array<string, HostProfile> */
    public function describeAll(): array
    {
        $profiles = [];
        foreach ($this->entries as $key => $entry) {
            $profiles[$key] = $entry['profile'];
        }

        return $profiles;
    }

    /** @return array<string, ReadinessReport> */
    public function readinessAll(): array
    {
        $reports = [];
        foreach ($this->entries as $key => $entry) {
            try {
                $reports[$key] = $this->get($entry['id'])->readiness;
            } catch (\Throwable) {
                $reports[$key] = new ReadinessReport(
                    installed: true,
                    enabled: true,
                    configured: false,
                    verified: false,
                    failed: true,
                    unknown: false,
                );
            }
        }

        return $reports;
    }
}
