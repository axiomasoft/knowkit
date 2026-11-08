<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Acceptance;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\JobEnvelope;
use KnowKit\Tests\Worker\Protocol\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:acceptance')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndRequiredCaseBindingsAreExact(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/worker-acceptance.json');
        $qa->fixtures($manifest);
        $cases = $qa->json('tests/fixtures/worker/acceptance/v1/cases.json');
        self::assertSame('worker-acceptance-fixtures-1.0', $cases['schemaVersion']);
        self::assertSame($manifest['fixtureVersion'], $cases['fixtureVersion']);
        self::assertNotEmpty($cases['versionRationale']);
        self::assertNotEmpty($cases['license']);
        self::assertNotEmpty($cases['provenance']);
        self::assertSame($manifest['requiredCaseIds'], array_column($cases['cases'], 'id'));
        $directory = Fixture::root() . '/tests/fixtures/worker/acceptance/v1';
        $job = new JobEnvelope(StrictJson::decode(file_get_contents($directory . '/envelope.json')), Fixture::validator());
        $root = Fixture::temporary();
        try {
            copy($directory . '/ir.json', $root . '/ir.json');
            copy($directory . '/result.json', $root . '/result.json');
            $verified = (new WorkerIngressBundleValidator(Fixture::validator()))->verify($job, $root);
            self::assertSame($cases['expectedDigests']['source'], hash_file('sha256', $directory . '/source.txt'));
            self::assertSame($cases['expectedDigests']['ir'], $verified->result->irDigest());
            self::assertSame($cases['expectedDigests']['result'], $verified->result->resultDigest()->value);
        } finally {
            WorkerFiles::remove($root);
        }
        foreach ($cases['cases'] as $case) {
            self::assertNotEmpty($case['expected']);
            self::assertContains($case['assertion'], $manifest['testCaseMap'][$case['id']]);
        }
    }

    public function testRunnerRejectsMissingUnknownEmptySkippedAndNestedFailure(): void
    {
        $qa = new Qa(Fixture::root());
        $unknown = $qa->process([PHP_BINARY, 'tools/qa.php', 'worker:does-not-exist']);
        self::assertNotSame(0, $unknown['exit']);
        $manifest = ['requiredCaseIds' => ['DT-Q06'], 'testCaseMap' => ['DT-Q06' => ['actual']]];
        foreach ([[], [['id' => 'other', 'status' => 'passed']], [['id' => 'actual', 'status' => 'skipped']]] as $executed) {
            $this->rejects(static fn () => Qa::validateCases($executed, $manifest));
        }
        $group = array_values(array_filter($qa->registry()['groups'],
            static fn (array $group): bool => $group['id'] === 'worker:acceptance'))[0];
        $group['commands'] = [[PHP_BINARY, '-r', 'exit(9);']];
        $this->rejects(static fn () => $qa->group($group));
        $root = Fixture::temporary();
        try {
            mkdir($root . '/empty');
            foreach (['missing', 'empty'] as $directory) {
                file_put_contents($root . '/phpunit.xml', '<phpunit><testsuites><testsuite name="Worker"><directory>'
                    . $directory . '</directory></testsuite></testsuites></phpunit>');
                $this->rejects(static fn () => (new Qa($root))->suite('Worker'));
            }
        } finally {
            WorkerFiles::remove($root);
        }
    }

    private function rejects(\Closure $call): void
    {
        try {
            $call();
            self::fail('QA accepted missing/skipped/failed work');
        } catch (\RuntimeException $failure) {
            self::assertNotSame('', $failure->getMessage());
        }
    }
}
