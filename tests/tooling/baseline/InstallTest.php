<?php
declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;

#[Group('tooling:baseline')]
final class InstallTest extends Support
{
    public function testLockedCleanInstallAndDiscoveryWithoutHostRuntimes(): void
    {
        foreach (['composer.json', 'composer.lock', 'packages/core/composer.json', 'packages/core/bin/knowkit', 'phpunit.xml',
            'tests/tooling/baseline/bootstrap.php', 'tests/tooling/baseline/CandidateAutoload.php', 'tools/qa.php'] as $path) {
            $this->copy($path);
        }
        $xml = new \SimpleXMLElement((string) file_get_contents($this->qa->file('phpunit.xml')));
        foreach ($xml->xpath('//testsuite/directory') ?: [] as $dir) {
            $this->put(trim((string) $dir) . '/.gitkeep', '');
        }
        $fixture = file_get_contents($this->qa->file('tests/tooling/baseline/fixtures/v1/DiscoveryTest.php.fixture'));
        Qa::ensure($fixture !== false, 'cannot read discovery fixture');
        $this->put('tests/tooling/baseline/DiscoveryCanaryTest.php', $fixture);
        $this->put('packages/core/src/Domain/.gitkeep', '');
        $qa = new Qa($this->temporary);
        $before = hash_file('sha256', $qa->file('composer.lock'));
        $installed = $qa->process(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], [], 180);
        self::assertSame(0, $installed['exit'], $installed['stdout'] . $installed['stderr']);
        self::assertSame($before, hash_file('sha256', $qa->file('composer.lock')));
        $result = $qa->process([PHP_BINARY, 'vendor/bin/phpunit', '--configuration', 'phpunit.xml', '--testsuite', 'Core',
            '--filter', 'DiscoveryCanaryTest', '--log-junit', $this->temporary . '/discovery.xml']);
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $discovered = Qa::junit($this->temporary . '/discovery.xml');
        self::assertCount(1, $discovered);
        self::assertSame([['id' => 'bootstrap.canary', 'status' => 'passed']], Qa::validateCases($discovered,
            ['requiredCaseIds' => ['bootstrap.canary'], 'testCaseMap' => [
                'bootstrap.canary' => ['KnowKit\\Tests\\Fixture\\DiscoveryCanaryTest::testCanary']]]));

        // Install the mandatory library as a consumer's sole production dependency.
        $consumer = $this->temporary . '/consumer';
        mkdir($consumer);
        $this->put('consumer/composer.json', json_encode(['name' => 'knowkit/bootstrap-consumer', 'license' => 'proprietary',
            'require' => ['knowkit/core' => '@dev'], 'repositories' => [['type' => 'path', 'url' => '../packages/core',
                'options' => ['symlink' => false, 'versions' => ['knowkit/core' => 'dev-main']]]],
            'config' => ['platform' => ['php' => '8.3.0'], 'allow-plugins' => false]], JSON_THROW_ON_ERROR));
        $consumerQa = new Qa($consumer);
        $result = $consumerQa->process(['composer', 'update', '--no-dev', '--no-interaction', '--no-progress']);
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame(['knowkit/core'], array_column($consumerQa->json('composer.lock')['packages'], 'name'));
        $result = $consumerQa->process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; if (class_exists("Illuminate\\\\Foundation\\\\Application") || class_exists("AgenTools\\\\Application")) exit(5); echo PHP_VERSION_ID;']);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertGreaterThanOrEqual(80300, (int) $result['stdout']);
        foreach (['python', 'routes', 'migrations', '.env'] as $unexpected) {
            self::assertFileDoesNotExist($consumer . '/' . $unexpected);
        }
    }
}
