<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ProviderTest.php';
require_once __DIR__ . '/ContextTest.php';
require_once __DIR__ . '/JobsTest.php';
require_once __DIR__ . '/ConsoleTest.php';
require_once __DIR__ . '/IsolationTest.php';

#[Group('delivery:laravel')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndReuseBindCandidate(): void
    {
        $root = dirname(__DIR__, 3);
        $qa = new Qa($root);
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-laravel.json');
        $qa->fixtures($manifest);
        self::assertSame('1.1.0', $manifest['fixtureVersion']);
        self::assertContains('R18-A01', $manifest['requiredCaseIds']);
        self::assertContains('R18-A02', $manifest['requiredCaseIds']);
        $receipt = $qa->json('tests/delivery/laravel/evidence/P7.1-reuse.json');
        self::assertCount(3, $receipt['sources']);
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
        self::assertSame(['P7.1-reuse.json'], array_map('basename', glob(__DIR__ . '/evidence/*')));
    }

    public function testRequiredCasesFailForMissingSkippedFailedEmptyAndUnknown(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-laravel.json');
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
        $this->rejects(static fn () => $qa->select(['unknown:laravel'], $qa->registry()));
        $this->rejects(static fn () => $qa->file('tests/delivery/laravel/MissingTest.php'));
        self::assertSame(9, $qa->process([PHP_BINARY, '-r', 'exit(9);'])['exit']);
        $qa->suite('Laravel');
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
