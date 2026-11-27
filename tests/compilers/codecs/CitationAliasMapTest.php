<?php
declare(strict_types=1);

namespace KnowKit\Tests\Compilers\Codecs;

use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\EvidenceSet;
use KnowKit\Domain\Ulid;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('compilers:codecs')]
final class CitationAliasMapTest extends TestCase
{
    private function permissiveEvidenceValidator(): ContractValidator
    {
        $schemaId = 'urn:knowkit:schema:evidence-set:1.0';

        return new ContractValidator(new SchemaSet([
            $schemaId => new JsonObject([
                '$schema' => SchemaSet::DIALECT,
                '$id' => $schemaId,
                'type' => 'object',
            ]),
        ]));
    }

    private function citationWire(string $citationId, string $documentId, string $textDigest, int $start, int $end): JsonObject
    {
        $tenantId = '01JSC420000000000000000000';
        $sourceId = '01JSC420000000000000000001';
        $versionId = '01JSC420000000000000000002';
        $revisionId = '01JSC420000000000000000003';
        $sourceDigest = str_repeat('a', 64);
        $irDigest = str_repeat('b', 64);

        return new JsonObject([
            'schemaVersion' => 'citation-ref:1',
            'citationId' => $citationId,
            'tenantId' => $tenantId,
            'sourceId' => $sourceId,
            'sourceVersionId' => $versionId,
            'sourceDigest' => $sourceDigest,
            'documentId' => $documentId,
            'documentRevisionId' => $revisionId,
            'irDigest' => $irDigest,
            'contentDigest' => $textDigest,
            'spans' => [
                new JsonObject([
                    'kind' => 'segment',
                    'segmentId' => 'seg-1',
                    'blockId' => 'blk-1',
                    'byteStart' => $start,
                    'byteEnd' => $end,
                    'textDigest' => $textDigest,
                ]),
            ],
        ]);
    }

    /** @param list<JsonObject> $citations */
    private function makeEvidenceSet(array $citations): EvidenceSet
    {
        $fragments = [];
        $totalBytes = 0;
        foreach ($citations as $index => $citation) {
            $text = 'fragment-' . ($index + 1);
            $totalBytes += strlen($text);
            $fragments[] = new JsonObject([
                'fragmentDigest' => hash('sha256', $text),
                'text' => $text,
                'citation' => $citation,
            ]);
        }

        $base = new JsonObject([
            'schemaVersion' => 'evidence-set:1',
            'usageId' => '01JSC420000000000000000010',
            'tenantId' => '01JSC420000000000000000000',
            'selectionId' => '01JSC420000000000000000011',
            'disclosureAttemptId' => '01JSC420000000000000000012',
            'hostScopeHash' => str_repeat('d', 64),
            'snapshotRef' => new JsonObject([
                'tenantId' => '01JSC420000000000000000000',
                'snapshotId' => '01JSC420000000000000000013',
            ]),
            'totalBytes' => $totalBytes,
            'fragments' => $fragments,
        ]);

        $wire = $base->with('evidenceSetDigest', HashDomain::EvidenceSet->digest($base));

        return new EvidenceSet($wire, $this->permissiveEvidenceValidator());
    }

    public function testAssignsDeterministicBijectiveAliasesAndPreservesExactCitationRef(): void
    {
        $c1Id = '01JSC420000000000000000101';
        $c2Id = '01JSC420000000000000000102';
        $d1Id = '01JSC420000000000000000201';
        $d2Id = '01JSC420000000000000000202';
        $digest1 = hash('sha256', 'fragment-1');
        $digest2 = hash('sha256', 'fragment-2');

        $evidence = $this->makeEvidenceSet([
            $this->citationWire($c1Id, $d1Id, $digest1, 0, 10),
            $this->citationWire($c2Id, $d2Id, $digest2, 10, 25),
        ]);

        $map = CitationAliasMap::fromEvidenceSet($evidence);
        $mapAgain = CitationAliasMap::fromEvidenceSet($evidence);

        self::assertSame('#1', $map->aliasFor(new Ulid($c1Id)));
        self::assertSame('#2', $map->aliasFor(new Ulid($c2Id)));
        self::assertNull($map->aliasFor(new Ulid('01JSC420000000000000000999')));

        $resolved1 = $map->resolve('#1');
        $resolved2 = $map->resolve('#2');
        self::assertNotNull($resolved1);
        self::assertNotNull($resolved2);
        self::assertSame($c1Id, $resolved1->id()->value);
        self::assertSame($d1Id, $resolved1->documentId()->value);
        self::assertSame($digest1, $resolved1->contentDigest()->value);
        self::assertCount(1, $resolved1->spans());
        self::assertSame(0, $resolved1->spans()[0]->locator->toWire()->get('byteStart'));
        self::assertSame(10, $resolved1->spans()[0]->locator->toWire()->get('byteEnd'));

        self::assertSame($c2Id, $resolved2->id()->value);
        self::assertNull($map->resolve('#99'));
        self::assertCount(2, $map->all());
        self::assertTrue($map->digest()->equals($mapAgain->digest()));
    }
}
