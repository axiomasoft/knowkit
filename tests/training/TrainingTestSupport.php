<?php

declare(strict_types=1);

namespace KnowKit\Tests\Training;

use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\Domain\{
    ActorId,
    Decision,
    DecisionReceipt,
    Decimal,
    EvidenceSet,
    Fingerprint,
    KbId,
    NodeRefs,
    Operation,
    QueryGrant,
    ResourceRef,
    Scope,
    Sha256,
    SnapshotId,
    SnapshotRef,
    SourceDigest,
    SourceId,
    SourceVersionId,
    SourceVersionRef,
    TenantId,
    Tombstone,
    Ulid,
    UsabilityReport,
};
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Port\{AccessPolicy, DependencyStore};
use KnowKit\TrainingExport\Domain\{TrainingRightsDecision, TrainingTraceRecord};
use KnowKit\TrainingExport\Port\TrainingRightsPolicy;

final class TrainingTestSupport
{
    private static ?ContractValidator $validator = null;

    public static function validator(): ContractValidator
    {
        if (self::$validator !== null) {
            return self::$validator;
        }

        $root = dirname(__DIR__, 2);
        $schemas = [];
        foreach (glob($root . '/schemas/*.schema.json') ?: [] as $file) {
            $decoded = StrictJson::decode((string) file_get_contents($file));
            if ($decoded instanceof JsonObject && is_string($decoded->get('$id'))) {
                $schemas[$decoded->get('$id')] = $decoded;
            }
        }

        return self::$validator = new ContractValidator(new SchemaSet($schemas));
    }

    public static function makeScope(): Scope
    {
        return new Scope(
            tenantId: new TenantId('01JSC420000000000000000000'),
            kbId: new KbId('01JKB000000000000000000001'),
            actorId: new ActorId('01JAC000000000000000000001'),
            purpose: 'training.export',
            policyEpoch: new Decimal('1'),
            hostScopeHash: new Sha256(str_repeat('a', 64)),
        );
    }

    public static function makeSourceRef(
        string $sourceId = '01JSR000000000000000000001',
        string $sourceVersionId = '01JSV000000000000000000001',
    ): SourceVersionRef {
        return new SourceVersionRef(
            tenantId: new TenantId('01JSC420000000000000000000'),
            sourceId: new SourceId($sourceId),
            sourceVersionId: new SourceVersionId($sourceVersionId),
            sourceDigest: new SourceDigest(str_repeat('4', 64)),
        );
    }

    public static function emptyEvidenceSet(TenantId $tenantId): EvidenceSet
    {
        $schemaId = 'urn:knowkit:schema:evidence-set:1.0';
        $permissive = new ContractValidator(new SchemaSet([
            $schemaId => new JsonObject([
                '$schema' => SchemaSet::DIALECT,
                '$id' => $schemaId,
                'type' => 'object',
            ]),
        ]));
        $wireWithoutDigest = new JsonObject([
            'tenantId' => $tenantId->value,
            'usageId' => '01JVS000000000000000000001',
            'selectionId' => '01JSK000000000000000000001',
            'disclosureAttemptId' => '01JDA000000000000000000001',
            'hostScopeHash' => str_repeat('a', 64),
            'scopeBindingDigest' => str_repeat('b', 64),
            'snapshotRef' => (new SnapshotRef(
                $tenantId,
                new KbId('01JKB000000000000000000001'),
                new SnapshotId('01JSN000000000000000000001'),
                new Fingerprint(str_repeat('8', 64)),
            ))->toWire(),
            'fragments' => [],
            'totalBytes' => 0,
        ]);
        $digest = HashDomain::EvidenceSet->digest($wireWithoutDigest);

        return new EvidenceSet($wireWithoutDigest->with('evidenceSetDigest', $digest), $permissive);
    }

