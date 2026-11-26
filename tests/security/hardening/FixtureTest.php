<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/bootstrap.php';

#[Group('security:hardening')]
final class FixtureTest extends HardeningCase
{
    public function testVersionProvenanceDigestsAndRequiredCasesBindExecutableAssertions(): void
    {
        $qa = new Qa(self::root());
        $manifest = $qa->json('tests/fixtures/check-groups/security-hardening.json');
        $qa->fixtures($manifest);
        self::assertSame('security-hardening-fixtures:1', $this->cases()['schemaVersion']);
        self::assertSame($manifest['fixtureVersion'], $this->cases()['fixtureVersion']);
        self::assertNotEmpty($this->cases()['provenance']);
        self::assertNotEmpty($this->cases()['license']);
        self::assertSame($manifest['requiredCaseIds'], array_keys($this->cases()['expectedOutcomes']));
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                $file = __DIR__ . '/' . substr($class, strrpos($class, '\\') + 1) . '.php';
                require_once $file;
                self::assertTrue(method_exists($class, $name), $method);
                self::assertTrue((new \ReflectionMethod($class, $name))->isPublic());
                self::assertNotEmpty((new \ReflectionClass($class))->getAttributes(Group::class));
            }
        }
        $receipt = $qa->json('tests/security/hardening/evidence/P9.2-reuse.json');
        self::assertCount(7, $receipt['candidates']);
        foreach ($receipt['candidates'] as $candidate) {
            self::assertTrue($candidate['sha256Verified']);
            self::assertNotEmpty($candidate['decision']);
        }
    }

    public function testRunnerRejectsUnknownMissingSkippedEmptyAndNestedFailures(): void
    {
        $qa = new Qa(self::root());
        $manifest = ['requiredCaseIds' => ['mandatory'], 'testCaseMap' => ['mandatory' => ['actual']]];
        foreach ([
            [[], 'zero executed tests'],
            [[['id' => 'other', 'status' => 'passed']], 'missing required case'],
            [[['id' => 'actual', 'status' => 'skipped']], 'failed/skipped executed case'],
            [[['id' => 'actual', 'status' => 'failed']], 'failed/skipped executed case'],
        ] as [$cases, $marker]) {
            $this->refused(fn () => Qa::validateCases(cases: $cases, manifest: $manifest), $marker);
        }
        $this->refused(fn () => $qa->select(args: ['security:nonexistent'], registry: ['groups' => [['id' => 'security:hardening']]]), 'unknown QA group');
        file_put_contents($this->temporary . '/nested.xml', '<testsuites><testsuite tests="1"><testcase class="actual" name="case"><failure/></testcase></testsuite></testsuites>');
        $this->refused(fn () => Qa::junit($this->temporary . '/nested.xml'), 'failed/skipped JUnit');
        self::assertSame([['id' => 'mandatory', 'status' => 'passed']],
            Qa::validateCases(cases: [['id' => 'actual', 'status' => 'passed']], manifest: $manifest));
    }

    private function refused(\Closure $call, string $marker): void
    {
        try {
            $call();
            self::fail('Runner accepted incomplete evidence');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString($marker, $failure->getMessage());
        }
    }
}
