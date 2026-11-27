<?php

declare(strict_types=1);

namespace KnowKit\Tests\Distill;

use KnowKit\Application\Access\{AccessContext, DenyAll, HostIdentity, PolicyChecks, ReceiptFactory};
use KnowKit\Distill\Application\{ConceptEdgeExtractor, R0OutlineDistiller, R1DigestDistiller, R2PropositionDistiller};
use KnowKit\Distill\Domain\{DistillOutput, DistillProfile};
use KnowKit\Distill\Testing\ScriptedDistillTransport;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\{
    ActorId,
    ChunkId,
    Decision,
    DecisionReceipt,
    Decimal,
    DocumentId,
    DocumentRevisionId,
    DocumentRevisionRef,
    DomainFailure,
    EgressRequest,
    ExactSourceRef,
    Fingerprint,
    IrDigest,
    IrDocument,
    KbId,
    Operation,
    Outcome,
    ParserSignature,
    QueryGrant,
    ResourceRef,
    Scope,
    Sha256,
    SourceDigest,
    SourceId,
    SourceVersionId,
    TenantId,
    Ulid,
    UtcInstant,
};
use KnowKit\Port\{AccessPolicy, Clock, EgressPolicy};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('distill:contracts')]
final class DistillEgressGateTest extends TestCase
{
    public function testDenyAllPerformsZeroTransportCallsAcrossAllFourDistillers(): void
    {
        $this->assertZeroCallsForDecision('Deny');
    }

    public function testUnknownDecisionPerformsZeroTransportCallsAcrossAllFourDistillers(): void
    {
        $this->assertZeroCallsForDecision('Unknown');
    }

    private function assertZeroCallsForDecision(string $decision): void
    {
        [$validator, $policy, $context, $binding, $revision, $ir, $profile] = $this->fixture($decision);
        $transport = new ScriptedDistillTransport($profile, [
            new DistillOutput(true, '{"nodes":[]}', 10, 5),
        ]);

        $expectedOutcome = $decision === 'Deny' ? Outcome::Denied : Outcome::Unknown;

        $callers = [
            fn () => (new R0OutlineDistiller($policy, $validator, $transport))->distill($context, $ir, $revision, $profile, static fn (): JsonObject => $binding),
            fn () => (new R1DigestDistiller($policy, $validator, $transport))->distill($context, $ir, $revision, $profile, static fn (): JsonObject => $binding),
            fn () => (new R2PropositionDistiller($policy, $validator, $transport))->distill($context, $ir, $revision, $profile, static fn (): JsonObject => $binding),
            fn () => (new ConceptEdgeExtractor($policy, $validator, $transport))->extract(
                $context,
                $revision->sourceRef->tenantId,
                new Ulid('01JSKB00000000000000000001'),
                new Ulid('01JSSN00000000000000000001'),
                $revision,
                [['chunkId' => new ChunkId('01JSC000000000000000000001'), 'text' => 'Chunk text.']],
                $profile,
                static fn (): JsonObject => $binding,
            ),
        ];

        foreach ($callers as $caller) {
            try {
                $caller();
                self::fail('Expected PolicyChecks::transmit to block transport call');
            } catch (DomainFailure $e) {
                self::assertSame($expectedOutcome, $e->outcome);
            }
        }

        self::assertSame(0, $transport->sendCount());
    }

    /** @return array{0: ContractValidator, 1: PolicyChecks, 2: AccessContext, 3: JsonObject, 4: DocumentRevisionRef, 5: IrDocument, 6: DistillProfile} */
    private function fixture(string $decision): array
    {
        $schemas = [];
        $files = glob(dirname(__DIR__, 2) . '/schemas/*.schema.json') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $schema = StrictJson::decode((string) file_get_contents($file), 4194304);
            self::assertInstanceOf(JsonObject::class, $schema);
            $schemas['urn:knowkit:schema:' . basename($file, '.schema.json') . ':1.0'] = $schema;
        }
        $validator = new ContractValidator(new SchemaSet($schemas));

