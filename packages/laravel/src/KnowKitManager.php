<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use KnowKit\Application\Runtime\InstanceRegistry;
use KnowKit\Application\Runtime\KnowledgeInstance;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\InstanceId;
use KnowKit\Domain\IsolationPreset;
use KnowKit\Domain\Outcome;
use KnowKit\Laravel\Jobs\Jobs;
use KnowKit\Registry\HostProfile;
use KnowKit\Registry\ReadinessReport;

/** Multi-instance manager for KnowKit in Laravel; never falls back silently to another instance. */
final class KnowKitManager
{
    /**
     * @param array<string, array{
     *     id: InstanceId,
     *     preset: IsolationPreset,
     *     profile: ?HostProfile,
     *     credentials: EnvironmentCredentials,
     *     httpEnabled: bool,
     *     registrar: mixed,
     *     jobsEnabled: bool,
     * }> $configs
     * @param \Closure(string, InstanceId): Jobs $jobsFactory
     * @param \Closure(string, InstanceId): ConsoleKernel $consoleFactory
     */
    public function __construct(
        private readonly InstanceRegistry $registry,
        private readonly string $defaultName,
        private readonly array $configs,
        private readonly \Closure $jobsFactory,
        private readonly \Closure $consoleFactory,
    ) {}

    public function instance(?string $name = null): KnowledgeInstance
    {
        $target = $this->resolveName($name);

        return $this->registry->get($this->configs[$target]['id']);
    }

    public function credentials(?string $name = null): EnvironmentCredentials
    {
        return $this->configs[$this->resolveName($name)]['credentials'];
    }

    public function jobs(?string $name = null): Jobs
    {
        $target = $this->resolveName($name);
        if (!$this->configs[$target]['jobsEnabled']) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'jobs_disabled');
        }

        return ($this->jobsFactory)($target, $this->configs[$target]['id']);
    }

    public function console(?string $name = null): ConsoleKernel
    {
        $target = $this->resolveName($name);

        return ($this->consoleFactory)($target, $this->configs[$target]['id']);
    }

    public function registry(): InstanceRegistry
    {
        return $this->registry;
    }

    public function defaultName(): string
    {
        return $this->defaultName;
    }

    /** @return array<string, HostProfile> */
    public function describeAll(): array
    {
        return $this->registry->describeAll();
    }

    /** @return array<string, ReadinessReport> */
    public function readinessAll(): array
    {
        return $this->registry->readinessAll();
    }

    /** @return list<array{id: InstanceId, registrar: mixed}> */
    public function enabledHttpRegistrars(): array
    {
        $registrars = [];
        foreach ($this->configs as $config) {
            if ($config['httpEnabled']) {
                $registrars[] = ['id' => $config['id'], 'registrar' => $config['registrar']];
            }
        }

        return $registrars;
    }

    private function resolveName(?string $name): string
    {
        $target = $name ?? $this->defaultName;
        if ($target === '' || !isset($this->configs[$target])) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'instance_not_found');
        }

        return $target;
    }
}
