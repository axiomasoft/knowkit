<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use KnowKit\Domain\CredentialRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Laravel\EnvironmentCredentials;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('delivery:laravel')]
final class IsolationTest extends TestCase
{
    public function testCoreLoadsInFreshProcessWithoutIlluminate(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $temporary = Qa::temporary();
        try {
            file_put_contents($temporary . '/composer.json', json_encode([
                'name' => 'knowkit/core-install-fixture',
                'require' => ['php' => '>=8.3', 'knowkit/core' => 'dev-main'],
                'repositories' => [[
                    'type' => 'path',
                    'url' => dirname(__DIR__, 3) . '/packages/core',
                    'options' => ['symlink' => false, 'versions' => ['knowkit/core' => 'dev-main']],
                ]],
                'config' => ['allow-plugins' => false],
            ], JSON_THROW_ON_ERROR));
            $install = $qa->process(
                argv: ['composer', 'install', '--working-dir=' . $temporary, '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins'],
                env: ['COMPOSER_DISABLE_NETWORK' => '1'],
            );
            self::assertSame(0, $install['exit'], $install['stderr']);
            $result = $qa->process([PHP_BINARY, __DIR__ . '/fixtures/v1/core-only.php', $temporary . '/vendor/autoload.php']);
            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertSame('core-only', $result['stdout']);
            $installed = json_decode(file_get_contents($temporary . '/vendor/composer/installed.json'), true);
            self::assertSame(['knowkit/core'], array_column($installed['packages'], 'name'));
        } finally {
            Qa::remove($temporary);
        }
        $lock = $qa->json('composer.lock');
        foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
            self::assertFalse(str_starts_with($package['name'], 'illuminate/'));
            self::assertNotSame('laravel/framework', $package['name']);
        }
    }

    public function testCredentialsResolveOnlyExplicitEnvironmentReferences(): void
    {
        $name = 'KNOWKIT_P71_SYNTHETIC_SECRET';
        $before = getenv($name);
        putenv($name . '=synthetic-environment-value');
        try {
            $credentials = new EnvironmentCredentials(['fixture.key' => $name]);
            self::assertSame('synthetic-environment-value', $credentials->get(new CredentialRef('fixture.key', true)));
            self::assertStringNotContainsString('synthetic-environment-value', print_r($credentials, true));
            foreach ([new CredentialRef('fixture.missing', true), new CredentialRef('fixture.key', false)] as $reference) {
                try {
                    $credentials->get($reference);
                    self::fail('Missing credential accepted');
                } catch (DomainFailure $failure) {
                    self::assertSame(Outcome::Unavailable, $failure->outcome);
                    self::assertSame('credential_unavailable', $failure->errorCode);
                }
            }
            $this->expectException(DomainFailure::class);
            new EnvironmentCredentials(['fixture.key' => 'literal secret forbidden']);
        } finally {
            putenv($before === false ? $name : $name . '=' . $before);
        }
    }
}
