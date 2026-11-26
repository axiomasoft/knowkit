<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Laravel\Http\AuthenticateContext;
use KnowKit\Laravel\Http\RouteRegistrar;
use KnowKit\Laravel\Jobs\JobHandler;
use KnowKit\Laravel\Jobs\JobIdentity;
use KnowKit\Laravel\Jobs\Jobs;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Registry\HostProfile;

final class KnowKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(path: __DIR__ . '/../config/knowkit.php', key: 'knowkit');
        $this->app->bind(abstract: EnvironmentCredentials::class, concrete: function (): EnvironmentCredentials {
            $names = $this->config()->get('knowkit.credentials');
            if (!is_array($names)) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invalid_environment_reference');
            }

            return new EnvironmentCredentials($names);
        });
        $this->registerAdapters();
        $this->registerJobs();
    }

    private function registerAdapters(): void
    {
        if (!$this->app->bound(AdapterRegistry::class)) {
            $this->app->singleton(abstract: AdapterRegistry::class, concrete: static function (): AdapterRegistry {
                $registry = new AdapterRegistry();
                $registry->boot();

                return $registry;
            });
        }
        $profile = $this->config()->get('knowkit.profile');
        if ($profile !== null) {
            if (!is_string($profile)) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'host_profile_invalid');
            }
            $validated = HostProfile::fromJson(json: $profile, registry: $this->app->make(AdapterRegistry::class));
            $this->app->instance(abstract: HostProfile::class, instance: $validated);
            AdapterBindings::register(container: $this->app, profile: $validated);
        }
    }

    private function registerJobs(): void
    {
        $this->app->bind(abstract: Jobs::class, concrete: function (): Jobs {
            if (!$this->enabled('jobs.enabled')) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'jobs_disabled');
            }

            return new Jobs(
                enabled: true,
                dispatcher: $this->app->make(Dispatcher::class),
                identity: $this->app->make(JobIdentity::class),
                handler: $this->app->make(JobHandler::class),
            );
        });
    }

    public function boot(): void
    {
        $this->enabled('jobs.enabled');
        $this->publishes(
            paths: [__DIR__ . '/../config/knowkit.php' => $this->app->configPath('knowkit.php')],
            groups: 'knowkit-config',
        );
        $this->publishMigrations();
        if ($this->enabled('commands.enabled')) {
            $this->commands([KnowKitCommand::class]);
        }
        if ($this->enabled('http.enabled')) {
            $this->registerRoutes();
        }
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

    private function registerRoutes(): void
    {
        $class = $this->config()->get('knowkit.http.registrar');
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
