<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Examples\Laravel\SearchRoutes;
use KnowKit\Laravel\HostApplication;
use KnowKit\Laravel\Http\ContextFactory;
use KnowKit\Laravel\Http\HttpIdentity;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';
require_once dirname(__DIR__, 3) . '/examples/laravel/SearchRoutes.php';
require_once dirname(__DIR__, 3) . '/examples/laravel/SearchController.php';

#[Group('delivery:laravel')]
final class ContextTest extends TestCase
{
    public function testUntrustedHttpIdentityNeverReachesApplication(): void
    {
        $httpConfig = ['instances' => ['default' => ['http' => ['enabled' => true, 'registrar' => SearchRoutes::class]]]];
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, $httpConfig);
            $identity = $this->createMock(HttpIdentity::class);
            $host = new Identity(Fixture::policy());
            $identity->method('forUser')->willReturn($host);
            $app->instance(HttpIdentity::class, $identity);
            $application = $this->createMock(HostApplication::class);
            $application->expects(self::never())->method('execute');
            $app->instance(HostApplication::class, $application);
            Fixture::boot($app);
            foreach (Fixture::json('scenarios.json')['httpSpoofFields'] as $field) {
                $request = Fixture::request(['query' => 'synthetic', $field => 'forged-other-tenant']);
                $app->instance('request', $request);
                $response = $app['router']->dispatch($request);
                self::assertSame(403, $response->getStatusCode(), $field);
                self::assertStringNotContainsString('forged-other-tenant', $response->getContent());
                self::assertStringNotContainsString('hostScopeHash', $response->getContent());
                self::assertStringContainsString('untrusted_context', $response->getContent());
            }
            $request = Fixture::request(body: ['tenantId' => 'forged-other-tenant'], authenticated: false);
            $app->instance('request', $request);
            self::assertSame(401, $app['router']->dispatch($request)->getStatusCode());
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testHostContextPrevailsAndIsRecheckedBeforeApplication(): void
    {
        $policy = Fixture::policy();
        $host = new Identity($policy);
        $identity = $this->createMock(HttpIdentity::class);
        $identity->expects(self::once())->method('forUser')->with(self::callback(
            static fn (Authenticatable $user): bool => $user->getAuthIdentifier() === 'host-user',
        ))->willReturn($host);
        $request = Fixture::request(['query' => 'synthetic']);
        $request->headers->set('X-Tenant-Id', 'forged-other-tenant');
        $context = (new ContextFactory($identity))->fromRequest($request);
        self::assertSame($policy, $context->policy);
        self::assertSame($policy->scope, $context->scope());
        self::assertGreaterThanOrEqual(2, $host->reads);
        $host->policy = null;
        $this->expectException(D\DomainFailure::class);
        $context->assertCurrent();
    }

    public function testHostFailuresRemainDistinctAndRedacted(): void
    {
        $temporary = Qa::temporary();
        try {
            foreach ([D\Outcome::Unknown, D\Outcome::Unavailable, D\Outcome::Incompatible] as $outcome) {
                $app = Fixture::app($temporary, ['instances' => ['default' => ['http' => ['enabled' => true, 'registrar' => SearchRoutes::class]]]]);
                $identity = $this->createMock(HttpIdentity::class);
                $identity->method('forUser')->willThrowException(new D\DomainFailure($outcome, 'identity_failure'));
                $app->instance(HttpIdentity::class, $identity);
                Fixture::boot($app);
                $request = Fixture::request(['query' => 'synthetic']);
                $app->instance('request', $request);
                $response = $app['router']->dispatch($request);
                self::assertSame($outcome === D\Outcome::Incompatible ? 409 : 503, $response->getStatusCode());
                self::assertSame($outcome->value, json_decode($response->getContent(), true)['outcome']);
                self::assertStringNotContainsString('synthetic', $response->getContent());
            }
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testExampleRouteDelegatesToHostApplicationWithTrustedContext(): void
    {
        $httpConfig = ['instances' => ['default' => ['http' => ['enabled' => true, 'registrar' => SearchRoutes::class]]]];
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, $httpConfig);
            $identity = $this->createMock(HttpIdentity::class);
            $policy = Fixture::policy();
            $identity->method('forUser')->willReturn(new Identity($policy));
            $app->instance(HttpIdentity::class, $identity);
            $application = $this->createMock(HostApplication::class);
            $application->expects(self::once())->method('execute')->with(
                self::callback(static fn (Command $command): bool => $command->text('query') === 'synthetic'),
                self::callback(static fn (AccessContext $context): bool => $context->policy === $policy),
            )->willReturn(Response::result(D\Result::empty()));
            $app->instance(HostApplication::class, $application);
            Fixture::boot($app);
            $request = Fixture::request(['query' => 'synthetic']);
            $app->instance('request', $request);
            $response = $app['router']->dispatch($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('empty', json_decode($response->getContent(), true)['outcome']);
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testHttpOutcomesPreserveCliTaxonomyWithoutContentOnFailure(): void
    {
        $temporary = Qa::temporary();
        try {
            foreach (Fixture::json('scenarios.json')['outcomes'] as $vector) {
                $app = Fixture::app($temporary, ['instances' => ['default' => ['http' => ['enabled' => true, 'registrar' => SearchRoutes::class]]]]);
                $identity = $this->createMock(HttpIdentity::class);
                $identity->method('forUser')->willReturn(new Identity(Fixture::policy()));
                $app->instance(HttpIdentity::class, $identity);
                $result = match ($vector['outcome']) {
                    'success' => D\Result::success(new D\Limit(1)),
                    'empty' => D\Result::empty(),
                    default => D\Result::failure(new D\DomainFailure(D\Outcome::from($vector['outcome']), 'synthetic_failure')),
                };
                $envelope = Response::result(result: $result, data: new JsonObject(['text' => 'synthetic-content']));
                $application = $this->createMock(HostApplication::class);
                $application->method('execute')->willReturn($envelope);
                $app->instance(HostApplication::class, $application);
                Fixture::boot($app);
                $request = Fixture::request(['query' => 'synthetic']);
                $app->instance('request', $request);
                $response = $app['router']->dispatch($request);
                self::assertSame($vector['status'], $response->getStatusCode());
                self::assertSame($envelope->json(), $response->getContent());
                if ($vector['exit'] !== 0) {
                    self::assertStringNotContainsString('synthetic-content', $response->getContent());
                }
            }
        } finally {
            Qa::remove($temporary);
        }
    }
}
