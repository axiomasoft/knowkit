<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Readiness;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:readiness')]
final class InstallTest extends TestCase
{
    public function testCoreOnlyCleanInstallAndReproducibleArtifact(): void
    {
        $temporary = Qa::temporary();
        try {
            $this->assemblePair($temporary);
            $artifact = $temporary . '/one';
            $this->assertInventory($artifact);
            $this->installCore($artifact);
            Qa::remove($artifact . '/packages/core/src');
            $this->assertInstalledJourney($artifact);
        } finally {
            Qa::remove($temporary);
        }
    }

    private function assemblePair(string $temporary): void
    {
        foreach (['one', 'two'] as $name) {
            $result = Fixture::process(['python3', 'deploy/assemble.py', $temporary . '/' . $name]);
            self::assertSame(0, $result['exit'], $result['stderr']);
        }
        self::assertSame(file_get_contents($temporary . '/one/artifact.json'), file_get_contents($temporary . '/two/artifact.json'));
    }

    private function assertInventory(string $artifact): void
    {
        $verified = Fixture::process(['python3', 'deploy/verify.py', $artifact]);
        self::assertSame(0, $verified['exit'], $verified['stderr']);
        $sbom = json_decode(file_get_contents($artifact . '/sbom.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('passed', $sbom['status']);
        self::assertFalse($sbom['publicationAllowed']);
        $notices = file_get_contents($artifact . '/THIRD_PARTY_NOTICES.txt');
        foreach ($sbom['components'] as $component) {
            self::assertStringContainsString($component['id'] . '@' . $component['sourceRevision'], $notices);
        }
        self::assertFileExists($artifact . '/docs/operations/backup.md');
        self::assertFileExists($artifact . '/docs/operations/purge.md');
    }

    private function installCore(string $artifact): void
    {
        file_put_contents($artifact . '/composer.json', json_encode([
            'name' => 'knowkit/local-acceptance', 'require' => ['knowkit/core' => 'dev-main'],
            'repositories' => [['type' => 'path', 'url' => 'packages/core',
                'options' => ['symlink' => false, 'versions' => ['knowkit/core' => 'dev-main']]],
                ['packagist.org' => false]],
            'config' => ['allow-plugins' => false],
        ], JSON_THROW_ON_ERROR));
        $install = Fixture::process(
            argv: ['composer', 'install', '--working-dir=' . $artifact, '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins'],
            env: ['COMPOSER_DISABLE_NETWORK' => '1'],
        );
        self::assertSame(0, $install['exit'], $install['stderr']);
        $installed = json_decode(file_get_contents($artifact . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['knowkit/core'], array_column($installed['packages'], 'name'));
    }

    private function assertInstalledJourney(string $artifact): void
    {
        $run = Fixture::process(argv: [PHP_BINARY, 'examples/acceptance/installed.php', $artifact], env: ['PATH' => '/nonexistent']);
        self::assertSame(0, $run['exit'], $run['stderr']);
        $wire = json_decode($run['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('success', $wire['outcome']);
        self::assertSame('denied', $wire['afterRevoke']['outcome']);
        self::assertSame(Fixture::manifest()['expected']['textDigest'], $wire['sourceDigest']);
        self::assertSame($wire['sourceDigest'], $wire['citation']['sourceDigest']);
        self::assertNotEmpty($wire['citation']['spans']);
        $binary = Fixture::process([PHP_BINARY, $artifact . '/vendor/bin/knowkit', 'misspelled', '--json']);
        self::assertSame(2, $binary['exit']);
        self::assertStringNotContainsString($artifact, $binary['stdout']);
    }

    public function testAlteredMissingAndUnlistedArtifactBytesFailVerification(): void
    {
        $temporary = Qa::temporary();
        try {
            file_put_contents($temporary . '/payload', 'original');
            file_put_contents($temporary . '/artifact.json', json_encode([
                'schemaVersion' => 'knowkit-local-artifact:1',
                'files' => ['payload' => hash('sha256', 'original')],
            ], JSON_THROW_ON_ERROR));
            self::assertSame(0, Fixture::process(['python3', 'deploy/verify.py', $temporary])['exit']);
            file_put_contents($temporary . '/payload', 'changed');
            self::assertSame(1, Fixture::process(['python3', 'deploy/verify.py', $temporary])['exit']);
            unlink($temporary . '/payload');
            self::assertSame(1, Fixture::process(['python3', 'deploy/verify.py', $temporary])['exit']);
            file_put_contents($temporary . '/payload', 'original');
            file_put_contents($temporary . '/unlisted', 'unexpected');
            self::assertSame(1, Fixture::process(['python3', 'deploy/verify.py', $temporary])['exit']);
            self::assertSame(1, Fixture::process(['python3', 'deploy/assemble.py', $temporary])['exit']);
        } finally {
            Qa::remove($temporary);
        }
    }

    public function testCoreHasNoOptionalImportsOrStartupHooks(): void
    {
        $package = Fixture::json('packages/core/composer.json');
        self::assertSame(['php', 'ext-pdo', 'ext-json', 'ext-mbstring'], array_keys($package['require']));
        self::assertArrayNotHasKey('scripts', $package);
        self::assertArrayNotHasKey('files', $package['autoload']);
        self::assertArrayNotHasKey('extra', $package);
        $root = Fixture::root() . '/packages/core/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                self::assertDoesNotMatchRegularExpression('/(?:use|new|extends|implements)\s+\\\\?(?:Illuminate|Laravel|AgenTools|Agentools|KnowKit\\\\(?:Answer|Http|Laravel|PgVector|Bridge))\\\\/i', file_get_contents($file->getPathname()));
            }
        }
        self::assertSame(['selection' => 'NOT_SELECTED', 'qualification' => 'PENDING'],
            Fixture::json('deploy/profiles/delivery.v1.json')['agentoolsBridge']);
    }
}
