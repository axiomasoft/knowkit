<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Application\Runtime\InstanceRegistry;
use KnowKit\Application\Runtime\KnowledgeFacade;
use KnowKit\Application\Runtime\KnowledgeInstance;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\InstanceId;
use KnowKit\Domain\IsolationPreset;
use KnowKit\Domain\Outcome;
use KnowKit\Laravel\Http\AuthenticateContext;
use KnowKit\Laravel\Http\RouteRegistrar;
use KnowKit\Laravel\Jobs\JobHandler;
use KnowKit\Laravel\Jobs\JobIdentity;
use KnowKit\Laravel\Jobs\Jobs;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Registry\HostProfile;
use KnowKit\Registry\ReadinessReport;

final class KnowKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(path: __DIR__ . '/../config/knowkit.php', key: 'knowkit');
        if (!$this->app->bound(AdapterRegistry::class)) {
            $this->app->singleton(abstract: AdapterRegistry::class, concrete: static function (): AdapterRegistry {
                $registry = new AdapterRegistry();
                $registry->boot();

                return $registry;
            });
        }

        $manager = $this->buildManager();
        $this->app->instance(abstract: KnowKitManager::class, instance: $manager);
        $this->app->instance(abstract: InstanceRegistry::class, instance: $manager->registry());

        $this->app->bind(abstract: EnvironmentCredentials::class, concrete: function (): EnvironmentCredentials {
            return $this->app->make(KnowKitManager::class)->credentials();
        });
        $this->app->bind(abstract: Jobs::class, concrete: function (): Jobs {
            return $this->app->make(KnowKitManager::class)->jobs();
        });
        $this->app->bind(abstract: ConsoleKernel::class, concrete: function (): ConsoleKernel {
            return $this->app->make(KnowKitManager::class)->console();
        });
    }

    public function boot(): void
    {
        $this->publishMigrations();
        if ($this->enabled('commands.enabled')) {
            $this->commands([KnowKitCommand::class]);
        }
        $manager = $this->app->make(KnowKitManager::class);
        foreach ($manager->enabledHttpRegistrars() as $entry) {
            $this->registerRoutes($entry['id'], $entry['registrar']);
        }
    }

    private function buildManager(): KnowKitManager
    {
        $config = $this->config();
        $defaultName = $config->get('knowkit.default');
        if (!is_string($defaultName) || $defaultName === '') {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_default_instance');
        }
        $rawInstances = $config->get('knowkit.instances');
        if (!is_array($rawInstances)) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_instances_config');
        }

        $instanceRegistry = new InstanceRegistry();
        $configs = [];

        foreach ($rawInstances as $name => $raw) {
            if (!is_string($name) || !is_array($raw)) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_instances_config');
            }
            $instanceId = InstanceId::fromString($name);

            $presetRaw = $raw['preset'] ?? IsolationPreset::SingleLocal->value;
            if (!is_string($presetRaw)) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_isolation_preset');
            }
            $preset = IsolationPreset::tryFrom($presetRaw);
            if ($preset === null) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_isolation_preset');
            }

            $credNames = $raw['credentials'] ?? [];
            if (!is_array($credNames)) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_environment_reference');
            }
            $credentials = new EnvironmentCredentials($credNames);

            $http = $raw['http'] ?? ['enabled' => false, 'registrar' => null];
            if (!is_array($http) || !is_bool($http['enabled'] ?? false)) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_enable_flag');
            }
            $httpEnabled = (bool) ($http['enabled'] ?? false);

            $jobs = $raw['jobs'] ?? ['enabled' => false];
            if (!is_array($jobs) || !is_bool($jobs['enabled'] ?? false)) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_enable_flag');
            }
            $jobsEnabled = (bool) ($jobs['enabled'] ?? false);

            $profileRaw = $raw['profile'] ?? null;
            $validatedProfile = null;
            if ($profileRaw !== null) {
                if (!is_string($profileRaw)) {
                    throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'host_profile_invalid');
                }
                $validatedProfile = HostProfile::fromJson(
                    json: $profileRaw,
                    registry: $this->app->make(AdapterRegistry::class),
                );
                $instanceRegistry->register(
                    id: $instanceId,
                    profile: $validatedProfile,
                    preset: $preset,
                    factory: function () use ($name, $instanceId, $validatedProfile, $preset, $defaultName): KnowledgeInstance {
                        return $this->resolveKnowledgeInstance($name, $instanceId, $validatedProfile, $preset, $defaultName);
                    },
                );
                if ($name === $defaultName) {
                    $this->app->instance(abstract: HostProfile::class, instance: $validatedProfile);
                    AdapterBindings::register(container: $this->app, profile: $validatedProfile);
                }
            }

            $configs[$name] = [
                'id' => $instanceId,
                'preset' => $preset,
                'profile' => $validatedProfile,
                'credentials' => $credentials,
                'httpEnabled' => $httpEnabled,
                'registrar' => $http['registrar'] ?? null,
                'jobsEnabled' => $jobsEnabled,
            ];
        }

        $instanceRegistry->boot();

        return new KnowKitManager(
            registry: $instanceRegistry,
            defaultName: $defaultName,
            configs: $configs,
            jobsFactory: function (string $name, InstanceId $id) use ($defaultName): Jobs {
                $identityKey = "knowkit.instances.{$name}.job_identity";
                $handlerKey = "knowkit.instances.{$name}.job_handler";
                $identity = $this->app->bound($identityKey)
                    ? $this->app->make($identityKey)
                    : ($name === $defaultName ? $this->app->make(JobIdentity::class)
                        : throw new DomainFailure(Outcome::Incompatible, 'instance_not_found'));
                $handler = $this->app->bound($handlerKey)
                    ? $this->app->make($handlerKey)
                    : ($name === $defaultName ? $this->app->make(JobHandler::class)
                        : throw new DomainFailure(Outcome::Incompatible, 'instance_not_found'));

                return new Jobs(
                    enabled: true,
                    dispatcher: $this->app->make(Dispatcher::class),
                    identity: $identity,
                    handler: $handler,
                    instanceId: $id,
                );
            },
            consoleFactory: function (string $name) use ($defaultName): ConsoleKernel {
                $identityKey = "knowkit.instances.{$name}.host_identity";
                $appKey = "knowkit.instances.{$name}.host_application";
                $identity = $this->app->bound($identityKey)
                    ? $this->app->make($identityKey)
                    : ($name === $defaultName ? $this->app->make(HostIdentity::class)
                        : throw new DomainFailure(Outcome::Incompatible, 'instance_not_found'));
                $application = $this->app->bound($appKey)
                    ? $this->app->make($appKey)
                    : ($name === $defaultName ? $this->app->make(HostApplication::class)
                        : throw new DomainFailure(Outcome::Incompatible, 'instance_not_found'));

                return new ConsoleKernel(
                    identity: $identity,
                    application: $application,
                );
            },
        );
    }

    private function resolveKnowledgeInstance(
        string $name,
        InstanceId $instanceId,
        HostProfile $profile,
        IsolationPreset $preset,
        string $defaultName,
    ): KnowledgeInstance {
        $key = "knowkit.instances.{$name}.facade";
        $facade = null;
        if ($this->app->bound($key)) {
            $facade = $this->app->make($key);
        } elseif ($name === $defaultName && $this->app->bound(KnowledgeFacade::class)) {
            $facade = $this->app->make(KnowledgeFacade::class);
        }
        if (!$facade instanceof KnowledgeFacade) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'facade_unconfigured');
        }

        return new KnowledgeInstance(
            id: $instanceId,
            profile: $profile,
            preset: $preset,
            facade: $facade,
            readiness: new ReadinessReport(
                installed: true,
                enabled: true,
                configured: true,
                verified: true,
                failed: false,
                unknown: false,
            ),
        );
    }

    private function enabled(string $key): bool
    {
        $value = $this->config()->get('knowkit.' . $key);
        if (!is_bool($value)) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_enable_flag');
        }

        return $value;
    }

    private function publishMigrations(): void
    {
        if (!$this->enabled('migrations.publish')) {
            return;
        }
        $file = (new \ReflectionClass(AdapterRegistry::class))->getFileName();
        if ($file === false) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'core_path_unavailable');
        }
        $core = dirname($file, 3);
        $this->publishes(
            paths: [$core . '/migrations/postgres' => $this->app->databasePath('knowkit/postgres')],
            groups: 'knowkit-migrations',
        );
    }

    private function registerRoutes(InstanceId $instanceId, mixed $class): void
    {
        if (!is_string($class) || !is_a($class, RouteRegistrar::class, true)) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'http_registrar_required');
        }
        $registrar = $this->app->make($class);
        $router = $this->app->make(Router::class);
        $existing = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $existing[spl_object_id($route)] = true;
        }
        $registrar->register($router);
        // Append, do not prepend: host auth on the route must run before this guard.
        // Priority only sorts routes that already include this class; it is not global middleware.
        $seen = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $id = spl_object_id($route);
            if (isset($existing[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $route->defaults['knowkit_instance'] = $instanceId->value;
            $route->middleware(AuthenticateContext::class);
        }
        if (!in_array(AuthenticateContext::class, $router->middlewarePriority, true)) {
            $router->middlewarePriority[] = AuthenticateContext::class;
        }
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
