<?php
declare(strict_types=1);

namespace KnowKit\Tests\Compilers\Strategies;

use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CacheSemantics;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\EvidenceSet;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Layers\{DeliveryMode, DisclosureMode, LayerBacking, LayerBudgetPolicy, LayerId, LayerNode, MergeStrategy, ResolutionTier, ResolvedLayerStack, TrustTier};
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\Ulid;

final class StrategyFixtures
{
    public const TENANT = '01JSC420000000000000000000';

    public static function profile(
        CacheSemantics $s,
        int $min = 0,
        int $maxBp = 0,
        int $read = 1000,
        int $write = 10000,
        int $storage = 0,
        bool $strip = false,
    ): CacheProfile {
        return new CacheProfile('p-' . str_replace('_', '-', $s->value), $s, $min, $maxBp, $read, $write, $storage, 300, false, true, $strip, 'toon');
    }

    public static function scope(): Scope
    {
        return new Scope(new TenantId(self::TENANT), new KbId('01JSC420000000000000000001'), new ActorId('01JSC420000000000000000002'), 'test.run', new Decimal('1'), new Sha256(str_repeat('d', 64)));
    }

    public static function backing(string $kind): LayerBacking
    {
        $u = new Ulid('01JSC420000000000000000003');
        return $kind === 'catalog_mount'
            ? LayerBacking::catalogMount($u, new TenantId(self::TENANT), new Sha256(str_repeat('e', 64)))
            : LayerBacking::directive($u, new Sha256(str_repeat('f', 64)));
    }

    /** @param array<string, string|int|bool> $values */
    public static function node(string $id, TrustTier $tier, DeliveryMode $mode, array $values, string $backing = 'directive'): LayerNode
    {
        return new LayerNode(
            new LayerId($id), [], $tier, $mode, MergeStrategy::OverrideAllowed, DisclosureMode::VerbatimAllowed,
            [], new JsonObject($values), [],
            new LayerBudgetPolicy(0, 1000, 10000, 100, ResolutionTier::R1Digest, ResolutionTier::R0Outline),
            self::backing($backing),
        );
    }

    /** @param list<LayerNode> $nodes */
    public static function stack(array $nodes): ResolvedLayerStack
    {
        $d = new Fingerprint(str_repeat('1', 64));
        return new ResolvedLayerStack(new TenantId(self::TENANT), new Ulid('01JSC420000000000000000004'), $nodes, [], [], $d, new Fingerprint(str_repeat('2', 64)), new Fingerprint(str_repeat('3', 64)));
    }

    public static function evidence(string $fragmentText = ''): EvidenceSet
    {
        $id = 'urn:knowkit:schema:evidence-set:1.0';
        $validator = new ContractValidator(new SchemaSet([$id => new JsonObject(['$schema' => SchemaSet::DIALECT, '$id' => $id, 'type' => 'object'])]));
        $fragments = [];
        if ($fragmentText !== '') {
            $dg = hash('sha256', $fragmentText);
            $citation = new JsonObject([
                'schemaVersion' => 'citation-ref:1', 'citationId' => '01JSC420000000000000000101', 'tenantId' => self::TENANT,
                'sourceId' => '01JSC420000000000000000005', 'sourceVersionId' => '01JSC420000000000000000006', 'sourceDigest' => str_repeat('a', 64),
                'documentId' => '01JSC420000000000000000201', 'documentRevisionId' => '01JSC420000000000000000007', 'irDigest' => str_repeat('b', 64),
                'contentDigest' => $dg,
                'spans' => [new JsonObject(['kind' => 'segment', 'segmentId' => 's-1', 'blockId' => 'b-1', 'byteStart' => 0, 'byteEnd' => 5, 'textDigest' => $dg])],
            ]);
            $fragments[] = new JsonObject(['fragmentDigest' => $dg, 'text' => $fragmentText, 'citation' => $citation]);
        }
        $base = new JsonObject([
            'schemaVersion' => 'evidence-set:1', 'usageId' => '01JSC420000000000000000010', 'tenantId' => self::TENANT,
            'selectionId' => '01JSC420000000000000000011', 'disclosureAttemptId' => '01JSC420000000000000000012',
            'hostScopeHash' => str_repeat('d', 64),
            'snapshotRef' => new JsonObject(['tenantId' => self::TENANT, 'snapshotId' => '01JSC420000000000000000013']),
            'totalBytes' => strlen($fragmentText), 'fragments' => $fragments,
        ]);

        return new EvidenceSet($base->with('evidenceSetDigest', HashDomain::EvidenceSet->digest($base)), $validator);
    }

    /** @return list<LayerNode> */
    public static function standardNodes(string $backing = 'directive'): array
    {
        return [
            self::node('l0.core', TrustTier::TrustedDirective, DeliveryMode::PinnedPrefix, ['rule' => str_repeat('be precise ', 20)], $backing),
            self::node('l2.ref', TrustTier::CuratedReference, DeliveryMode::PinnedPrefix, ['chapter' => str_repeat('reference text ', 15)], $backing),
            self::node('l4.overlay', TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, ['now' => '2026-01-01T10:00:00Z'], $backing),
        ];
    }
}
