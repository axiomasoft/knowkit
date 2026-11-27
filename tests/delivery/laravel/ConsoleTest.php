<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use Illuminate\Console\Application as Artisan;
use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Kernel;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Laravel\ConsoleKernel;
use KnowKit\Laravel\HostApplication;
use KnowKit\Laravel\KnowKitCommand;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:laravel')]
final class ConsoleTest extends TestCase
{
    public function testArtisanAndCoreCliKeepEveryOutcomeEnvelopeAndExit(): void
    {
        $temporary = Qa::temporary();
        try {
            foreach (Fixture::json('scenarios.json')['outcomes'] as $vector) {
                $app = Fixture::app($temporary, ['instances' => ['default' => []]]);
                $app->register(\KnowKit\Laravel\KnowKitServiceProvider::class);
                $host = new Identity(Fixture::policy());
                $app->instance(HostIdentity::class, $host);
                $application = $this->createMock(HostApplication::class);
                $application->method('execute')->willReturnCallback(function (Command $command, AccessContext $context) use ($vector): Response {
                    self::assertSame('search', $command->name);
                    self::assertSame('synthetic', $command->text('query'));
                    self::assertSame('support.answer', $context->scope()->purpose);
                    echo 'accidental provider debug must disappear';

                    return self::outcome($vector['outcome']);
                });
                $app->instance(HostApplication::class, $application);
                $command = new KnowKitCommand();
                $command->setLaravel($app);
                $tester = new CommandTester($command);
                $exit = $tester->execute([
                    'words' => ['search'],
                    '--input' => __DIR__ . '/fixtures/v1/search.json',
                    '--json' => true,
                ]);
                $core = new Kernel(static function (Command $command) use ($host, $application): Response {
                    $context = AccessContext::fromHost($host);
                    $context->assertCurrent();

                    return $application->execute(command: $command, context: $context);
                });
                $expected = $core->run(arguments: ['search', '--input=-'], stdin: '{"query":"synthetic"}');
                self::assertSame($vector['exit'], $exit);
                self::assertSame($expected->exit, $exit);
                self::assertSame($expected->json() . "\n", $tester->getDisplay());
                self::assertSame($vector['outcome'], json_decode($tester->getDisplay(), true)['outcome']);
                self::assertStringNotContainsString('provider debug', $tester->getDisplay());

                $artisan = new Artisan(laravel: $app, events: $app['events'], version: 'test');
                $artisan->setAutoExit(false);
                $artisan->add($command);
                $output = new BufferedOutput();
                $input = new ArgvInput(['artisan', 'knowkit', 'search', '--input=' . __DIR__ . '/fixtures/v1/search.json', '--json']);
                self::assertSame($expected->exit, $artisan->run(input: $input, output: $output));
                self::assertSame($expected->json() . "\n", $output->fetch());
            }
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testForgedToolIdentityCannotEnlargeHostScope(): void
    {
        $host = new Identity(Fixture::policy());
        $application = $this->createMock(HostApplication::class);
        $application->expects(self::never())->method('execute');
        $kernel = new ConsoleKernel(identity: $host, application: $application);
        foreach (['tenantId', 'subject', 'grants', 'purpose'] as $field) {
            $response = $kernel->run(arguments: ['search', '--input=-'], stdin: json_encode([
                'query' => 'synthetic', $field => 'forged-other-tenant',
            ]));
            self::assertSame(2, $response->exit);
            self::assertStringNotContainsString('forged-other-tenant', $response->json());
        }
        self::assertSame(0, $host->reads);
    }

    public function testTerminalArgumentsPreserveCoreGrammarBeforeDispatch(): void
    {
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, ['instances' => ['default' => []]]);
            $app->register(\KnowKit\Laravel\KnowKitServiceProvider::class);
            $host = new Identity(Fixture::policy());
            $app->instance(HostIdentity::class, $host);
            $application = $this->createMock(HostApplication::class);
            $application->expects(self::never())->method('execute');
            $app->instance(HostApplication::class, $application);
            $core = new Kernel(static fn (): Response => throw new \LogicException('Unexpected dispatch'));
            foreach (Fixture::json('argv.json') as $vector) {
                $arguments = array_map(
                    static fn (string $argument): string => str_replace('<input>', __DIR__ . '/fixtures/v1/search.json', $argument),
                    $vector['arguments'],
                );
                $expected = $core->run($arguments);
                self::assertSame($vector['exit'], $expected->exit, $vector['id']);
                $command = new KnowKitCommand();
                $command->setLaravel($app);
                $output = new BufferedOutput();
                $exit = $command->run(input: new ArgvInput(['artisan', ...$arguments]), output: $output);
                self::assertSame($expected->exit, $exit, $vector['id']);
                self::assertSame($expected->json() . "\n", $output->fetch(), $vector['id']);

                $artisan = new Artisan(laravel: $app, events: $app['events'], version: 'test');
                $artisan->setAutoExit(false);
                $artisan->add($command);
                $input = new ArgvInput(['artisan', 'knowkit', ...$arguments]);
                self::assertSame($expected->exit, $artisan->run(input: $input, output: $output), $vector['id']);
                self::assertSame($expected->json() . "\n", $output->fetch(), $vector['id']);
            }
            self::assertSame(0, $host->reads);
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testArtisanSyntaxErrorsKeepCoreUsageExitAndSafeEnvelope(): void
    {
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary, ['instances' => ['default' => []]]);
            $app->register(\KnowKit\Laravel\KnowKitServiceProvider::class);
            $app->instance(HostIdentity::class, new Identity(Fixture::policy()));
            $application = $this->createMock(HostApplication::class);
            $application->expects(self::never())->method('execute');
            $app->instance(HostApplication::class, $application);
            foreach ([[], ['words' => ['unknown']], ['words' => ['source', 'upload']]] as $arguments) {
                $command = new KnowKitCommand();
                $command->setLaravel($app);
                $tester = new CommandTester($command);
                self::assertSame(2, $tester->execute($arguments));
                self::assertSame(Response::error(code: 'invalid_command', exit: 2)->json() . "\n", $tester->getDisplay());
            }
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testCurrentHostIdentityAndInternalErrorRedaction(): void
    {
        $host = new Identity(Fixture::policy());
        $application = $this->createMock(HostApplication::class);
        $application->expects(self::once())->method('execute')->willThrowException(new \RuntimeException('raw provider document and secret'));
        $kernel = new ConsoleKernel(identity: $host, application: $application);
        $response = $kernel->run(['capabilities']);
        self::assertSame(70, $response->exit);
        self::assertStringNotContainsString('raw provider', $response->json());
        $host->policy = null;
        self::assertSame(3, $kernel->run(['capabilities'])->exit);
    }

    public function testUnconfiguredConsoleCompositionReturnsRedactedInternalEnvelope(): void
    {
        $temporary = Qa::temporary();
        try {
            $app = Fixture::app($temporary);
            $command = new KnowKitCommand();
            $command->setLaravel($app);
            $tester = new CommandTester($command);
            self::assertSame(70, $tester->execute(['words' => ['capabilities']]));
            self::assertSame(Response::error(code: 'internal_error', exit: 70)->json() . "\n", $tester->getDisplay());
        } finally {
            Qa::remove($temporary);
        }
    }

    private static function outcome(string $outcome): Response
    {
        $result = match ($outcome) {
            'success' => D\Result::success(new D\Limit(1)),
            'empty' => D\Result::empty(),
            default => D\Result::failure(
                failure: new D\DomainFailure(D\Outcome::from($outcome), 'synthetic_failure'),
                retryable: in_array($outcome, ['unknown', 'unavailable'], true),
            ),
        };

        return Response::result(result: $result, data: new JsonObject(['message' => '<info>synthetic</info>']));
    }
}
