<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Laravel\Http\RouteRegistrar;
use KnowKit\Laravel\KnowKitServiceProvider;
use KnowKit\Registry\AdapterDescriptor;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Registry\Factory;
use KnowKit\Tooling\Qa;

final class Identity implements HostIdentity
{
    public int $reads = 0;

    public function __construct(
        public ?PolicyContext $policy,
    ) {}

    public function current(): ?PolicyContext
    {
        $this->reads++;

        return $this->policy;
    }
}

final class Fixture
{
    public const JOB = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public static function policy(): PolicyContext
    {
        return new PolicyContext(
            scope: new D\Scope(
                tenantId: new D\TenantId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
                kbId: new D\KbId('01ARZ3NDEKTSV4RRFFQ69G5FAW'),
                actorId: new D\ActorId('01ARZ3NDEKTSV4RRFFQ69G5FAX'),
                purpose: 'support.answer',
                policyEpoch: new D\Decimal('1'),
                hostScopeHash: new D\Sha256(str_repeat('a', 64)),
            ),
            audience: 'support',
            grants: ['read'],
        );
    }

    public static function app(string $path, array $config = []): Application
    {
        ServiceProvider::$publishes = [];
        ServiceProvider::$publishGroups = [];
        $app = new Application($path);
        $app->instance('config', new Repository(['app' => ['key' => null], 'knowkit' => $config]));
        $app->instance('router', new Router(events: new Dispatcher($app), container: $app));

        return $app;
    }

    public static function boot(Application $app): void
    {
        $app->register(KnowKitServiceProvider::class);
        $app->boot();
    }

    public static function request(array $body = [], bool $authenticated = true): Request
    {
        $request = Request::create(
            uri: '/v1/search',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->setUserResolver(static fn () => $authenticated ? new GenericUser(['id' => 'host-user']) : null);

        return $request;
    }

    public static function registry(Factory $factory): AdapterRegistry
    {
        $registry = new AdapterRegistry();
        $registry->register(
            descriptor: new AdapterDescriptor(
                id: new D\AdapterId('fixture.clock'),
                kind: D\AdapterKind::Clock,
                version: '1.0.0',
                configSchema: StrictJson::decode('{"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"urn:fixture:clock:1","type":"object","unevaluatedProperties":false}'),
                capabilities: [],
            ),
            factory: $factory,
        );
        $registry->boot();

        return $registry;
    }

    public static function json(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/v1/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class Routes implements RouteRegistrar
{
    public static int $calls = 0;

    public function register(Router $router): void
    {
        self::$calls++;
        $router->post('/v1/search', static fn () => response('host-application'));
    }
}
