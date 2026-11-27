<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Runtime\KnowledgeFacade;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Laravel\HostApplication;
use KnowKit\Laravel\Jobs\JobHandler;
use KnowKit\Laravel\Jobs\JobIdentity;
use KnowKit\Laravel\KnowKitCommand;
use KnowKit\Laravel\KnowKitManager;
use KnowKit\Port\Clock;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Registry\Factory;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:laravel')]
final class MultiInstanceTest extends TestCase
{
    public function testManagerResolvesExplicitInstancesAndRejectsMissingWithoutFallback(): void
    {
        $temporary = Qa::temporary();
        try {
            $profile = file_get_contents(__DIR__ . '/fixtures/v1/profile.json');
            $clock = $this->createMock(Clock::class);
            $factory = $this->createMock(Factory::class);
            $factory->method('create')->willReturn($clock);

            $app = Fixture::app($temporary, [
                'default' => 'support_faq',
                'instances' => [
                    'support_faq' => [
                        'profile' => $profile,
                        'preset' => 'single_local',
                        'credentials' => ['fixture.key' => 'KNOWKIT_SUPPORT_KEY'],
                    ],
                    'enterprise_kb' => [
                        'profile' => $profile,
                        'preset' => 'multi_tenant_strict',
                        'credentials' => ['fixture.key' => 'KNOWKIT_ENTERPRISE_KEY'],
                    ],
                ],
            ]);
            $app->instance(AdapterRegistry::class, Fixture::registry($factory));

            $facadeA = (new \ReflectionClass(KnowledgeFacade::class))->newInstanceWithoutConstructor();
            $facadeB = (new \ReflectionClass(KnowledgeFacade::class))->newInstanceWithoutConstructor();
            $app->instance('knowkit.instances.support_faq.facade', $facadeA);
            $app->instance('knowkit.instances.enterprise_kb.facade', $facadeB);

            Fixture::boot($app);

            $manager = $app->make(KnowKitManager::class);
            self::assertSame('support_faq', $manager->instance()->id->value);
            self::assertSame($facadeA, $manager->instance()->facade);
            self::assertSame('enterprise_kb', $manager->instance('enterprise_kb')->id->value);
            self::assertSame($facadeB, $manager->instance('enterprise_kb')->facade);
            self::assertSame(D\IsolationPreset::SingleLocal, $manager->instance('support_faq')->preset);
            self::assertSame(D\IsolationPreset::MultiTenantStrict, $manager->instance('enterprise_kb')->preset);

            $described = $manager->describeAll();
            self::assertArrayHasKey('support_faq', $described);
            self::assertArrayHasKey('enterprise_kb', $described);
            $readiness = $manager->readinessAll();
            self::assertTrue($readiness['support_faq']->ready);
            self::assertTrue($readiness['enterprise_kb']->ready);

            try {
                $manager->instance('unknown_instance');
                self::fail('Missing instance must not fall back');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
                self::assertSame('instance_not_found', $failure->errorCode);
            }
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testManagerDefaultThrowsInstanceNotFoundWhenDefaultNotInInstances(): void
    {
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, [
                'default' => 'missing_default',
                'instances' => [
                    'other_kb' => [
                        'preset' => 'single_local',
                    ],
                ],
            ]);
            Fixture::boot($app);
            $manager = $app->make(KnowKitManager::class);

            $this->expectException(D\DomainFailure::class);
            $this->expectExceptionMessage('instance_not_found');
            $manager->instance();
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testCommandSupportsInstanceOptionAndRejectsUnknownInstance(): void
    {
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, [
                'default' => 'support_faq',
                'instances' => [
                    'support_faq' => ['preset' => 'single_local'],
                    'enterprise_kb' => ['preset' => 'multi_tenant_strict'],
                ],
                'commands' => ['enabled' => true],
            ]);
            $app->instance('knowkit.instances.support_faq.host_identity', new Identity(Fixture::policy()));
            $app->instance('knowkit.instances.enterprise_kb.host_identity', new Identity(Fixture::policy()));

            $appA = $this->createMock(HostApplication::class);
            $appA->expects(self::never())->method('execute');
            $appB = $this->createMock(HostApplication::class);
            $appB->expects(self::once())->method('execute')->willReturnCallback(
                static fn (Command $command, AccessContext $context): Response => Response::result(
                    D\Result::success(new D\Limit(1)),
                    new JsonObject(['instance' => 'enterprise_kb']),
                ),
            );
            $app->instance('knowkit.instances.support_faq.host_application', $appA);
            $app->instance('knowkit.instances.enterprise_kb.host_application', $appB);

            Fixture::boot($app);

            $command = new KnowKitCommand();
            $command->setLaravel($app);
            $output = new BufferedOutput();
            $exit = $command->run(
                input: new ArgvInput(['artisan', 'capabilities', '--instance=enterprise_kb']),
                output: $output,
            );
            self::assertSame(0, $exit);
            $decoded = json_decode($output->fetch(), true);
            self::assertSame('enterprise_kb', $decoded['data']['instance']);

            $badOutput = new BufferedOutput();
            $badExit = $command->run(
                input: new ArgvInput(['artisan', 'capabilities', '--instance=missing_kb']),
                output: $badOutput,
            );
            self::assertNotSame(0, $badExit);
            $badDecoded = json_decode($badOutput->fetch(), true);
            self::assertSame('incompatible', $badDecoded['outcome']);
            self::assertSame('instance_not_found', $badDecoded['error']['code']);
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testJobsAreScopedPerInstance(): void
    {
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, [
                'default' => 'support_faq',
                'instances' => [
                    'support_faq' => ['jobs' => ['enabled' => false]],
                    'enterprise_kb' => ['jobs' => ['enabled' => true]],
                ],
            ]);
            $dispatcher = $this->createMock(Dispatcher::class);
            $app->instance(Dispatcher::class, $dispatcher);
            $app->instance('knowkit.instances.enterprise_kb.job_identity', $this->createMock(JobIdentity::class));
            $app->instance('knowkit.instances.enterprise_kb.job_handler', $this->createMock(JobHandler::class));
            Fixture::boot($app);

            $manager = $app->make(KnowKitManager::class);
            $jobs = $manager->jobs('enterprise_kb');
            self::assertSame('enterprise_kb', $jobs->instanceId()?->value);

            $this->expectException(D\DomainFailure::class);
            $this->expectExceptionMessage('jobs_disabled');
            $manager->jobs('support_faq');
        } finally {
            Qa::remove($temporary);
        }
    }
}
