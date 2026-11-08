<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Client;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Conformance\Http\CorpusTest;
use KnowKit\Tests\Conformance\Http\Remote;
use KnowKit\Tests\Delivery\Http\Fixture as F;
use KnowKit\Tooling\Qa;
use KnowKitExampleHttp\SearchAndDisclose;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientTest.php';
require_once __DIR__ . '/StreamTransportTest.php';
require_once dirname(__DIR__, 2) . '/conformance/http/CorpusTest.php';
require_once dirname(__DIR__, 3) . '/examples/http-client/SearchAndDisclose.php';

#[Group('delivery:client')]
final class FixtureTest extends TestCase
{
    public function testIndependentLibraryAndCliCreateIngestSearchResolveAndRevokeWithoutOptionalServices(): void
    {
        $qa = new Qa(F::root());
        $result = $qa->process([PHP_BINARY, 'tests/conformance/http/independent.php']);
        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        $wire = StrictJson::decode($result['stdout']);
        self::assertSame('success', $wire->get('outcome'));
        self::assertSame('denied', $wire->get('afterRevoke')->get('outcome'));
        self::assertFalse($wire->get('afterRevoke')->has('data'));
        self::assertSame(hash_file('sha256', F::root() . '/examples/plain-php/fixtures/v1/support.txt'), $wire->get('sourceDigest'));
        self::assertSame($wire->get('sourceDigest'), $wire->get('citation')->get('sourceDigest'));
        $commands = array_map(static fn ($step): string => $step->get('command'), $wire->get('steps'));
        foreach (['kb create', 'source upload', 'ingest start', 'publication activate', 'search', 'disclose', 'read source', 'lifecycle revoke'] as $command) {
            self::assertContains($command, $commands);
        }
    }

    public function testExampleUsesTypedRemoteClientAndPreservesImmutableCitation(): void
    {
        $remote = new Remote('reading');
        try {
            $ids = new F();
            $example = new SearchAndDisclose(client: $remote->client, validator: F::validator(), ids: $ids);
            $before = $remote->control('stats')->get('usages');
            $result = $example->execute(new D\Query('DOC-2026/0042'));
            self::assertSame(D\Outcome::Success, $result->outcome);
            $evidence = $result->evidence(F::validator());
            self::assertSame('untrusted', $evidence->trustClass());
            self::assertNull($evidence->checkedAt());
            $citation = new D\CitationRef(value: $evidence->toWire()->get('fragments')[0]->get('citation'), validator: F::validator());
            $resolved = $remote->client->resolveCitation(citationId: $citation->id(), requestId: $ids->next());
            self::assertEquals($citation, $resolved->citation(F::validator()));
            self::assertSame($before + 2, $remote->control('stats')->get('usages'));
            $remote->control('revoke');
            $denied = $remote->client->resolveCitation(citationId: $citation->id(), requestId: $ids->next());
            self::assertSame(D\Outcome::Denied, $denied->outcome);
            self::assertNull($denied->data());
            self::assertSame($before + 2, $remote->control('stats')->get('usages'));
        } finally {
            $remote->close();
        }
    }

    public function testVersionedFixtureDigestsRightsAndReuseBindTheCandidate(): void
    {
        $qa = new Qa(F::root());
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-client.json');
        $qa->fixtures($manifest);
        self::assertSame('1.0.0', $manifest['fixtureVersion']);
        self::assertSame('http-client-conformance:1', CorpusTest::corpus()->get('schemaVersion'));
        self::assertCount(6, CorpusTest::corpus()->get('outcomes'));
        $receipt = $qa->json('tests/delivery/client/evidence/P7.3-reuse.json');
        self::assertCount(3, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertSame('adapt', $source['decision']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
        }
        $lines = '';
        foreach ($receipt['candidateDigest']['paths'] as $path) {
            $lines .= $path . "\0" . hash_file('sha256', $qa->file($path)) . "\n";
        }
        self::assertSame($receipt['candidateDigest']['sha256'], hash('sha256', $lines));
        self::assertSame(['P7.3-reuse.json'], array_map('basename', glob(__DIR__ . '/evidence/*')));
    }

    public function testRequiredCaseRunnerRejectsMissingSkippedFailedZeroUnknownAndNestedFailure(): void
    {
        $qa = new Qa(F::root());
        $manifest = $qa->json('tests/fixtures/check-groups/delivery-client.json');
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(array_values($cases), $manifest));
        $required = ['requiredCaseIds' => ['R01-A01'], 'testCaseMap' => ['R01-A01' => ['Example::testRequired']]];
        foreach ([[], [['id' => 'Example::testOther', 'status' => 'passed']],
            [['id' => 'Example::testRequired', 'status' => 'skipped']],
            [['id' => 'Example::testRequired', 'status' => 'failed']]] as $invalid) {
            $this->rejects(static fn () => Qa::validateCases(cases: $invalid, manifest: $required));
        }
        $this->rejects(static fn () => $qa->select(args: ['unknown:client'], registry: $qa->registry()));
        $this->rejects(static fn () => $qa->file('tests/delivery/client/MissingTest.php'));
        self::assertSame(9, $qa->process([PHP_BINARY, '-r', 'exit(9);'])['exit']);
        $qa->suite('Http');
    }

    private function rejects(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Invalid required-case execution accepted');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
    }
}
