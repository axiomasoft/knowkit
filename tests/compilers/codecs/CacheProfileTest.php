<?php
declare(strict_types=1);

namespace KnowKit\Tests\Compilers\Codecs;

use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CacheSemantics;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('compilers:codecs')]
final class CacheProfileTest extends TestCase
{
    private function validator(): ContractValidator
    {
        $schemas = [];
        $files = glob(dirname(__DIR__, 3) . '/schemas/*.schema.json') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $schema = StrictJson::decode((string) file_get_contents($file), 4194304);
            self::assertInstanceOf(JsonObject::class, $schema);
            $id = $schema->get('$id');
            if (is_string($id)) {
                $schemas[$id] = $schema;
            }
        }

        return new ContractValidator(new SchemaSet($schemas));
    }

    public function testValidatesAllFiveReferenceProfilesAgainstSchema(): void
    {
        $validator = $this->validator();
        $fixtures = [
            ['explicit-obj-v1', CacheSemantics::ExplicitServerObject, 2048, 0, 1000, 10000, 2500, 3600, true, true, false, 'canonical_json'],
            ['explicit-bp-v1', CacheSemantics::ExplicitBreakpoints, 1024, 4, 1000, 12500, 0, 300, false, true, false, 'compact_markdown'],
            ['implicit-pref-v1', CacheSemantics::ImplicitPrefix, 1024, 0, 2500, 10000, 0, 300, false, true, false, 'toon'],
            ['local-radix-v1', CacheSemantics::LocalRadixPrefix, 512, 0, 500, 10000, 0, 600, true, false, true, 'toon'],
            ['compact-nocache-v1', CacheSemantics::CompactNoCache, 0, 0, 10000, 10000, 0, 0, false, false, true, 'compact_markdown'],
        ];

        $digests = [];
        foreach ($fixtures as [$id, $semantics, $minTokens, $maxBp, $readBps, $writeBps, $storageBps, $ttl, $affinity, $tools, $strip, $codec]) {
            $profile = new CacheProfile(
                profileId: $id,
                semantics: $semantics,
                minCacheableTokens: $minTokens,
                maxBreakpoints: $maxBp,
                readCostBps: $readBps,
                writeCostBps: $writeBps,
                hourlyStorageBps: $storageBps,
                ttlSeconds: $ttl,
                supportsSessionAffinity: $affinity,
                discountsToolTokens: $tools,
                stripPriorThinkingBlocks: $strip,
                preferredCodec: $codec,
            );

            $wire = $profile->toWire();
            self::assertSame([], $validator->validateValue($wire, 'urn:knowkit:schema:cache-profile:1.0'));

            $roundTrip = CacheProfile::fromWire($wire, $validator);
            self::assertTrue($profile->digest()->equals($roundTrip->digest()));
            $digests[$id] = $profile->digest()->value;
        }

        self::assertCount(5, array_unique($digests));
    }

    public function testRejectsInvalidBoundsAndUnknownCodec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheProfile(
            profileId: 'invalid-bp',
            semantics: CacheSemantics::ExplicitBreakpoints,
            minCacheableTokens: 1024,
            maxBreakpoints: 17,
            readCostBps: 1000,
            writeCostBps: 12500,
            hourlyStorageBps: 0,
            ttlSeconds: 300,
            supportsSessionAffinity: false,
            discountsToolTokens: true,
            stripPriorThinkingBlocks: false,
            preferredCodec: 'toon',
        );
    }

    public function testRejectsInvalidPreferredCodec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheProfile(
            profileId: 'invalid-codec',
            semantics: CacheSemantics::ImplicitPrefix,
            minCacheableTokens: 512,
            maxBreakpoints: 0,
            readCostBps: 2500,
            writeCostBps: 10000,
            hourlyStorageBps: 0,
            ttlSeconds: 300,
            supportsSessionAffinity: false,
            discountsToolTokens: true,
            stripPriorThinkingBlocks: false,
            preferredCodec: 'yaml',
        );
    }
}
