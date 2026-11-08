<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Laravel;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\ServiceProvider;
use KnowKit\Domain as D;
use KnowKit\Laravel\Jobs\Jobs;
use KnowKit\Laravel\KnowKitServiceProvider;
use KnowKit\Port\Clock;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Registry\Factory;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:laravel')]
final class ProviderTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = Qa::temporary();
        Routes::$calls = 0;
        Artisan::forgetBootstrappers();
    }

    protected function tearDown(): void
    {
        Artisan::forgetBootstrappers();
        Qa::remove($this->temporary);
    }

    public function testPackageDiscoveryAndManualBootHaveNoInstallEffects(): void
    {
        $files = new Filesystem();
        $files->makeDirectory(path: $this->temporary . '/vendor/composer', mode: 0755, recursive: true);
        $package = json_decode(file_get_contents(dirname(__DIR__, 3) . '/packages/laravel/composer.json'), true);
        file_put_contents($this->temporary . '/vendor/composer/installed.json', json_encode(['packages' => [$package]]));
        $manifest = new PackageManifest(
            files: $files,
            basePath: $this->temporary,
            manifestPath: $this->temporary . '/packages.php',
        );
        $manifest->build();
        self::assertSame([], $manifest->providers());
        self::assertArrayNotHasKey('scripts', $package);
        self::assertArrayNotHasKey('files', $package['autoload']);

        $app = Fixture::app($this->temporary);
        foreach ([Dispatcher::class, \PDO::class, \KnowKit\Laravel\HostApplication::class] as $service) {
            $app->bind($service, static fn () => throw new \LogicException('Unexpected side effect'));
        }
        Fixture::boot($app);
        self::assertCount(0, $app['router']->getRoutes());
        self::assertSame(0, Routes::$calls);
        self::assertSame([], ServiceProvider::pathsToPublish(group: 'knowkit-migrations'));
        self::assertDirectoryDoesNotExist($this->temporary . '/database');
        $artisan = new Artisan(laravel: $app, events: $app['events'], version: 'test');
        self::assertArrayNotHasKey('knowkit', $artisan->all());
        $this->assertFailure(fn () => $app->make(Jobs::class), 'jobs_disabled');
    }

    public function testRoutesMigrationsAndCommandsRequireIndependentOptIns(): void
    {
        $app = Fixture::app($this->temporary, [
            'http' => ['enabled' => true, 'registrar' => Routes::class],
            'migrations' => ['publish' => true],
            'commands' => ['enabled' => true],
        ]);
        Fixture::boot($app);
        self::assertCount(1, $app['router']->getRoutes());
        self::assertSame(1, Routes::$calls);
        self::assertDirectoryDoesNotExist($this->temporary . '/database');
        $paths = ServiceProvider::pathsToPublish(group: 'knowkit-migrations');
        self::assertCount(1, $paths);
        $source = array_key_first($paths);
        self::assertFileExists($source . '/001_metadata.sql');
        $publisher = new VendorPublishCommand(new Filesystem());
        $publisher->setLaravel($app);
        $tester = new CommandTester($publisher);
        self::assertSame(0, $tester->execute(['--tag' => ['knowkit-migrations'], '--force' => true]));
        foreach (glob($source . '/*.sql') as $file) {
            self::assertSame(hash_file('sha256', $file), hash_file('sha256', $paths[$source] . '/' . basename($file)));
        }
        $artisan = new Artisan(laravel: $app, events: $app['events'], version: 'test');
        self::assertArrayHasKey('knowkit', $artisan->all());
        $this->assertFailure(fn () => $app->make(Jobs::class), 'jobs_disabled');
    }

    public function testUnknownAdapterAndUnsupportedCapabilitiesFailBeforeFactory(): void
    {
        $factory = $this->createMock(Factory::class);
        $factory->expects(self::never())->method('create');
        $vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/v1/invalid-profiles.json'));
        foreach ($vectors as $vector) {
            $app = Fixture::app($this->temporary, ['profile' => json_encode($vector->profile)]);
            $app->instance(AdapterRegistry::class, Fixture::registry($factory));
            $this->assertFailure(fn () => Fixture::boot($app), $vector->expected);
        }
    }

    public function testValidExplicitAdapterBindsLazilyAndOnlyOnce(): void
    {
        $clock = $this->createMock(Clock::class);
        $factory = $this->createMock(Factory::class);
        $factory->expects(self::once())->method('create')->willReturn($clock);
        $profile = file_get_contents(__DIR__ . '/fixtures/v1/profile.json');
        $app = Fixture::app($this->temporary, ['profile' => $profile]);
        $app->instance(AdapterRegistry::class, Fixture::registry($factory));
        Fixture::boot($app);
        self::assertTrue($app->bound(Clock::class));
        self::assertSame($clock, $app->make(Clock::class));
        self::assertSame($clock, $app->make(Clock::class));
    }

    public function testInvalidOptInFlagsAndMissingRegistrarFailClosed(): void
    {
        foreach ([['http' => ['enabled' => true]], ['jobs' => ['enabled' => 'false']]] as $config) {
            $app = Fixture::app($this->temporary, $config);
            $code = isset($config['jobs']) ? 'invalid_enable_flag' : 'http_registrar_required';
            $this->assertFailure(fn () => Fixture::boot($app), $code);
        }
    }

    public function testEachCapabilityCanBeEnabledAlone(): void
    {
        $configurations = [
            ['http' => ['enabled' => true, 'registrar' => Routes::class]],
            ['migrations' => ['publish' => true]],
            ['commands' => ['enabled' => true]],
        ];
        foreach ($configurations as $config) {
            Artisan::forgetBootstrappers();
            $app = Fixture::app($this->temporary, $config);
            Fixture::boot($app);
            self::assertCount(isset($config['http']) ? 1 : 0, $app['router']->getRoutes());
            self::assertCount(isset($config['migrations']) ? 1 : 0, ServiceProvider::pathsToPublish(group: 'knowkit-migrations'));
            $artisan = new Artisan(laravel: $app, events: $app['events'], version: 'test');
            self::assertSame(isset($config['commands']), array_key_exists('knowkit', $artisan->all()));
            self::assertDirectoryDoesNotExist($this->temporary . '/database');
        }
    }

    private function assertFailure(callable $operation, string $code): void
    {
        try {
            $operation();
            self::fail('Invalid configuration accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            self::assertSame($code, $failure->errorCode);
        }
    }
}
