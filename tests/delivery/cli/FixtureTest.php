<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Cli;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TransportTest.php';
require_once __DIR__ . '/JourneyTest.php';
require_once __DIR__ . '/EmptyMembershipTest.php';

#[Group('delivery:cli')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndReuseBindTheCandidate(): void
    {
        $root = dirname(__DIR__, 3);
        $qa = new Qa($root);
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-cli.json');
        $qa->fixtures($manifest);
        self::assertSame('1.0.0', $manifest['fixtureVersion']);
        self::assertSame(['R01-A02'], array_column($manifest['deferredCases'], 'id'));
        self::assertNotContains('R01-A02', $manifest['requiredCaseIds']);
        $commands = $qa->json('tests/delivery/cli/fixtures/v1/commands.json');
        self::assertSame('cli-commands:1', $commands['schemaVersion']);
        self::assertNotEmpty($commands['rights']['provenance']);
        $outcomes = $qa->json('tests/delivery/cli/fixtures/v1/outcomes.json');
        self::assertSame($manifest['requiredCaseIds'], array_keys($outcomes['cases']));
        self::assertSame(0, filesize($root . '/examples/plain-php/fixtures/v1/empty.txt'));
        $receipt = $qa->json('tests/delivery/cli/evidence/P4.5-reuse.json');
        self::assertCount(5, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
        }
        $lines = '';
        foreach ($receipt['candidateDigest']['paths'] as $path) {
            self::assertFileExists($root . '/' . $path);
            $lines .= $path . "\0" . hash_file('sha256', $root . '/' . $path) . "\n";
        }
        self::assertSame($receipt['candidateDigest']['sha256'], hash('sha256', $lines));
        self::assertSame(['P4.5-reuse.json'], array_map('basename', glob(__DIR__ . '/evidence/*')));
    }

    public function testRequiredCasesFailForMissingSkippedFailedEmptyAndUnknownGroup(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-cli.json');
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(array_values($cases), $manifest));
        $required = ['requiredCaseIds' => ['required'], 'testCaseMap' => ['required' => ['Example::testRequired']]];
        foreach ([[], [['id' => 'Example::testOther', 'status' => 'passed']],
            [['id' => 'Example::testRequired', 'status' => 'skipped']],
            [['id' => 'Example::testRequired', 'status' => 'failed']]] as $invalid) {
            $this->rejects(static fn () => Qa::validateCases($invalid, $required));
        }
        $this->rejects(static fn () => $qa->select(['unknown:delivery-cli'], $qa->registry()));
        $this->rejects(static fn () => $qa->file('tests/delivery/cli/MissingTest.php'));
        self::assertSame(9, $qa->process([PHP_BINARY, '-r', 'exit(9);'])['exit']);
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            self::fail('Required-case violation accepted');
        } catch (\RuntimeException $error) {
            self::assertNotEmpty($error->getMessage());
        }
    }
}
