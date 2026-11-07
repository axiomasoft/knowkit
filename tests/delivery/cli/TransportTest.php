<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Cli;

use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Grammar;
use KnowKit\Adapter\Cli\Kernel;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKitExample\Host;
use KnowKitExample\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/examples/plain-php/bootstrap.php';

#[Group('delivery:cli')]
final class TransportTest extends TestCase
{
    public function testMalformedCommandsFailBeforeHostAndNeverEchoInputs(): void
    {
        $called = 0;
        $kernel = new Kernel(static function () use (&$called): Response {
            ++$called;

            return Runtime::ok(new JsonObject());
        });
        $cases = json_decode(file_get_contents(__DIR__ . '/fixtures/v1/commands.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($cases['malformed'] as $case) {
            $stdin = isset($case['stdinBytes']) ? str_repeat(' ', $case['stdinBytes']) : $case['stdin'];
            $response = $kernel->run($case['argv'], $stdin);
            self::assertSame(2, $response->exit, $case['id']);
            self::assertSame('invalid_command', $response->envelope->get('error')->get('code'));
            self::assertStringNotContainsString('SYNTHETIC_SECRET', $response->json());
            self::assertStringNotContainsString('/private/', $response->json());
        }
        self::assertSame(0, $called);
    }

    public function testEveryGrammarRouteForwardsMutationAndHostOwnedInput(): void
    {
        $seen = [];
        $kernel = new Kernel(static function (Command $command) use (&$seen): Response {
            $seen[] = $command;

            return Runtime::ok(new JsonObject(['command' => $command->name]));
        });
        $cases = json_decode(file_get_contents(__DIR__ . '/fixtures/v1/commands.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($cases['valid'] as $case) {
            $response = $kernel->run($case['argv'], json_encode((object) $case['input'], JSON_THROW_ON_ERROR));
            self::assertSame(0, $response->exit, $case['command']);
            $command = $seen[array_key_last($seen)];
            self::assertSame($case['command'], $command->name);
            self::assertSame($case['mutation'] ? 'fixture-key' : null, $command->key?->value);
            self::assertSame($case['epoch'] ? '0' : null, $command->expectedEpoch?->value);
            self::assertSame($case['revision'] ? '0' : null, $command->expectedRevision?->value);
        }
        self::assertSame(Grammar::commands(), array_column($cases['valid'], 'command'));
    }

    public function testTypedOutcomesKeepDistinctExitCodesAndFailureRedaction(): void
    {
        $cases = [[D\Outcome::Denied, 3], [D\Outcome::Unknown, 4],
            [D\Outcome::Unavailable, 4], [D\Outcome::Incompatible, 5]];
        foreach ($cases as [$outcome, $exit]) {
            $kernel = new Kernel(static fn (): Response => Response::result(
                D\Result::failure(new D\DomainFailure($outcome, 'fixture_error'), $outcome === D\Outcome::Unknown),
                new JsonObject(['text' => 'SYNTHETIC_SECRET'])));
            $response = $kernel->run(['capabilities', '--json']);
            self::assertSame($exit, $response->exit);
            self::assertSame($outcome->value, $response->envelope->get('outcome'));
            self::assertFalse($response->envelope->has('data'));
            self::assertStringNotContainsString('SYNTHETIC_SECRET', $response->json());
        }
        $empty = Response::result(D\Result::empty());
        self::assertSame(0, $empty->exit);
        self::assertSame([], $empty->envelope->get('data'));
        self::assertSame('empty', $empty->envelope->get('outcome'));
    }

    public function testInternalErrorsAndAccidentalHostOutputAreRedacted(): void
    {
        $kernel = new Kernel(static function (): never {
            echo 'SYNTHETIC_SECRET document text /private/machine';
            throw new \RuntimeException('SYNTHETIC_SECRET provider inputs');
        });
        ob_start();
        $response = $kernel->run(['capabilities', '--json']);
        $leaked = ob_get_clean();
        self::assertSame('', $leaked);
        self::assertSame(70, $response->exit);
        self::assertSame('internal_error', $response->envelope->get('error')->get('code'));
        self::assertStringNotContainsString('SYNTHETIC_SECRET', $response->json());
        self::assertStringNotContainsString('/private/', $response->json());
    }

    public function testExecutableUsesExactlyOneEnvelopeAndSafeStderr(): void
    {
        foreach ([['malformed', '--SYNTHETIC_SECRET=/private/path'], ['capabilities', '--json']] as $args) {
            $result = self::process([PHP_BINARY, dirname(__DIR__, 3) . '/packages/core/bin/knowkit', ...$args],
                ['KNOWKIT_HOST_BOOTSTRAP' => '/private/SYNTHETIC_SECRET-missing.php']);
            self::assertSame($args[0] === 'malformed' ? 2 : 4, $result['exit']);
            self::assertCount(1, explode("\n", trim($result['stdout'])));
            $wire = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('outcome', $wire);
            self::assertStringNotContainsString('/private/', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('SYNTHETIC_SECRET', $result['stdout'] . $result['stderr']);
        }
    }

    public function testInstalledComposerBinaryLoadsHostOnlyAfterValidation(): void
    {
        $root = dirname(__DIR__, 3);
        $host = $root . '/tests/delivery/cli/fixtures/v1/host.php';
        $result = self::process([PHP_BINARY, 'vendor/bin/knowkit', 'capabilities', '--json'],
            ['KNOWKIT_HOST_BOOTSTRAP' => $host]);
        self::assertSame(0, $result['exit']);
        self::assertSame('', $result['stderr']);
        self::assertSame('empty', json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR)['outcome']);
        self::assertStringNotContainsString('SYNTHETIC_SECRET', $result['stdout']);
        $invalid = self::process([PHP_BINARY, 'vendor/bin/knowkit', 'capabilities', '--tenant', 'SYNTHETIC_SECRET'],
            ['KNOWKIT_HOST_BOOTSTRAP' => $host]);
        self::assertSame(2, $invalid['exit']);
        self::assertStringNotContainsString('SYNTHETIC_SECRET', $invalid['stdout'] . $invalid['stderr']);
    }

    public static function process(array $argv, ?array $environment = null): array
    {
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 3), $environment === null ? null : array_merge(getenv(), $environment));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
