<?php

declare(strict_types=1);

namespace KnowKit\Tests\Bridge\Contracts;

use KnowKit\Bridge\AgenTools\Legacy\CanonicalTarget;
use KnowKit\Bridge\AgenTools\Legacy\IdentityMap;
use KnowKit\Bridge\AgenTools\Legacy\LimitedEvidence;
use KnowKit\Bridge\AgenTools\Legacy\Locator;
use KnowKit\Bridge\AgenTools\Legacy\Mapping;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Sha256;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

#[Group('bridge:contracts')]
final class IdentityMapTest extends TestCase
{
    public function testMissingOriginalRetainsOnlyLimitedLegacyEvidenceR40A01(): void
    {
        $fixture = Support::fixture('identity-map')['missing'];
        $mapping = Support::mapping($fixture);
        $result = (new IdentityMap([$mapping]))->resolve(scope: Support::scope(), release: $mapping->release);
        self::assertSame(Outcome::Success, $result->outcome);
        self::assertSame($mapping, $result->data);
        self::assertSame($fixture['expectedVerificationLevel'], $mapping->verificationLevel());
        self::assertNull($mapping->target);
        self::assertNull($mapping->citationFor($mapping->locators[0]));
        self::assertSame($fixture['expectedLimitations'], LimitedEvidence::LIMITATIONS);
        $wire = $mapping->limitedEvidence->toWire();
        self::assertSame(Support::wire($fixture['expectedEvidence'])->keys(), $wire->keys());
        self::assertSame(Jcs::canonicalize(Support::wire($fixture['expectedEvidence'])), Jcs::canonicalize($wire));
        self::assertSame([], Support::validator()->validateValue(document: $wire, schemaId: 'urn:knowkit:schema:legacy-evidence-ref:1.0'));
        foreach (['sourceDigest', 'irDigest', 'contentDigest', 'fingerprint'] as $forbidden) {
            self::assertFalse($wire->has($forbidden));
            self::assertNotEmpty(Support::validator()->validateValue(
                document: $wire->with(name: $forbidden, value: $mapping->release->legacyHash),
                schemaId: 'urn:knowkit:schema:legacy-evidence-ref:1.0',
            ));
        }
        self::assertNotEmpty(Support::validator()->validateValue(
            document: $wire->with(name: 'freshlyValidated', value: true),
            schemaId: 'urn:knowkit:schema:legacy-evidence-ref:1.0',
        ));
    }

    public function testSimilarNewSourceNeverReplacesHistoricalCitationR40A02(): void
    {
        $fixture = Support::fixture('identity-map');
        $old = Support::mapping($fixture['old']);
        $new = Support::mapping($fixture['similarNew']);
        $map = new IdentityMap([$old, $new]);
        $before = $old->target->citations[0]->canonical->canonical();
        $resolved = $map->resolve(scope: Support::scope(), release: $old->release);
        self::assertSame($old, $resolved->data);
        self::assertSame($before, $resolved->data->citationFor($old->locators[0])->canonical());
        self::assertNotSame($new->target->revision, $resolved->data->target->revision);
        self::assertNull($old->citationFor($new->locators[0]));
        $withoutOld = new IdentityMap([$new]);
        $missing = $withoutOld->resolve(scope: Support::scope(), release: $old->release);
        self::assertSame($fixture['expectedNoFallback'], $missing->outcome->value);
        self::assertSame('legacy_mapping_missing', $missing->errorCode);
        self::assertNull($missing->data);
        self::assertSame($before, $old->target->citations[0]->canonical->canonical());
    }

