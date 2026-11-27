<?php

declare(strict_types=1);

namespace KnowKit\Tests\Matrix;

use KnowKit\Adapter\LocalBlob\BlobCompressionCodec;
use KnowKit\Adapter\Pruning\DeterministicSentencePruner;
use KnowKit\Application\Layers\LayerStackResolver;
use KnowKit\ContextCompilers\Compiler\PromptCacheCompiler;
use KnowKit\ContextCompilers\Domain\CacheProfile;
use KnowKit\ContextCompilers\Domain\CacheSemantics;
use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\Chunk;
use KnowKit\Domain\ChunkId;
use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\ChunkSetId;
use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\DocumentId;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\EffectiveProfile;
use KnowKit\Domain\EvidenceFragment;
use KnowKit\Domain\EvidenceRepresentation;
use KnowKit\Domain\EvidenceSet;
use KnowKit\Domain\ExactSourceRef;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\IrDigest;
use KnowKit\Domain\IsolationPreset;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Layers\DeliveryMode;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\LayerBacking;
use KnowKit\Domain\Layers\LayerBudgetPolicy;
use KnowKit\Domain\Layers\LayerId;
use KnowKit\Domain\Layers\LayerNode;
use KnowKit\Domain\Layers\MergeStrategy;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Layers\TrustTier;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ParserSignature;
use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Domain\Query;
use KnowKit\Domain\QueryBinding;
use KnowKit\Domain\Scope;
use KnowKit\Domain\SelectionLimits;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\SourceDigest;
use KnowKit\Domain\SourceId;
use KnowKit\Domain\SourceVersionId;
use KnowKit\Domain\Span;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\TextLocator;
use KnowKit\Domain\Ulid;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('matrix:combinatorial')]
final class CombinatorialMatrixTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(PromptCacheCompiler::class)) {
            $base = dirname(__DIR__, 2) . '/packages/context-compilers/src/';
            spl_autoload_register(static function (string $class) use ($base): void {
                $prefix = 'KnowKit\\ContextCompilers\\';
                if (str_starts_with($class, $prefix)) {
                    $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });
        }
    }

    public function testTrustTierDeliveryModeTruthTable(): void
    {
        $resolver = new LayerStackResolver();
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000001');
        $digest = new Fingerprint(str_repeat('a', 64));

        $allowed = [
            TrustTier::TrustedDirective->value . ':' . DeliveryMode::PinnedPrefix->value => true,
            TrustTier::TrustedDirective->value . ':' . DeliveryMode::SessionPinned->value => true,
            TrustTier::CuratedReference->value . ':' . DeliveryMode::PinnedPrefix->value => true,
            TrustTier::CuratedReference->value . ':' . DeliveryMode::SessionPinned->value => true,
            TrustTier::CuratedReference->value . ':' . DeliveryMode::HybridRetrievable->value => true,
            TrustTier::UntrustedEvidence->value . ':' . DeliveryMode::HybridRetrievable->value => true,
            TrustTier::UntrustedEvidence->value . ':' . DeliveryMode::ToolDiscoverable->value => true,
            TrustTier::UntrustedEvidence->value . ':' . DeliveryMode::VolatileSuffix->value => true,
            TrustTier::DynamicOverlay->value . ':' . DeliveryMode::VolatileSuffix->value => true,
        ];

        $checked = 0;
        foreach (TrustTier::cases() as $trust) {
            foreach (DeliveryMode::cases() as $delivery) {
                $checked++;
                $key = $trust->value . ':' . $delivery->value;
                $expectedAllowed = $allowed[$key] ?? false;
                self::assertSame($expectedAllowed, $trust->allowsDeliveryMode($delivery), $key);

                $merge = $trust === TrustTier::TrustedDirective
                    ? MergeStrategy::ImmutableSeal
                    : ($trust === TrustTier::DynamicOverlay ? MergeStrategy::OverrideAllowed : MergeStrategy::WeightedMix);
                $backing = $trust === TrustTier::TrustedDirective
                    ? LayerBacking::directive(new Ulid('01JSD000000000000000000000'), $digest)
                    : LayerBacking::overlay(new LocalId('node-1'));

                $node = new LayerNode(
                    layerId: LayerId::fromString('node.test'),
                    dependsOnLayerIds: [],
                    trustTier: $trust,
                    deliveryMode: $delivery,
                    mergeStrategy: $merge,
                    disclosureMode: DisclosureMode::VerbatimAllowed,
                    lockedKeys: [],
                    ruleValues: new JsonObject(['k' => 'v']),
                    denySelectors: [],
                    budget: $this->budget(),
                    backing: $backing,
                );

                if ($expectedAllowed) {
                    $resolved = $resolver->resolve($tenantId, $stackId, [$node]);
                    self::assertCount(1, $resolved->nodes);
                } else {
                    try {
                        $resolver->resolve($tenantId, $stackId, [$node]);
                        self::fail('Expected invalid_trust_tier for ' . $key);
                    } catch (DomainFailure $e) {
                        self::assertSame(Outcome::Denied, $e->outcome);
                        self::assertSame('invalid_trust_tier', $e->errorCode);
                    }
                }
            }
        }
        self::assertSame(20, $checked);
    }

    public function testDisclosureModeResolutionTierTruthTable(): void
    {
        $checked = 0;
        foreach (DisclosureMode::cases() as $mode) {
            foreach (ResolutionTier::cases() as $tier) {
                $checked++;
                $expected = match ($mode) {
                    DisclosureMode::VerbatimAllowed => true,
                    DisclosureMode::DerivedOnly => $tier !== ResolutionTier::R3Verbatim,
                    DisclosureMode::SummaryOnly => $tier === ResolutionTier::R0Outline || $tier === ResolutionTier::R1Digest,
                    DisclosureMode::MetadataOnly => $tier === ResolutionTier::R0Outline,
                    DisclosureMode::IndexOnly => false,
                };
                self::assertSame($expected, $mode->allowsTier($tier), $mode->value . ' x ' . $tier->value);

                $r3BytesRead = 0;
                if ($mode->allowsTier($tier) && $tier === ResolutionTier::R3Verbatim && $mode->allowsDirectRead()) {
                    $r3BytesRead = 128;
                }
                if (!$expected || $mode !== DisclosureMode::VerbatimAllowed) {
                    self::assertSame(0, $r3BytesRead, 'Forbidden R3 byte read for ' . $mode->value . ' x ' . $tier->value);
                }
            }
        }
        self::assertSame(20, $checked);
    }

    public function testPairwiseValidAndForbiddenMatrixEndToEnd(): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/pairwise-matrix.json');
        self::assertIsString($raw);
        $matrix = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $vectors = $matrix['vectors'];
        self::assertGreaterThanOrEqual(40, count($vectors));

        // Verify 100% pairwise coverage across all 9 axes.
        $axisNames = array_keys($matrix['axes']);
        $coveredPairs = [];
        foreach ($vectors as $v) {
            for ($i = 0; $i < count($axisNames); $i++) {
                for ($j = $i + 1; $j < count($axisNames); $j++) {
                    $a = $axisNames[$i];
                    $b = $axisNames[$j];
                    $coveredPairs["{$a}={$v[$a]}|{$b}={$v[$b]}"] = true;
                }
            }
        }
        self::assertSame($matrix['totalPairs'], count($coveredPairs), 'Pairwise matrix must cover 100% of pairs');

        $resolver = new LayerStackResolver();
        $compiler = new PromptCacheCompiler();
        $pruner = new DeterministicSentencePruner();
        $validator = $this->permissiveValidator();

        $tenantId = new TenantId('01JSC420000000000000000000');
        $kbId = new KbId('01JSKB00000000000000000001');
        $scope = new Scope(
            $tenantId,
            $kbId,
            new ActorId('01JSAC00000000000000000001'),
            'qa.matrix',
            new Decimal('1'),
            new Sha256(str_repeat('1', 64)),
        );
        $digest = new Fingerprint(str_repeat('a', 64));

        $validRunCount = 0;
        $forbiddenRunCount = 0;

        foreach ($vectors as $v) {
            $preset = IsolationPreset::from($v['isolationPreset']);
            $disclosure = DisclosureMode::from($v['disclosureMode']);
            $tier = ResolutionTier::from($v['resolutionTier']);
            $trust = TrustTier::from($v['trustTier']);
            $delivery = DeliveryMode::from($v['deliveryMode']);
            $semantics = CacheSemantics::from($v['cacheSemantics']);
            $codec = match ($v['blobCompressionCodec']) {
                'None' => BlobCompressionCodec::None,
                'Gzip' => BlobCompressionCodec::Gzip,
                'Zstd' => BlobCompressionCodec::Zstd,
            };

            // Verify codec availability check behaves deterministically (fail-closed if ext-zstd missing).
            if ($codec === BlobCompressionCodec::Zstd && !extension_loaded('zstd')) {
                $this->assertFailClosedZstd($codec);
            } else {
                $codec->assertAvailable();
            }

            if (!$v['expectedValid']) {
                $forbiddenRunCount++;
                self::assertSame(
                    $v['expectedTrustValid'],
                    $trust->allowsDeliveryMode($delivery),
                    $v['id'],
                );
                self::assertSame(
                    $v['expectedDisclosureValid'],
                    $disclosure->allowsTier($tier),
                    $v['id'],
                );
                continue;
            }

            $validRunCount++;
            $sysNode = new LayerNode(
                layerId: LayerId::fromString('core.directive'),
                dependsOnLayerIds: [],
                trustTier: TrustTier::TrustedDirective,
                deliveryMode: DeliveryMode::PinnedPrefix,
                mergeStrategy: MergeStrategy::ImmutableSeal,
                disclosureMode: DisclosureMode::VerbatimAllowed,
                lockedKeys: ['safety.no_harm'],
                ruleValues: new JsonObject(['safety.no_harm' => true, 'preset' => $preset->value]),
                denySelectors: [],
                budget: $this->budget(),
                backing: LayerBacking::directive(new Ulid('01JSD000000000000000000000'), $digest),
            );
            $varNode = new LayerNode(
                layerId: LayerId::fromString('variant.node'),
                dependsOnLayerIds: [LayerId::fromString('core.directive')],
                trustTier: $trust,
                deliveryMode: $delivery,
                mergeStrategy: $trust === TrustTier::TrustedDirective ? MergeStrategy::GuardedExtend : ($trust === TrustTier::DynamicOverlay ? MergeStrategy::OverrideAllowed : MergeStrategy::WeightedMix),
                disclosureMode: $disclosure,
                lockedKeys: [],
                ruleValues: new JsonObject(['vector_format' => $v['vectorStorageFormat']]),
                denySelectors: [],
                budget: $this->budget(),
                backing: $trust === TrustTier::TrustedDirective
                    ? LayerBacking::directive(new Ulid('01JSD000000000000000000001'), $digest)
                    : LayerBacking::overlay(new LocalId('overlay-1')),
            );

            $stack = $resolver->resolve($tenantId, new Ulid('01JST000000000000000000001'), [$sysNode, $varNode]);

            $unitText = 'Momentum conservation governs isolated systems. Noise sentence here.';
            if ($v['contextPruner'] === 'deterministic_sentence') {
                $q = new Query('momentum conservation');
                $chunk = $this->makeChunk($tenantId, $unitText);
                $pruned = $pruner->prune(
                    new QueryBinding($q, $q, 'rev1', new EffectiveProfile('test', '1.0', []), SelectionLimits::profile('compact')),
                    [new PrunableUnit($chunk, [])],
                    new PruningPolicy(200, true, true, 16, 4096),
                );
                self::assertCount(1, $pruned);
            }

            $evidence = $this->evidence($tenantId, $validator, 'Derived evidence for ' . $v['id']);
            $profile = new CacheProfile(
                profileId: 'matrix-profile',
                semantics: $semantics,
                minCacheableTokens: 16,
                maxBreakpoints: 4,
                readCostBps: 1000,
                writeCostBps: 12500,
                hourlyStorageBps: 100,
                ttlSeconds: 300,
                supportsSessionAffinity: true,
                discountsToolTokens: true,
                stripPriorThinkingBlocks: true,
                preferredCodec: 'toon',
            );

            $env1 = $compiler->compile($scope, $stack, $evidence, $profile);
            $env2 = $compiler->compile($scope, $stack, $evidence, $profile);

            // ARCH-UNTRUSTED: UntrustedEvidence / DynamicOverlay never enter systemBlocks.
            foreach ($env1->systemBlocks as $sysBlock) {
                self::assertSame(TrustTier::TrustedDirective, $sysBlock->trustTier);
            }
            self::assertSame($env1->expectedPrefixHash->value, $env2->expectedPrefixHash->value);

            $aliasMap = CitationAliasMap::fromEvidenceSet($evidence);
            $rehydrated = $aliasMap->resolve('#1');
            self::assertNotNull($rehydrated);
        }

        self::assertGreaterThanOrEqual(12, $validRunCount);
        self::assertGreaterThanOrEqual(12, $forbiddenRunCount);
    }

    private function assertFailClosedZstd(BlobCompressionCodec $codec): void
    {
        try {
            $codec->assertAvailable();
            self::fail('Expected missing_extension:zstd');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Unavailable, $e->outcome);
            self::assertSame('missing_extension_zstd', $e->errorCode);
        }
    }

    private function budget(): LayerBudgetPolicy
    {
        return new LayerBudgetPolicy(64, 1024, 16384, 5000, ResolutionTier::R1Digest, ResolutionTier::R0Outline);
    }

    private function permissiveValidator(): ContractValidator
    {
        $ids = [
            'urn:knowkit:schema:evidence-set:1.0',
            'urn:knowkit:schema:citation-ref:1.0',
            'urn:knowkit:schema:common:1.0',
        ];
        $schemas = [];
        foreach ($ids as $id) {
            $schemas[$id] = new JsonObject([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                '$id' => $id,
                'type' => 'object',
            ]);
        }

        return new ContractValidator(new SchemaSet($schemas));
    }

    private function makeChunk(TenantId $tenantId, string $text): Chunk
    {
        $rev = new DocumentRevisionRef(
            new ExactSourceRef(
                $tenantId,
                new SourceId('01JSSR00000000000000000001'),
                new SourceVersionId('01JSSV00000000000000000001'),
                new SourceDigest(str_repeat('a', 64)),
            ),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(str_repeat('b', 64)),
            new ParserSignature('md', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );
        $span = new Span(
            TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, strlen($text)),
            new Sha256(hash('sha256', $text)),
        );
        $ref = new ChunkRef(
            $rev,
            new ChunkSetId('01JSCS00000000000000000001'),
            new ChunkId('01JSCK00000000000000000001'),
            new Sha256(hash('sha256', $text)),
        );

        return new Chunk($ref, new DocumentId('01JSD000000000000000000001'), [$span], $text);
    }

    private function evidence(TenantId $tenantId, ContractValidator $validator, string $text): EvidenceSet
    {
        $digest = hash('sha256', $text);
        $base = new JsonObject([
            'schemaVersion' => 'evidence-set:1',
            'tenantId' => $tenantId->value,
            'queryBindingDigest' => HashDomain::QueryBinding->digest(['q' => 'matrix']),
            'snapshotRef' => new JsonObject([
                'tenantId' => $tenantId->value,
                'kbId' => '01JSKB00000000000000000001',
                'snapshotId' => '01JSSN00000000000000000001',
                'manifestDigest' => str_repeat('e', 64),
            ]),
            'totalBytes' => strlen($text),
            'fragments' => [
                new JsonObject([
                    'text' => $text,
                    'fragmentDigest' => $digest,
                    'joiner' => null,
                    'representation' => 'derived',
                    'citation' => new JsonObject([
                        'schemaVersion' => 'citation-ref:1',
                        'citationId' => '01JSCT00000000000000000001',
                        'tenantId' => $tenantId->value,
                        'sourceId' => '01JSSR00000000000000000001',
                        'sourceVersionId' => '01JSSV00000000000000000001',
                        'sourceDigest' => str_repeat('a', 64),
                        'documentId' => '01JSD000000000000000000001',
                        'documentRevisionId' => '01JSDV00000000000000000001',
                        'irDigest' => str_repeat('b', 64),
                        'contentDigest' => $digest,
                        'spans' => [
                            new JsonObject([
                                'kind' => 'segment',
                                'segmentId' => 'seg-1',
                                'blockId' => 'b-1',
                                'byteStart' => 0,
                                'byteEnd' => strlen($text),
                                'textDigest' => $digest,
                            ]),
                        ],
                    ]),
                ]),
            ],
        ]);

        return new EvidenceSet(
            $base->with('evidenceSetDigest', HashDomain::EvidenceSet->digest($base)),
            $validator,
        );
    }
}
