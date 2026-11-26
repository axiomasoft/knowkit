<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Contracts;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:contracts')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndPinnedDonorDigests(): void
    {
        $qa = new Qa(Fixture::root());
        $corpus = Fixture::corpus();
        self::assertSame('answer-contracts:1', $corpus['schemaVersion']);
        self::assertSame('1.0.0', $corpus['fixtureVersion']);
        self::assertNotEmpty($corpus['versionRationale']);
        self::assertNotEmpty($corpus['rights']['license']);
        $manifest = $qa->json('tests/fixtures/check-groups/answer-contracts.json');
        $qa->fixtures($manifest);
        $receipt = $qa->json('tests/answer/contracts/evidence/P4.7-reuse.json');
        self::assertSame('P4.7', $receipt['item']);
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            $this->assertPinnedDonor($source);
            self::assertFileExists($qa->file($source['qualificationRef']));
        }
        $this->rejects(function () use ($qa, $manifest): void {
            $manifest['fixtures'][0]['sha256'] = str_repeat('0', 64);
            $qa->fixtures($manifest);
        });
        $qa->suite('Answer');
        $group = array_values(array_filter($qa->registry()['groups'], static fn (array $g): bool => $g['id'] === 'answer:contracts'))[0];
        self::assertSame('packages/answer', $group['composerProject']);
        self::assertSame('8.3', $group['minPhp']);
        self::assertFileExists($qa->file('packages/answer/composer.lock'));
        self::assertFileExists($qa->file('packages/answer/vendor/autoload.php'));
        self::assertFileExists($qa->file('packages/answer/vendor/bin/phpunit'));
    }

    public function testRequiredManifestRejectsMissingSkippedFailedAndZeroCases(): void
    {
        require_once __DIR__ . '/ContractsTest.php';
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/answer-contracts.json');
        self::assertSame([
            'answer-contracts:variants',
            'bounds-and-utf8',
            'missing-configuration-no-io',
            'scripted-rejected-production',
            'full-host-scope',
            'model-vs-embedding-binding-independent',
        ], $manifest['requiredCaseIds']);
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(6, Qa::validateCases(array_values($cases), $manifest));
        $required = ['requiredCaseIds' => ['required'], 'testCaseMap' => ['required' => ['Actual::required']]];
        foreach ([
            [],
            [['id' => 'Actual::other', 'status' => 'passed']],
            [['id' => 'Actual::required', 'status' => 'skipped']],
            [['id' => 'Actual::required', 'status' => 'failed']],
            [['id' => 'Actual::required', 'status' => 'passed'], ['id' => 'Actual::required', 'status' => 'passed']],
        ] as $executed) {
            $this->rejects(static fn () => Qa::validateCases($executed, $required));
        }
    }

    /** @param array<string, mixed> $source */
    private function assertPinnedDonor(array $source): void
    {
        // The private donor checkout is not part of the public package.
        self::assertSame($source['sha256'], $source['observedSha256']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $source['sha256']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/', $source['pin']);
        self::assertStringStartsWith('https://', $source['sourceUrl']);
        self::assertStringContainsString('/' . $source['pin'] . '/' . $source['path'], $source['sourceUrl']);
        self::assertContains($source['decision'], ['adapt', 'reject']);
        self::assertNotEmpty($source['license']);
        self::assertNotEmpty($source['reason']);
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
        } catch (\RuntimeException $error) {
            self::assertNotEmpty($error->getMessage());

            return;
        }
        self::fail('Invalid fixture/runner result accepted');
    }
}