        $tenant = new TenantId('01JSC420000000000000000000');
        $kb = new KbId('01JSKB00000000000000000001');
        $scope = new Scope($tenant, $kb, new ActorId('01JSAC00000000000000000001'), 'distill.run', new Decimal('1'), new Sha256(str_repeat('f', 64)));
        $binding = new JsonObject(['tenantPolicyEpoch' => '0', 'kbPolicyEpoch' => '1', 'sources' => []]);
        $clock = new class implements Clock {
            public function now(): UtcInstant { return new UtcInstant('2026-11-27T10:00:00Z'); }
        };

        $receipts = new ReceiptFactory($validator, $clock);
        $access = new class ($validator, $receipts, $binding) implements AccessPolicy {
            public function __construct(private ContractValidator $v, private ReceiptFactory $r, private JsonObject $b) {}
            public function authorizeQuery(Scope $scope): QueryGrant {
                return new QueryGrant(new JsonObject(['decision' => 'allow', 'policyBindingDigest' => HashDomain::PolicyBinding->digest($this->b)]), $this->v);
            }
            public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt {
                return $this->r->issue(Decision::Allow, $scope, [$resource], $operation, $this->b, 'rev1');
            }
        };

        $egress = $decision === 'Deny'
            ? new DenyAll($receipts)
            : new class ($receipts, $binding) implements EgressPolicy {
                public function __construct(private ReceiptFactory $r, private JsonObject $b) {}
                public function mayEgress(Scope $scope, EgressRequest $request): DecisionReceipt {
                    $ref = $request->toWire()->get('items')[0]->get('sourceVersionRefs')[0];
                    $resource = new ResourceRef(new TenantId($ref->get('tenantId')), 'source_version', new SourceVersionId($ref->get('sourceVersionId')));
                    return $this->r->issue(Decision::Unknown, $scope, [$resource], Operation::from($request->toWire()->get('operation')), $this->b, 'rev1');
                }
            };

        $policy = new PolicyChecks($access, $egress, $clock);
        $context = AccessContext::fromHost(new class ($scope) implements HostIdentity {
            public function __construct(private Scope $s) {}
            public function current(): ?PolicyContext { return new PolicyContext($this->s, 'distill-worker'); }
        });

        $revision = new DocumentRevisionRef(
            new ExactSourceRef($tenant, new SourceId('01JSSR00000000000000000001'), new SourceVersionId('01JSSV00000000000000000001'), new SourceDigest(str_repeat('a', 64))),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(str_repeat('b', 64)),
            new ParserSignature('pdf-parser', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );

        $text = 'Protected document content.';
        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => $tenant->value,
            'sourceVersionId' => $revision->sourceRef->sourceVersionId->value,
            'parserSignature' => ['name' => 'pdf-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [['segmentId' => 'seg1', 'text' => $text, 'textDigest' => hash('sha256', $text), 'status' => 'ok']],
            'blocks' => [[
                'blockId' => 'b1',
                'kind' => 'text',
                'parentId' => null,
                'order' => 0,
                'origin' => 'source',
                'spans' => [['kind' => 'segment', 'segmentId' => 'seg1', 'blockId' => 'b1', 'byteStart' => 0, 'byteEnd' => strlen($text)]],
                'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
            ]],
            'assets' => [],
            'coverage' => [
                'state' => 'complete',
                'counts' => ['expected' => 1, 'processed' => 1, 'empty' => 0, 'failed' => 0, 'skipped' => 0],
                'missingFeatures' => [],
                'diagnostics' => [],
            ],
        ], JSON_THROW_ON_ERROR));
        self::assertInstanceOf(JsonObject::class, $irWire);
        $ir = new IrDocument($irWire, $validator);

        $profile = new DistillProfile('openai', 'acc1', 'us-east-1', 'gpt-test', 'cl100k', production: false);

        return [$validator, $policy, $context, $binding, $revision, $ir, $profile];
    }
}