    public static function makeEvidenceSet(TenantId $tenantId, string $provenanceKind = 'verbatim'): EvidenceSet
    {
        $text = 'Verified physics fragment.';
        $textDigest = hash('sha256', $text);
        $citationData = [
            'tenantId' => $tenantId->value,
            'sourceId' => '01JSR000000000000000000001',
            'sourceVersionId' => '01JSV000000000000000000001',
            'sourceDigest' => str_repeat('4', 64),
            'documentRevisionId' => '01JDR000000000000000000001',
            'irDigest' => str_repeat('5', 64),
            'citationId' => '01JCT000000000000000000001',
            'documentId' => '01JDC000000000000000000001',
            'contentDigest' => $textDigest,
            'origin' => $provenanceKind === 'verbatim' ? 'source' : 'generated',
            'spans' => [
                new JsonObject([
                    'kind' => 'segment',
                    'segmentId' => 'seg-1',
                    'blockId' => 'blk-1',
                    'byteStart' => 0,
                    'byteEnd' => strlen($text),
                    'textDigest' => $textDigest,
                ]),
            ],
        ];
        if ($provenanceKind !== 'verbatim') {
            $citationData['supportingRefs'] = ['blk-1'];
        }
        $citation = new JsonObject($citationData);

        $fragment = new JsonObject([
            'text' => $text,
            'fragmentDigest' => $textDigest,
            'provenanceKind' => $provenanceKind,
            'citation' => $citation,
            'joiner' => null,
        ]);

        $wireWithoutDigest = new JsonObject([
            'tenantId' => $tenantId->value,
            'usageId' => '01JVS000000000000000000001',
            'selectionId' => '01JSK000000000000000000001',
            'disclosureAttemptId' => '01JDA000000000000000000001',
            'hostScopeHash' => str_repeat('a', 64),
            'scopeBindingDigest' => str_repeat('b', 64),
            'snapshotRef' => (new SnapshotRef(
                $tenantId,
                new KbId('01JKB000000000000000000001'),
                new SnapshotId('01JSN000000000000000000001'),
                new Fingerprint(str_repeat('8', 64)),
            ))->toWire(),
            'fragments' => [$fragment],
            'totalBytes' => strlen($text),
        ]);
        $digest = HashDomain::EvidenceSet->digest($wireWithoutDigest);

        return new EvidenceSet($wireWithoutDigest->with('evidenceSetDigest', $digest), self::validator());
    }

    /**
     * @param list<SourceVersionRef>|null $sourceRefs
     * @param list<array{role: string, content: string}>|null $messages
     * @param list<string>|null $citations
     * @param list<array{role: string, content: string}> $teacherMessages
     * @param list<array{role: string, content: string}> $studentMessages
     * @param array<string, bool|int|string> $lockedInvariants
     * @param list<Tombstone> $tombstones
     */
    public static function makeTrace(
        ?Ulid $traceId = null,
        ?array $sourceRefs = null,
        ?array $messages = null,
        ?array $citations = null,
        bool $hostOutcomeVerified = true,
        array $teacherMessages = [],
        array $studentMessages = [],
        ?string $targetResponse = null,
        array $lockedInvariants = [],
        ?Sha256 $promptEnvelopeDigest = null,
        DisclosureMode $disclosureMode = DisclosureMode::VerbatimAllowed,
        string $provenanceKind = 'verbatim',
        array $tombstones = [],
    ): TrainingTraceRecord {
        $scope = self::makeScope();
        $evidence = self::makeEvidenceSet($scope->tenantId, $provenanceKind);
        $aliasMap = CitationAliasMap::fromEvidenceSet($evidence, self::validator());
        $snapshot = new SnapshotRef(
            $scope->tenantId,
            $scope->kbId,
            new SnapshotId('01JSN000000000000000000001'),
            new Fingerprint(str_repeat('8', 64)),
        );

        return new TrainingTraceRecord(
            traceId: $traceId ?? new Ulid('01JTR000000000000000000001'),
            scope: $scope,
            layerStackDigest: new Sha256(str_repeat('3', 64)),
            snapshotRefs: [$snapshot],
            sourceRefs: $sourceRefs ?? [self::makeSourceRef()],
            evidenceSet: $evidence,
            citationAliasMap: $aliasMap,
            messages: $messages ?? [
                ['role' => 'system', 'content' => '[SYSTEM]'],
                ['role' => 'user', 'content' => '[#1] Verified physics fragment.'],
                ['role' => 'assistant', 'content' => 'Confirmed [#1].'],
            ],
            hostOutcomeVerified: $hostOutcomeVerified,
            citations: $citations ?? ['#1'],
            teacherMessages: $teacherMessages,
            studentMessages: $studentMessages,
            targetResponse: $targetResponse,
            lockedInvariants: $lockedInvariants,
            promptEnvelopeDigest: $promptEnvelopeDigest,
            disclosureMode: $disclosureMode,
            tombstones: $tombstones,
        );
    }

