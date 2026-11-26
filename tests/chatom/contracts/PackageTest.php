<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Contracts;

use Chatom\Agents\Contracts\DurableAgentHandler;
use Chatom\Agents\Contracts\DurableAgentReply;
use Chatom\Messages\Contracts\ViewerMessageReader;
use Chatom\Messages\Data\ViewerMessageHistory;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

require_once __DIR__ . '/bootstrap.php';

#[Group('chatom:contracts')]
final class PackageTest extends TestCase
{
    public function testDependencyDagKeepsCoreVendorFreeAndBothRoutePackagesIndependent(): void
    {
        $qa = new Qa(Fixture::root());
        self::assertNotEmpty($qa->dag());
        $package = $qa->json('packages/chatom/composer.json');
        self::assertSame(expected: 'dev-main', actual: $package['require']['knowkit/core']);
        self::assertSame(expected: 'dev-main', actual: $package['require']['knowkit/answer']);
        self::assertArrayNotHasKey(key: 'knowkit/laravel', array: $package['require']);
        self::assertArrayNotHasKey(key: 'knowkit/agentools-bridge', array: $package['require']);
        $root = $qa->json('composer.json');
        self::assertArrayNotHasKey(key: 'axiomasoft/chatom', array: $root['require-dev']);
        self::assertSame(expected: hash_file('sha256', $qa->file('packages/core/src/Domain/ConversationRoute.php')), actual: hash_file('sha256', (new ReflectionClass(ConversationRoute::class))->getFileName()));
        self::assertSame(expected: ['caller_managed', 'direct_chatom', 'agentools_orchestrated'], actual: array_column(ConversationRoute::cases(), 'value'));
    }

    public function testExplicitLaravelProfileLoadsRealPinnedContractsWithoutBootingAListener(): void
    {
        $qa = new Qa(Fixture::root());
        $package = $qa->json('packages/chatom/composer.json');
        self::assertSame(expected: '>=8.4', actual: $package['require']['php']);
        self::assertSame(expected: '^13.0', actual: $package['require']['illuminate/contracts']);
        self::assertSame(expected: 'dev-main', actual: $package['require']['axiomasoft/chatom']);
        self::assertArrayNotHasKey(key: 'scripts', array: $package);
        self::assertArrayNotHasKey(key: 'files', array: $package['autoload']);
        self::assertArrayNotHasKey(key: 'laravel', array: $package['extra'] ?? []);
        $native = $qa->json('packages/chatom/vendor/axiomasoft/chatom/composer.json');
        $definition = $package['repositories'][2]['package'];
        self::assertEquals(expected: $native['require'], actual: $definition['require']);
        self::assertSame(expected: $native['autoload'], actual: $definition['autoload']);
        $lock = $qa->json('packages/chatom/composer.lock');
        $locked = array_column($lock['packages'], null, 'name');
        self::assertSame(expected: Fixture::corpus()['chatomProfile']['revision'], actual: $locked['axiomasoft/chatom']['dist']['reference']);
        self::assertStringStartsWith(prefix: 'v13.', string: $locked['laravel/framework']['version']);
        self::assertArrayNotHasKey(key: 'knowkit/laravel', array: $locked);
        $this->assertNativeContracts();
        self::assertFalse(class_exists(class: 'Chatom\\ChatomServiceProvider', autoload: false));
        self::assertFalse(class_exists(class: 'Illuminate\\Foundation\\Application', autoload: false));
        $qa->suite('Chatom');
        self::assertFileExists($qa->file('packages/chatom/vendor/bin/phpunit'));
    }

    public function testVersionedFixturesAndReuseReceiptBindInspectedSources(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/chatom-contracts.json');
        $qa->fixtures($manifest);
        self::assertSame(expected: 'chatom-routes:1', actual: Fixture::corpus()['schemaVersion']);
        self::assertSame(expected: Fixture::corpus()['requiredCaseIds'], actual: $manifest['requiredCaseIds']);
        $receipt = $qa->json('tests/chatom/contracts/evidence/P7.4-reuse.json');
        self::assertSame(expected: 'P7.4', actual: $receipt['item']);
        self::assertSame(expected: $receipt['nativeContracts']['composerSha256'], actual: hash_file('sha256', $qa->file('packages/chatom/vendor/axiomasoft/chatom/composer.json')));
        self::assertCount(expectedCount: 3, haystack: $receipt['sources']);

        foreach ($receipt['sources'] as $source) {
            self::assertSame(
                expected: $source['sha256'],
                actual: Fixture::inspectedSha256(donor: $source['donor'], relative: $source['path'], sourceUrl: $source['sourceUrl']),
            );
            self::assertSame(expected: $source['sha256'], actual: $source['observedSha256']);
            self::assertContains(needle: $source['decision'], haystack: ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
        }

        $manifest['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        $qa->fixtures($manifest);
    }

    public function testRequiredManifestRejectsMissingSkippedFailedAndZeroCases(): void
    {
        require_once __DIR__ . '/RoutesTest.php';
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/chatom-contracts.json');
        $cases = [];

        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists(object_or_class: $class, method: $name));
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }

        self::assertCount(expectedCount: 6, haystack: Qa::validateCases(cases: array_values($cases), manifest: $manifest));

        foreach (['missing', 'skipped', 'failed', 'zero'] as $mutation) {
            $changed = array_values($cases);
            if ($mutation === 'missing') {
                array_shift($changed);
            } elseif ($mutation === 'zero') {
                $changed = [];
            } else {
                $changed[0]['status'] = $mutation;
            }
            $this->assertCasesRejected(cases: $changed, manifest: $manifest);
        }
    }

    private function assertNativeContracts(): void
    {
        foreach ([DurableAgentHandler::class, DurableAgentReply::class, ViewerMessageReader::class, ViewerMessageHistory::class] as $class) {
            $reflection = new ReflectionClass($class);
            $path = $reflection->getFileName();
            self::assertStringContainsString(needle: '/packages/chatom/vendor/axiomasoft/chatom/src/', haystack: $path);
            self::assertSame(expected: Fixture::corpus()['chatomProfile']['contracts'][$class], actual: hash_file('sha256', $path));
        }

        self::assertTrue(interface_exists(DurableAgentHandler::class));
        self::assertTrue(interface_exists(DurableAgentReply::class));
        self::assertTrue(interface_exists(ViewerMessageReader::class));
        self::assertTrue(method_exists(object_or_class: ViewerMessageReader::class, method: 'history'));
    }

    private function assertCasesRejected(array $cases, array $manifest): void
    {
        try {
            Qa::validateCases(cases: $cases, manifest: $manifest);
        } catch (RuntimeException $error) {
            self::assertNotEmpty($error->getMessage());

            return;
        }

        self::fail('Required case inventory accepted missing/skipped/failed/zero cases');
    }
}