    public function testEveryCompositeKeyPartIsExactAndTenantAndKbAreIsolated(): void
    {
        $fixture = Support::fixture('identity-map')['old'];
        $old = Support::mapping($fixture);
        $map = new IdentityMap([$old]);
        foreach (['corpus', 'releaseId', 'legacyHashKind', 'legacyHash'] as $field) {
            $key = $fixture['identity'];
            $key[$field] = $field === 'legacyHash' ? str_repeat('0', 64) : 'other_value';
            $result = $map->resolve(scope: Support::scope(), release: Support::identity($key));
            self::assertSame(Outcome::Unavailable, $result->outcome, $field);
            self::assertNull($result->data);
        }
        $foreign = $fixture;
        foreach (['identity', 'revision', 'snapshot', 'citation'] as $field) {
            $foreign[$field]['tenantId'] = Support::OTHER_TENANT;
        }
        $other = Support::mapping($foreign);
        $map = new IdentityMap([$old, $other]);
        self::assertNotSame($old->release->key(), $other->release->key());
        self::assertSame(Outcome::Denied, $map->resolve(scope: Support::scope(), release: $other->release)->outcome);
        self::assertSame($other, $map->resolve(scope: Support::scope(Support::OTHER_TENANT), release: $other->release)->data);
        self::assertSame($old, $map->resolve(scope: Support::scope(), release: $old->release)->data);
        $wrongKb = $fixture;
        $wrongKb['snapshot']['kbId'] = Support::OTHER_TENANT;
        $wrong = Support::mapping($wrongKb);
        self::assertSame(Outcome::Denied, (new IdentityMap([$wrong]))->resolve(scope: Support::scope(), release: $old->release)->outcome);
        $left = array_replace($fixture['identity'], ['corpus' => 'a:b', 'releaseId' => 'c']);
        $right = array_replace($fixture['identity'], ['corpus' => 'a', 'releaseId' => 'b:c']);
        self::assertNotSame(Support::identity($left)->key(), Support::identity($right)->key());
    }

    public function testLegacyLocatorsRequireExactHistoricalSpanAndCanonicalIdentity(): void
    {
        $fixture = Support::fixture('identity-map')['old'];
        $mapping = Support::mapping($fixture);
        $locator = $mapping->locators[0];
        self::assertEquals(Support::wire($fixture['legacyLocator']), $locator->toWire());
        $canonical = $mapping->citationFor($locator);
        self::assertSame(Support::wire($fixture['citation'])->get('sourceDigest'), $canonical->toWire()->get('sourceDigest'));
        self::assertSame($fixture['citation']['spans'][0]['physicalNo'], $canonical->spans()[0]->pageNo());
        self::assertSame($fixture['originalBytes'], $canonical->spans()[0]->slice($fixture['originalBytes']));
        foreach (['material', 'version', 'fragment', 'page', 'start', 'end', 'content_hash'] as $field) {
            $wire = $fixture['legacyLocator'];
            $wire[$field] = match ($field) {
                'page', 'start', 'end' => $wire[$field] + 1,
                'content_hash' => str_repeat('0', 64),
                default => 'different',
            };
            self::assertNull($mapping->citationFor(Support::locator(identity: $mapping->release, data: $wire)), $field);
        }
        $this->expectException(\InvalidArgumentException::class);
        new Locator(
            release: $mapping->release,
            material: $locator->material,
            version: $locator->version,
            fragment: $locator->fragment,
            page: 0,
            start: 0,
            end: 1,
            contentHash: new Sha256(str_repeat('a', 64)),
        );
    }

    public function testConflictingOrCrossTenantCanonicalTargetsCannotBeImported(): void
    {
        $fixture = Support::fixture('identity-map')['old'];
        foreach (['sourceId', 'sourceVersionId', 'sourceDigest', 'documentRevisionId', 'irDigest', 'contentDigest'] as $field) {
            $data = $fixture;
            $data['citation'][$field] = str_ends_with($field, 'Digest') ? str_repeat('0', 64) : Support::OTHER_TENANT;
            $this->assertConflict(static fn () => Support::mapping($data));
        }
        foreach (['snapshot', 'citation'] as $field) {
            $foreign = $fixture;
            $foreign[$field]['tenantId'] = Support::OTHER_TENANT;
            $this->assertConflict(operation: static fn () => Support::mapping($foreign), outcome: Outcome::Denied);
        }
        $old = Support::mapping($fixture);
        $this->assertConflict(static fn () => new IdentityMap([$old, $old]));
        $this->assertConflict(static fn () => new CanonicalTarget(
            revision: $old->target->revision,
            snapshot: $old->target->snapshot,
            citations: [$old->target->citations[0], $old->target->citations[0]],
        ));
        $key = $fixture['identity'];
        $key['releaseId'] = 'release:similar';
        $this->assertConflict(static fn () => Mapping::fromOriginals(release: Support::identity($key), target: $old->target));
        $this->expectException(\InvalidArgumentException::class);
        new CanonicalTarget(revision: $old->target->revision, snapshot: $old->target->snapshot, citations: []);
    }

    private function assertConflict(\Closure $operation, Outcome $outcome = Outcome::Incompatible): void
    {
        try {
            $operation();
            self::fail('Conflicting identity accepted');
        } catch (DomainFailure $failure) {
            self::assertSame($outcome, $failure->outcome);
            self::assertSame($outcome === Outcome::Denied ? 'scope_mismatch' : 'immutable_conflict', $failure->errorCode);
        }
    }
}