    public static function allowAccessPolicy(Scope $scope): AccessPolicy
    {
        return self::makeAccessPolicy($scope, Decision::Allow);
    }

    public static function denyAccessPolicy(Scope $scope): AccessPolicy
    {
        return self::makeAccessPolicy($scope, Decision::Deny);
    }

    private static function makeAccessPolicy(Scope $scope, Decision $decision): AccessPolicy
    {
        return new class ($scope, $decision) implements AccessPolicy {
            public function __construct(
                private Scope $scope,
                private Decision $decision,
            ) {
            }

            public function authorizeQuery(Scope $scope): QueryGrant
            {
                throw new \LogicException('Not used in training export');
            }

            public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt
            {
                $binding = new JsonObject([
                    'tenantPolicyEpoch' => '1',
                    'kbPolicyEpoch' => '1',
                    'sources' => [],
                ]);
                $withoutDigest = new JsonObject([
                    'hostScopeHash' => $scope->hostScopeHash->value,
                    'operation' => $operation->value,
                    'decision' => $this->decision->value,
                    'maxDisclosureMode' => DisclosureMode::VerbatimAllowed->value,
                    'resourceRefs' => [$resource->toWire()],
                    'binding' => $binding,
                    'ownerRevision' => null,
                    'checkedAt' => '2026-11-27T12:00:00Z',
                    'expiresAt' => '2026-11-27T12:05:00Z',
                ]);
                $digest = HashDomain::DecisionReceipt->digest($withoutDigest);

                return new DecisionReceipt($withoutDigest->with('receiptDigest', $digest), TrainingTestSupport::validator());
            }
        };
    }

    public static function allowRightsPolicy(): TrainingRightsPolicy
    {
        return self::callbackRightsPolicy(static fn (): TrainingRightsDecision => TrainingRightsDecision::allowed());
    }

    /** @param callable(Scope, \KnowKit\Domain\ExactSourceRef): TrainingRightsDecision $fn */
    public static function callbackRightsPolicy(callable $fn): TrainingRightsPolicy
    {
        return new class ($fn) implements TrainingRightsPolicy {
            /** @var \Closure(Scope, \KnowKit\Domain\ExactSourceRef): TrainingRightsDecision */
            private \Closure $fn;

            /** @param callable(Scope, \KnowKit\Domain\ExactSourceRef): TrainingRightsDecision $fn */
            public function __construct(callable $fn)
            {
                $this->fn = $fn(...);
            }

            public function decide(Scope $scope, \KnowKit\Domain\ExactSourceRef $source): TrainingRightsDecision
            {
                return ($this->fn)($scope, $source);
            }
        };
    }

    public static function allowDependencyStore(): DependencyStore
    {
        return self::denyDependencyStore(Decision::Allow, new NodeRefs(self::makeScope()->tenantId, []));
    }

    public static function denyDependencyStore(Decision $decision, NodeRefs $blocked): DependencyStore
    {
        return new class ($decision, $blocked) implements DependencyStore {
            public function __construct(
                private Decision $decision,
                private NodeRefs $blocked,
            ) {
            }

            public function register(\KnowKit\Domain\Node $node, \KnowKit\Domain\Parents $parents): void
            {
            }

            public function checkAncestors(NodeRefs $refs): UsabilityReport
            {
                return new UsabilityReport($this->decision, $this->blocked);
            }

            public function recordUsage(\KnowKit\Domain\Usage $usage): void
            {
            }

            public function invalidate(\KnowKit\Domain\LifecycleEvent $event): void
            {
            }
        };
    }
}
