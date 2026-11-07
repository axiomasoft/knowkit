<?php
declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;

#[Group('tooling:baseline')]
final class BoundaryTest extends Support
{
    public function testRejectsForbiddenCoreImportsAndInstallHooks(): void
    {
        $qa = $this->candidate();
        $this->put('packages/core/src/Domain/Allowed.php', '<?php namespace KnowKit\\Domain; final class Allowed {}');
        self::assertSame(1, $qa->dag()['phpFiles']);
        foreach (['Illuminate\\Support\\Collection', 'Laravel\\App', 'Chatom\\Chat', 'AgenTools\\Agent',
            'AgenToolsPlatform\\Chatom\\Chat', 'Python\\Worker', 'Psr\\Http\\Message\\RequestInterface',
            'GuzzleHttp\\Client', 'KnowKit\\Answer\\Answer', 'KnowKit\\Application\\UseCase', 'KnowKit\\Adapter\\Postgres\\Store'] as $forbidden) {
            $this->put('packages/core/src/Domain/Allowed.php', '<?php namespace KnowKit\\Domain; use ' . $forbidden . '; final class Allowed {}');
            $this->rejects(static fn() => $qa->dag(), 'forbidden');
        }
        $this->put('packages/core/src/Domain/Allowed.php', '<?php namespace KnowKit\\Domain; final class Allowed {}');
        $manifest = $qa->json('packages/core/composer.json');
        foreach ([['scripts' => ['post-install-cmd' => 'php install.php']], ['autoload' => ['files' => ['boot.php']]],
            ['extra' => ['laravel' => ['providers' => ['Autodiscover']]]]] as $mutation) {
            $this->put('packages/core/composer.json', json_encode(array_replace_recursive($manifest, $mutation), JSON_THROW_ON_ERROR));
            $this->rejects(static fn() => $qa->dag(), 'side effects');
        }
    }

    public function testActualCoreGraphAndRegistryDoNotRequireOptionalInstalls(): void
    {
        $result = $this->candidate()->dag();
        self::assertSame(1, $result['packages']);
        $root = $this->qa->json('composer.json');
        self::assertArrayNotHasKey('require', $root);
        $lock = $this->qa->json('composer.lock');
        self::assertSame([], $lock['packages']);
        $core = $this->qa->json('packages/core/composer.json');
        self::assertSame('>=8.3', $core['require']['php']);
        self::assertSame(['php', 'ext-pdo', 'ext-json', 'ext-mbstring'], array_keys($core['require']));
        foreach ($this->qa->registry()['groups'] as $group) {
            self::assertArrayHasKey('composerProject', $group);
            self::assertArrayHasKey('minPhp', $group);
            if (str_starts_with($group['id'], 'chatom:') || str_starts_with($group['id'], 'bridge')) {
                self::assertSame('8.4', $group['minPhp']);
                self::assertNotSame('.', $group['composerProject']);
            }
            if (str_starts_with($group['id'], 'worker:') && $group['id'] !== 'worker:acceptance') {
                self::assertSame(['uv', 'run', '--project', 'python/knowkit-worker', '--frozen', 'pytest'], array_slice($group['commands'][1], 0, 6));
                self::assertContains('--strict-markers', $group['commands'][1]);
            }
        }
    }

    public function testNamespaceAliasesPreserveVendorAndLayerChecks(): void
    {
        $qa = $this->candidate();
        $this->put('packages/core/src/Application/Allowed.php', '<?php namespace KnowKit\\Application; use KnowKit\\Domain as D; final class Allowed { public function run(d\\Lease $lease): void {} }');
        self::assertSame(1, $qa->dag()['phpFiles']);
        foreach ([
            'use Vendor as D; final class Allowed { public function run(D\\Lease $lease): void {} }',
            'use KnowKit as K; final class Allowed { public function run(K\\Adapter\\Store $store): void {} }',
            'use KnowKit\\Domain as D; final class Allowed { public function run(\\D\\Lease $lease): void {} }',
            '/* use KnowKit\\Domain as D; */ final class Allowed { public function run(D\\Lease $lease): void {} }'
        ] as $body) {
            $this->put('packages/core/src/Application/Allowed.php', '<?php namespace KnowKit\\Application; '.$body);
            $this->rejects(static fn() => $qa->dag(), 'forbidden');
        }
    }

    public function testCoreDependencyFloorAndPackageDagMutationsAreRejected(): void
    {
        $qa = $this->candidate();
        $manifest = $qa->json('packages/core/composer.json');
        foreach (['illuminate/support', 'knowkit/answer'] as $dependency) {
            $changed = $manifest;
            $changed['require'][$dependency] = '*';
            $this->put('packages/core/composer.json', json_encode($changed, JSON_THROW_ON_ERROR));
            $this->rejects(static fn() => $qa->dag(), 'core vendor runtime dependency');
        }
        $changed = $manifest;
        $changed['require']['php'] = '>=8.4';
        $this->put('packages/core/composer.json', json_encode($changed, JSON_THROW_ON_ERROR));
        $this->rejects(static fn() => $qa->dag(), 'PHP floor');
        $this->put('packages/core/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->put('packages/postgres/composer.json', '{}');
        $this->rejects(static fn() => $qa->dag(), 'unapproved Composer package');
    }

    public function testPortableConfigurationRejectsHostPathsAndPublicDatabasePorts(): void
    {
        self::assertSame(['files' => 6], $this->qa->config());
        foreach (['vendor/autoload.php', 'tools/qa-groups.json', '.github/workflows/ci.yml', 'compose.yaml', '.env.example',
            'composer.json', 'packages/core/composer.json'] as $path) {
            if ($path === 'vendor/autoload.php') {
                $this->put($path, '<?php require ' . var_export($this->qa->root . '/vendor/autoload.php', true) . ';');
            } else {
                $this->copy($path);
            }
        }
        $qa = new Qa($this->temporary);
        $this->put('.env.example', 'CACHE=/home/example/private');
        $this->rejects(static fn() => $qa->config(), 'nonportable');
        $this->copy('.env.example');
        $this->put('compose.yaml', str_replace('127.0.0.1:', '0.0.0.0:', file_get_contents($this->qa->file('compose.yaml'))));
        $this->rejects(static fn() => $qa->config(), 'loopback');
    }
}
