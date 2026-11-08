<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Security\Hardening\HttpHost as Fixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/HttpHost.php';

#[Group('security:hardening')]
final class AuditTest extends HardeningCase
{
    public function testR35A01DurableReplayConflictingPayloadAndStaleEpoch(): void
    {
        CatalogFixture::run(function (CatalogFixture $fixture): void {
            $fixture->tenant();
            $kb = $fixture->kb();
            $scope = $fixture->scope();
            $command = new D\CreateKB(kbId: $scope->kbId, name: $kb->name, policyRef: $kb->policyRef,
                defaultRetrievalProfile: $kb->defaultRetrievalProfile, coveragePolicy: $kb->coveragePolicy);
            self::assertEquals($kb, $fixture->commands->createKnowledgeBase(scope: $scope, command: $command, context: $fixture->context()));
            $changed = new D\CreateKB(kbId: $scope->kbId, name: 'changed', policyRef: $kb->policyRef,
                defaultRetrievalProfile: $kb->defaultRetrievalProfile, coveragePolicy: $kb->coveragePolicy);
            $this->failure(fn () => $fixture->commands->createKnowledgeBase(scope: $scope, command: $changed, context: $fixture->context()),
                code: 'idempotency_conflict', outcome: D\Outcome::Incompatible);
            $this->failure(fn () => $fixture->commands->createKnowledgeBase(scope: $scope, command: $command,
                context: $fixture->context(key: 'create-kb', epoch: '1')), code: 'idempotency_conflict', outcome: D\Outcome::Incompatible);
            $collection = new D\CreateCollection(collectionId: new D\CollectionId(CatalogFixture::id(200)), parentId: null, metadata: new D\CatalogMetadata());
            $this->failure(fn () => $fixture->commands->createCollection(scope: $scope, command: $collection,
                context: $fixture->context(key: 'stale', revision: '0', epoch: '1')), code: 'POLICY_CHANGED');
            self::assertSame(1, $fixture->count('idempotency_receipts'));
            self::assertSame(0, $fixture->count('collections'));
        });
    }

    public function testR35A02UnsafePathsAndRawFiltersStopBeforePrivilegedWork(): void
    {
        $fixture = new Fixture();
        $server = $fixture->server();
        foreach ([['sql' => 'SELECT * FROM secrets'], ['where' => 'true'], ['sourceVersionIds' => ["x' OR 1=1"]]] as $filters) {
            self::assertSame(400, $server->handle(Fixture::request(path: '/v1/search', body: ['query' => 'safe', 'filters' => $filters]))->status);
        }
        foreach (['/v1/citations/../../private', '/v1/citations/%2e%2e', '/v1/search?sql=SELECT'] as $path) {
            self::assertContains($server->handle(Fixture::request($path))->status, [400, 404]);
        }
        foreach ([['query' => str_repeat('я', 2049)], ['query' => 'safe', 'limit' => 101]] as $body) {
            self::assertSame(400, $server->handle(Fixture::request(path: '/v1/search', body: $body))->status);
        }
        self::assertSame([], $fixture->seen);
    }

    public function testMetadataUtf8BytesBoundedIndependentlyOfKeyAndCharacterCount(): void
    {
        $members = [];
        for ($index = 0; $index < 32; $index++) {
            $members['test.key' . $index] = str_repeat('界', 256);
        }
        try {
            new D\CatalogMetadata(new JsonObject($members));
            self::fail('Metadata byte ceiling bypassed');
        } catch (\InvalidArgumentException) {
            self::assertCount(8, (new D\CatalogMetadata(new JsonObject(array_slice($members, 0, 8))))->toWire());
        }
    }

    public function testPromptInjectionStaysLiteralUntrustedEvidenceAndCannotPromote(): void
    {
        $text = file_get_contents(__DIR__ . '/fixtures/v1/prompt-injection.txt');
        $base = PortFixture::json('tests/fixtures/contracts/v1/valid/evidence-set.valid.json');
        $fragment = $base->get('fragments')[0];
        $citation = $fragment->get('citation')->with('spans', [new JsonObject([
            'kind' => 'page', 'pageId' => 'p1', 'physicalNo' => 1, 'printedLabel' => null,
            'blockId' => 'b1', 'byteStart' => 0, 'byteEnd' => strlen($text), 'textDigest' => hash('sha256', $text),
        ])])->with('contentDigest', hash('sha256', $text))->with('sourceDigest', hash('sha256', $text));
        $fragment = $fragment->with('citation', $citation)->with('text', $text)->with('joiner', null)
            ->with('fragmentDigest', hash('sha256', $text));
        $wire = $base->with('fragments', [$fragment])->with('totalBytes', strlen($text))->without('evidenceSetDigest');
        $wire = $wire->with('evidenceSetDigest', HashDomain::EvidenceSet->digest($wire));
        $evidence = new D\EvidenceSet($wire, PortFixture::validator());
        self::assertSame($text, $evidence->toWire()->get('fragments')[0]->get('text'));
        self::assertSame('untrusted', $evidence->trustClass());
        self::assertFalse($evidence->memoryActivation());
        self::assertEquals($citation, $evidence->toWire()->get('fragments')[0]->get('citation'));
        self::assertStringContainsString('ignore', $evidence->searchRepresentations()[0]->toWire()->get('normalizedText'));
        try {
            new D\EvidenceSet($wire->with('trustClass', 'trusted'), PortFixture::validator());
            self::fail('Evidence self-promotion accepted');
        } catch (ContractViolation) {
            self::assertSame('untrusted', $evidence->trustClass());
        }
    }
}
