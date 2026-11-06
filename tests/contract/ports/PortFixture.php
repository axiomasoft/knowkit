<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Ports;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject, StrictJson};
use KnowKit\Tests\Contract\Schemas\ContractHarness;

final class PortFixture
{
    public static function root(): string { return dirname(__DIR__, 3); }
    public static function json(string $path): JsonObject
    {
        return StrictJson::decode((string) file_get_contents(self::root() . '/' . $path), 67108864);
    }
    public static function validator(): ContractValidator
    {
        static $validator;
        return $validator ??= (new ContractHarness(self::root()))->validator;
    }
    public static function fixture(): JsonObject { return self::json('tests/fixtures/identity/v1/processing.json'); }
    public static function id(int $n): string { return '01J' . str_pad((string) $n, 23, '0', STR_PAD_LEFT); }
    public static function scope(?string $hash = null): D\Scope
    {
        return new D\Scope(new D\TenantId(self::id(1)), new D\KbId(self::id(100)), new D\ActorId(self::id(101)),
            'lesson.read', new D\Decimal('42'), new D\Sha256($hash ?? str_repeat('a', 64)));
    }
    public static function signature(JsonObject $wire): D\ParserSignature
    {
        return new D\ParserSignature($wire->get('name'), $wire->get('version'), new D\Fingerprint($wire->get('profileDigest')));
    }
    public static function processing(JsonObject $wire): D\ProcessingIdentity
    {
        return new D\ProcessingIdentity(new D\SourceVersionId($wire->get('sourceVersionId')), new D\SourceDigest($wire->get('sourceDigest')),
            self::signature($wire->get('parserSignature')), self::signature($wire->get('ocrProfileSignature')),
            self::signature($wire->get('chunkerSignature')), $wire->get('irSchema'),
            new D\Fingerprint($wire->get('indexProfileDigest')),
            $wire->get('embeddingSpaceDigest') === null ? null : new D\Fingerprint($wire->get('embeddingSpaceDigest')));
    }
    public static function source(): D\SourceVersionRef
    {
        $fixture = self::fixture();
        return new D\SourceVersionRef(new D\TenantId($fixture->get('tenantId')), new D\SourceId($fixture->get('sourceId')),
            new D\SourceVersionId($fixture->get('base')->get('sourceVersionId')), new D\SourceDigest($fixture->get('base')->get('sourceDigest')));
    }
    public static function revision(int $n = 0): D\DocumentRevisionRef
    {
        $item = self::fixture()->get('history')[$n];
        $document = new D\IrDocument($item->get('ir'), self::validator());
        return new D\DocumentRevisionRef(self::source(), new D\DocumentRevisionId($item->get('documentRevisionId')),
            new D\IrDigest(hash('sha256', $document->canonical())), self::signature($item->get('ir')->get('parserSignature')));
    }
    public static function chunk(int $n = 0): D\ChunkRef
    {
        $item = self::fixture()->get('history')[$n];
        return new D\ChunkRef(self::revision($n), new D\ChunkSetId($item->get('chunkSetId')),
            new D\ChunkId($item->get('chunkId')), new D\Sha256(hash('sha256', $item->get('ir')->get('segments')[0]->get('text'))));
    }
    public static function binding(?D\Scope $scope = null): JsonObject
    {
        return new JsonObject(['tenantPolicyEpoch'=>'7','kbPolicyEpoch'=>($scope ?? self::scope())->policyEpoch->value,
            'sources'=>[new JsonObject(['sourceId'=>self::id(2),'sourcePolicyEpoch'=>'3','lifecycleEpoch'=>'1'])]]);
    }
    public static function receipt(D\Decision $decision, D\Operation $operation, D\ResourceRef $resource, ?D\Scope $scope = null): D\DecisionReceipt
    {
        $scope ??= self::scope();
        $wire = new JsonObject(['decision'=>$decision->value,'operation'=>$operation->value,'resourceRefs'=>[$resource->toWire()],
            'binding'=>self::binding($scope),'ownerRevision'=>null,'checkedAt'=>'2026-11-06T12:00:00Z',
            'expiresAt'=>'2026-11-06T12:00:05Z','hostScopeHash'=>$scope->hostScopeHash->value]);
        return new D\DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), self::validator());
    }
    public static function grant(): D\QueryGrant
    {
        return new D\QueryGrant(new JsonObject(['allowedCollectionIds'=>[self::id(200)],
            'allowedSourceVersionIds'=>[self::id(3)],'grantEpoch'=>'42',
            'policyBindingDigest'=>HashDomain::PolicyBinding->digest(self::binding()),'expiresAt'=>'2026-11-06T12:00:05Z']), self::validator());
    }
    public static function snapshot(): D\SnapshotRef
    {
        return new D\SnapshotRef(self::scope()->tenantId, self::scope()->kbId, new D\SnapshotId(self::id(110)), new D\Fingerprint(str_repeat('b',64)));
    }
    public static function search(?D\Scope $scope = null): D\AuthorizedSearchScope
    {
        return D\AuthorizedSearchScope::materialize($scope ?? self::scope(), self::grant(), self::snapshot(),
            new D\GenerationId(self::id(14)), [new D\SourceVersionId(self::id(3))], new D\UtcInstant('2026-11-06T12:00:01Z'));
    }
    public static function space(array $changes = []): D\SpaceRef
    {
        $wire = iterator_to_array(self::fixture()->get('space'));
        $wire = array_replace($wire, $changes);
        return new D\SpaceRef(...$wire);
    }
    public static function egress(bool $document): D\EmbeddingAccess
    {
        $item = new JsonObject(['category'=>$document ? 'DOCUMENT_CONTENT' : 'QUERY',
            'contentDigest'=>hash('sha256', 'provider input'), 'sourceVersionRefs'=>$document ? [self::source()->toWire()] : []]);
        $request = new JsonObject(['operation'=>$document ? 'ingest' : 'retrieve',
            'destination'=>new JsonObject(['providerId'=>'recorded','accountId'=>'test','region'=>'local','modelId'=>'deterministic']),
            'items'=>[$item],'manifestDigest'=>hash('sha256','complete outbound test manifest')]);
        return new D\EmbeddingAccess(self::scope(), new D\EgressRequest($request, self::validator()));
    }

    public static function validateManifest(JsonObject $manifest): void
    {
        if ($manifest->get('schemaVersion') !== 'identity-manifest:1' || $manifest->get('entries') === []) {
            throw new \InvalidArgumentException('Empty or unknown identity manifest');
        }
        $seen = [];
        foreach ($manifest->get('entries') as $entry) {
            if (isset($seen[$entry->get('id')])) { throw new \InvalidArgumentException('Duplicate fixture'); }
            $seen[$entry->get('id')] = true;
            if (!in_array($entry->get('schemaId'), ['urn:knowkit:test:identity-fixture:1','urn:knowkit:test:registry-fixture:1',
                'urn:knowkit:test:catalog-fixture:1'], true)) {
                throw new \InvalidArgumentException('Unknown fixture schema');
            }
            $path = realpath(self::root() . '/' . $entry->get('path'));
            if ($path === false || !str_starts_with($path, self::root() . '/tests/fixtures/identity/')
                || !is_file($path) || hash_file('sha256', $path) !== $entry->get('sha256')) {
                throw new \InvalidArgumentException('Missing or corrupt fixture');
            }
            if ($entry->get('expected') === null || !$entry->get('rights') instanceof JsonObject
                || !$entry->get('rights')->get('license') || !$entry->get('rights')->get('origin')) {
                throw new \InvalidArgumentException('Fixture expectations and provenance required');
            }
        }
    }
}
