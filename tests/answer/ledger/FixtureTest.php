<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Ledger;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:ledger')]
final class FixtureTest extends TestCase
{
    private function manifest(): array
    {
        return (new Qa(dirname(__DIR__, 3)))->json('tests/fixtures/check-groups/answer-ledger.json');
    }

    public function testVersionedFixturesAndPinnedReuseReceipt(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $this->manifest();
        $qa->fixtures($manifest);
        $qa->suite('Answer');
        $corpus = $qa->json('tests/fixtures/answer/ledger/v1/cases.json');
        self::assertSame('answer-ledger:1', $corpus['schemaVersion']);
        self::assertSame('1.0.0', $corpus['fixtureVersion']);
        self::assertSame($manifest['requiredCaseIds'], array_column($corpus['cases'], 'id'));
        self::assertCount(10, $corpus['cases']);
        foreach ($corpus['cases'] as $case) {
            self::assertContains($case['providerSends'], [0, 1]);
            self::assertNotEmpty($case['expected']);
        }
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $identity) {
                [$class, $method] = explode('::', $identity);
                require_once __DIR__ . '/' . substr($class, strrpos($class, '\\') + 1) . '.php';
                self::assertTrue(method_exists($class, $method), $identity);
            }
        }
        $receipt = $qa->json('tests/answer/ledger/evidence/P4.8-reuse.json');
        self::assertSame('P4.8', $receipt['item']);
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            $path = '.swissknifeman/orch/donor-cache/' . $source['donor'] . '/' . $source['path'];
            self::assertSame($source['sha256'], hash_file('sha256', $qa->file($path)));
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['license']);
            self::assertNotEmpty($source['reason']);
            self::assertFileExists($qa->file($source['qualificationRef']));
        }
        self::assertSame(['P4.8-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
    }

    public function testManifestRejectsMissingSkippedFailedDuplicateAndZeroCases(): void
    {
        $manifest = $this->manifest();
        $names = [];
        foreach ($manifest['testCaseMap'] as $mapped) {
            $names = array_merge($names, $mapped);
        }
        $cases = array_map(static fn (string $id): array => ['id' => $id, 'status' => 'passed'], array_values(array_unique($names)));
        self::assertCount(10, Qa::validateCases($cases, $manifest));
        $skipped = $cases;
        $skipped[0]['status'] = 'skipped';
        $failed = $cases;
        $failed[0]['status'] = 'failed';
        foreach ([[], array_slice($cases, 1), $skipped, $failed, [...$cases, $cases[0]]] as $invalid) {
            try {
                Qa::validateCases($invalid, $manifest);
                self::fail('Required-case validation accepted incomplete execution');
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        $manifest['fixtures'][0]['sha256'] = str_repeat('0', 64);
        try {
            (new Qa(dirname(__DIR__, 3)))->fixtures($manifest);
            self::fail('Tampered fixture digest accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('fixture digest mismatch', $error->getMessage());
        }
    }
}
