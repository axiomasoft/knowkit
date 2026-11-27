<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Domain as D;
use KnowKit\Laravel\Jobs\JobHandler;
use KnowKit\Laravel\Jobs\JobIdentity;
use KnowKit\Laravel\Jobs\Jobs;
use KnowKit\Laravel\Jobs\RunJob;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:laravel')]
final class JobsTest extends TestCase
{
    public function testJobsOptInNeverDispatchesUntilHostRequestsWork(): void
    {
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, ['instances' => ['default' => ['jobs' => ['enabled' => true]]]]);
            $dispatcher = $this->createMock(Dispatcher::class);
            $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
                static fn (RunJob $job): bool => get_object_vars($job) === ['jobRef' => Fixture::JOB],
            ));
            $identity = $this->createMock(JobIdentity::class);
            $host = new Identity(Fixture::policy());
            $identity->expects(self::once())->method('forJob')->with(Fixture::JOB)->willReturn($host);
            $handler = $this->createMock(JobHandler::class);
            $handler->expects(self::once())->method('handle')->with(
                Fixture::JOB,
                self::callback(static fn (AccessContext $context): bool => $context->policy === $host->policy),
            );
            $app->instance(Dispatcher::class, $dispatcher);
            $app->instance(JobIdentity::class, $identity);
            $app->instance(JobHandler::class, $handler);
            Fixture::boot($app);
            self::assertSame(0, $host->reads);
            self::assertCount(0, $app['router']->getRoutes());
            $jobs = $app->make(Jobs::class);
            self::assertSame(0, $host->reads);
            $jobs->dispatch(Fixture::JOB);
            self::assertSame(0, $host->reads);
            (new RunJob(Fixture::JOB))->handle($jobs);
            self::assertGreaterThanOrEqual(2, $host->reads);
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testQueuedJobReauthorizesAndCannotReplaySerializedPrivileges(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');
        $host = new Identity(Fixture::policy());
        $identity = $this->createMock(JobIdentity::class);
        $identity->method('forJob')->willReturn($host);
        $handler = $this->createMock(JobHandler::class);
        $handler->expects(self::never())->method('handle');
        $jobs = new Jobs(enabled: true, dispatcher: $dispatcher, identity: $identity, handler: $handler);
        $job = unserialize(serialize(new RunJob(Fixture::JOB)), ['allowed_classes' => [RunJob::class]]);
        $host->policy = null;
        try {
            $job->handle($jobs);
            self::fail('Revoked job was executed');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Denied, $failure->outcome);
        }
        $disabled = new Jobs(enabled: false, dispatcher: $dispatcher, identity: $identity, handler: $handler);
        foreach ([fn () => $disabled->dispatch(Fixture::JOB), fn () => $disabled->run(Fixture::JOB)] as $operation) {
            try {
                $operation();
                self::fail('Disabled job was executed');
            } catch (D\DomainFailure $failure) {
                self::assertSame('jobs_disabled', $failure->errorCode);
            }
        }
    }
}
