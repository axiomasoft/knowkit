<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Http;

use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Http\Server\Contract;
use KnowKit\Http\Server\InputFailure;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TransportTest.php';
require_once __DIR__ . '/ApplicationTest.php';
require_once __DIR__ . '/SapiTest.php';

#[Group('delivery:http')]
final class FixtureTest extends TestCase
{
    public function testFixtureDigestsProvenanceAndRequiredScenariosBindCandidate(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-http.json');
        $qa->fixtures($manifest);
        self::assertSame('1.0.0', $manifest['fixtureVersion']);
        foreach (['UNAUTHENTICATED', 'TENANT-SPOOF', 'IDEMPOTENCY-REPLAY', 'EPOCH-CONFLICT', 'SCHEMA-MISMATCH',
            'OVERSIZED-UPLOAD', 'REVOKED-PREVIEW', 'UNKNOWN-POLICY', 'R18-A01', 'R18-A02', 'R35-A01', 'R35-A02'] as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']);
        }
        $receipt = $qa->json('tests/delivery/http/evidence/P7.2-reuse.json');
        self::assertCount(3, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
        }
        $lines = '';
        foreach ($receipt['candidateDigest']['paths'] as $path) {
            self::assertFileExists(Fixture::root() . '/' . $path);
            $lines .= $path . "\0" . hash_file('sha256', Fixture::root() . '/' . $path) . "\n";
        }
        self::assertSame($receipt['candidateDigest']['sha256'], hash('sha256', $lines));
        self::assertSame(['P7.2-reuse.json'], array_map('basename', glob(__DIR__ . '/evidence/*')));
    }

    public function testOpenApiRuntimeArtifactAndEnvelopeSchemasConform(): void
    {
        self::assertSame(file_get_contents(Fixture::root() . '/specs/http/openapi.yaml'),
            file_get_contents(Fixture::root() . '/packages/http/resources/openapi.json'));
        $contract = new Contract();
        self::assertSame('3.1.0', $contract->document->get('openapi'));
        $server = (new Fixture())->server();
        foreach (Fixture::cases()['routes'] as $case) {
            $response = $server->handle(Fixture::request($case['path'], $case['body'], $case['headers'], $case['method']));
            $contract->validate(schema: 'Envelope', body: StrictJson::decode($response->body));
            self::assertSame($case['status'], $response->status);
        }
        foreach ([
            '{"outcome":"denied","data":{"text":"forbidden"},"error":{"code":"denied","retryable":false},"degraded":false,"warnings":[]}',
            '{"outcome":"empty","data":{},"degraded":false,"warnings":[]}',
            '{"outcome":"unknown","degraded":false,"warnings":[]}',
        ] as $invalid) {
            try {
                $contract->validate(schema: 'Envelope', body: StrictJson::decode($invalid));
                self::fail('Invalid envelope accepted');
            } catch (InputFailure) {
                self::assertTrue(true);
            }
        }
    }

    public function testRequiredCaseRunnerRejectsMissingSkippedFailedZeroAndUnknown(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-http.json');
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(array_values($cases), $manifest));
        $required = ['requiredCaseIds' => ['required'], 'testCaseMap' => ['required' => ['Example::testRequired']]];
        foreach ([[], [['id' => 'Example::testOther', 'status' => 'passed']],
            [['id' => 'Example::testRequired', 'status' => 'skipped']],
            [['id' => 'Example::testRequired', 'status' => 'failed']]] as $invalid) {
            $this->rejects(static fn () => Qa::validateCases($invalid, $required));
        }
        $this->rejects(static fn () => $qa->select(['unknown:http'], $qa->registry()));
        $this->rejects(static fn () => $qa->file('tests/delivery/http/MissingTest.php'));
        self::assertSame(9, $qa->process([PHP_BINARY, '-r', 'exit(9);'])['exit']);
        $qa->suite('Http');
    }

    public function testPackageLoadsInFreshProcessWithoutFrameworkOrInstallationSideEffects(): void
    {
        $qa = new Qa(Fixture::root());
        $result = $qa->process([PHP_BINARY, '-r', <<<'PHP'
            require 'packages/http/vendor/autoload.php';
            $contract = new KnowKit\Http\Server\Contract();
            if (count($contract->routes()) !== 29 || class_exists('Illuminate\Support\ServiceProvider')) {
                exit(8);
            }
            foreach (get_included_files() as $path) {
                if (str_contains($path, '/laravel/') || str_contains($path, '/agentools/')) {
                    exit(7);
                }
            }
            PHP]);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        $composer = $qa->json('packages/http/composer.json');
        self::assertArrayNotHasKey('scripts', $composer);
        self::assertArrayNotHasKey('files', $composer['autoload']);
        self::assertArrayNotHasKey('laravel', $composer['extra'] ?? []);
        self::assertSame('>=8.3', $composer['require']['php']);
        self::assertSame(['php', 'knowkit/core'], array_keys($composer['require']));
        self::assertArrayNotHasKey('knowkit/http', $qa->json('composer.json')['require-dev']);
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            self::fail('Required-case violation accepted');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
    }
}
