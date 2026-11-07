<?php
declare(strict_types=1);

namespace KnowKit\Tests\Sources\Inventory;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('sources:inventory')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndIndependentFullHashes(): void
    {
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/sources-inventory.json');
        $data = Fixture::data();
        self::assertSame('sources-inventory-fixtures:1', $data['schemaVersion']);
        self::assertSame($manifest['requiredCaseIds'], $data['requiredCaseIds']);
        self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['versionRationale']);
        foreach ($manifest['fixtures'] as $fixture) {
            self::assertSame($fixture['sha256'], hash_file('sha256', Fixture::root() . '/' . $fixture['path']));
        }
        foreach ($data['scenarios']['initial']['items'] as $item) {
            self::assertSame(hash('sha256', $item['bytes']), $item['contentDigest']);
            self::assertSame((string) strlen($item['bytes']), $item['byteLength']);
        }
        self::assertSame($data['initialInventoryDigest'], Fixture::snapshot($data['scenarios']['initial'])->inventoryDigest->value);
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                require_once __DIR__ . '/' . substr($class, strrpos($class, '\\') + 1) . '.php';
                self::assertTrue(method_exists($class, $name), $method);
            }
        }
    }

    public function testRunnerRejectsMissingSkippedFailedAndZeroCases(): void
    {
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/sources-inventory.json');
        $ids = array_unique(array_merge(...array_values($manifest['testCaseMap'])));
        $cases = array_values(array_map(static fn (string $id): array => ['id' => $id, 'status' => 'passed'], $ids));
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases($cases, $manifest));
        $skipped = $cases;
        $skipped[0]['status'] = 'skipped';
        $failed = $cases;
        $failed[0]['status'] = 'failed';
        foreach ([[], array_slice($cases, 1), $skipped, $failed, [...$cases, $cases[0]]] as $invalid) {
            try {
                Qa::validateCases($invalid, $manifest);
                self::fail('Runner must reject incomplete execution');
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }
}
