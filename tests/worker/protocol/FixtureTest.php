<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once Fixture::root() . '/tools/qa.php';

#[Group('worker:protocol')]
final class FixtureTest extends TestCase
{
    public function testVersionedManifestBindsAllRequiredCasesAndGoldenDigests(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/worker-protocol.json');
        $qa->fixtures($manifest);
        $cases = $qa->json('tests/worker/protocol/fixtures/v1/cases.json');
        self::assertSame('worker-protocol-fixtures-1.0', $cases['schemaVersion']);
        self::assertSame($manifest['fixtureVersion'], $cases['fixtureVersion']);
        self::assertNotEmpty($cases['provenance']);
        self::assertNotEmpty($cases['license']);
        self::assertSame($manifest['requiredCaseIds'], array_column($cases['cases'], 'id'));
        foreach ($cases['cases'] as $case) {
            self::assertNotEmpty($case['expected']);
            self::assertNotEmpty($manifest['testCaseMap'][$case['id']]);
        }
        self::assertSame($cases['expectedDigests']['ir'], Fixture::wire('result')->get('irDigest'));
        self::assertSame($cases['expectedDigests']['result'], Fixture::wire('result')->get('resultDigest'));
        self::assertSame($cases['expectedDigests']['source'], hash_file('sha256', __DIR__ . '/fixtures/v1/source.txt'));
        self::assertSame($manifest['components']['workerLock']['sha256'],
            hash_file('sha256', Fixture::root() . '/python/knowkit-worker/uv.lock'));
    }

    public function testRunnerRejectsUnknownEmptyMissingSkippedAndNestedFailure(): void
    {
        $qa = new Qa(Fixture::root());
        $this->rejects(static fn () => $qa->select(['worker:not-a-group'], $qa->registry()), 'unknown QA group');
        $manifest = ['requiredCaseIds' => ['required'], 'testCaseMap' => ['required' => ['actual']]];
        $this->rejects(static fn () => Qa::validateCases([], $manifest), 'zero executed');
        $this->rejects(static fn () => Qa::validateCases([['id' => 'other', 'status' => 'passed']], $manifest), 'missing required');
        $this->rejects(static fn () => Qa::validateCases([['id' => 'actual', 'status' => 'skipped']], $manifest), 'failed/skipped');
        $group = array_values(array_filter($qa->registry()['groups'],
            static fn (array $group): bool => $group['id'] === 'worker:protocol'))[0];
        $group['commands'] = [[PHP_BINARY, '-r', 'exit(7);']];
        $this->rejects(static fn () => $qa->group($group), 'child failed: worker:protocol exit 7');
        $directory = Fixture::temporary();
        try {
            mkdir($directory . '/empty');
            file_put_contents($directory . '/phpunit.xml',
                '<phpunit><testsuites><testsuite name="Worker"><directory>missing</directory></testsuite></testsuites></phpunit>');
            $isolated = new Qa($directory);
            $this->rejects(static fn () => $isolated->suite('Worker'), 'missing suite directory');
            file_put_contents($directory . '/phpunit.xml',
                '<phpunit><testsuites><testsuite name="Worker"><directory>empty</directory></testsuite></testsuites></phpunit>');
            $this->rejects(static fn () => $isolated->suite('Worker'), 'empty suite');
        } finally {
            \KnowKit\Adapter\ProcessWorker\WorkerFiles::remove($directory);
        }
    }

    private function rejects(\Closure $call, string $message): void
    {
        try {
            $call();
            self::fail('QA accepted invalid execution');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString($message, $failure->getMessage());
        }
    }
}
