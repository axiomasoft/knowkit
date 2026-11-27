<?php

declare(strict_types=1);

namespace KnowKit\Tests\Distill;

use KnowKit\Application\Access\{AccessContext, HostIdentity, PolicyChecks, ReceiptFactory};
use KnowKit\Distill\Application\{ConceptEdgeExtractor, DistillationOrchestrator, R0OutlineDistiller, R2PropositionDistiller};
use KnowKit\Distill\Domain\{DistillJobKinds, DistillOutput, DistillProfile, DistillRequest};
use KnowKit\Distill\Testing\ScriptedDistillTransport;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, Jcs, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\{
    ActorId,
    AttemptId,
    Checkpoint,
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
    IdempotencyKey,
    IrDigest,
    IrDocument,
    JobCommand,
    JobId,
    JobResult,
    JobType,
    KbId,
    Lease,
    Operation,
    Outcome,
    ParserSignature,
    QueryGrant,
    ResourceId,
    ResourceRef,
    Scope,
    Sha256,
    SourceDigest,
    SourceId,
    SourceVersionId,
    SourceVersionRef,
    TenantId,
    Ulid,
    UtcInstant,
    WorkerId,
};
use KnowKit\Port\{AccessPolicy, Clock, EgressPolicy, JobQueue};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('distill:contracts')]
final class DistillContractsTest extends TestCase
{
    public function testDistillJobKindsExposeFourSnakeCaseTypes(): void
    {
        $all = DistillJobKinds::all();
        self::assertCount(4, $all);
        self::assertSame(
            ['distill_r0_outline', 'distill_r1_digest', 'distill_r2_propositions', 'extract_concept_edges'],
            array_map(static fn (JobType $t): string => $t->value, $all),
        );
        self::assertTrue(DistillJobKinds::isDistillJob(DistillJobKinds::r0Outline()));
        self::assertFalse(DistillJobKinds::isDistillJob(new JobType('ingest_pdf')));
    }

    public function testScriptedTransportRejectsProductionProfile(): void
    {
        $prodProfile = new DistillProfile('openai', 'acc1', 'us-east-1', 'gpt-test', 'cl100k', production: true);

        $this->expectException(\InvalidArgumentException::class);
        new ScriptedDistillTransport($prodProfile, []);
    }

    public function testDistillProfileUsesIntegerOnlyBpsAndCanonicalDigest(): void
    {
        $profile = new DistillProfile('openai', 'acc1', 'us-east-1', 'gpt-test', 'cl100k', production: false);
        self::assertSame(200, $profile->r0MaxRatioBps);
        self::assertSame(1000, $profile->r1MaxRatioBps);
        self::assertSame(2200, $profile->r2MaxRatioBps);
        self::assertSame(64, strlen($profile->digest()->value));
    }

    public function testR0OutlineAndR2PropositionsDistillersEnrichAndBindR3Spans(): void
    {
        [$validator, $policy, $context, $binding, $revision, $ir, $profile] = $this->fixture();

        $r0Payload = json_encode([
            'nodes' => [
                ['nodeId' => 'n1', 'title' => 'Overview of Mechanics', 'terms' => ['mechanics', 'energy']],
            ],
        ], JSON_THROW_ON_ERROR);
        $r0Transport = new ScriptedDistillTransport($profile, [new DistillOutput(true, $r0Payload, 50, 20)]);
        $r0 = (new R0OutlineDistiller($policy, $validator, $r0Transport))->distill(
            $context,
            $ir,
            $revision,
            $profile,
            static fn (): JsonObject => $binding,
        );
        self::assertNotEmpty($r0->nodes);
        self::assertSame(1, $r0Transport->sendCount());

        $r2Payload = json_encode([
            'propositions' => [
                ['propositionId' => 'p1', 'statement' => 'Context: Physics. Conservation of energy holds.', 'blockIds' => ['b1']],
            ],
        ], JSON_THROW_ON_ERROR);
        $r2Transport = new ScriptedDistillTransport($profile, [new DistillOutput(true, $r2Payload, 80, 30)]);
        $r2 = (new R2PropositionDistiller($policy, $validator, $r2Transport))->distill(
            $context,
            $ir,
            $revision,
            $profile,
            static fn (): JsonObject => $binding,
        );
        self::assertCount(1, $r2->propositions);
        self::assertNotEmpty($r2->propositions[0]->supportingSpans);
        self::assertSame(1, $r2Transport->sendCount());
    }

    public function testConceptEdgeExtractorSortsAndValidatesAgainstSchema(): void
    {
        [$validator, $policy, $context, $binding, $revision, , $profile] = $this->fixture();
        $c1 = new ChunkId('01JSC000000000000000000001');
        $c2 = new ChunkId('01JSC000000000000000000002');
        $c3 = new ChunkId('01JSC000000000000000000003');

        $edgeJson = json_encode([
            'edges' => [
                ['fromChunkId' => $c2->value, 'toChunkId' => $c3->value, 'relation' => 'defines', 'weightBps' => 8000, 'origin' => 'generated'],
                ['fromChunkId' => $c1->value, 'toChunkId' => $c2->value, 'relation' => 'prerequisite_of', 'weightBps' => 9500, 'origin' => 'generated'],
            ],
        ], JSON_THROW_ON_ERROR);
        $transport = new ScriptedDistillTransport($profile, [new DistillOutput(true, $edgeJson, 60, 40)]);
        $extractor = new ConceptEdgeExtractor($policy, $validator, $transport);

        $wire = $extractor->extract(
            $context,
            $revision->sourceRef->tenantId,
            new Ulid('01JSKB00000000000000000001'),
            new Ulid('01JSSN00000000000000000001'),
            $revision,
            [
                ['chunkId' => $c1, 'text' => 'Momentum is mass times velocity.'],
                ['chunkId' => $c2, 'text' => 'Impulse equals change in momentum.'],
                ['chunkId' => $c3, 'text' => 'Elastic collisions preserve kinetic energy.'],
            ],
            $profile,
            static fn (): JsonObject => $binding,
        );

        self::assertSame([], $validator->validateValue($wire, 'urn:knowkit:schema:concept-edges:1.0'));
        $edges = $wire->get('edges');
        self::assertCount(2, $edges);
        self::assertSame($c1->value, $edges[0]->get('fromChunkId'));
    }

    public function testOrchestratorCommitsFencedCheckpointAndRejectsStaleLease(): void
    {
        [, , $context, , $revision] = $this->fixture();
        $lease = new Lease(
            $context->scope(),
            new JobId('01JSJB00000000000000000001'),
            new AttemptId('01JSAT00000000000000000001'),
            new SourceVersionRef($revision->sourceRef->tenantId, $revision->sourceRef->sourceId, $revision->sourceRef->sourceVersionId, $revision->sourceRef->sourceDigest),
            null,
            new Decimal('1'),
            str_repeat('t', 32),
            new UtcInstant('2026-11-27T11:00:00Z'),
            new UtcInstant('2026-11-27T12:00:00Z'),
            new Decimal('1'),
        );

        $checkpoints = [];
        $completed = [];
        $jobs = new class ($checkpoints, $completed) implements JobQueue {
            public bool $allowWrites = true;
            /** @param list<Checkpoint> $checkpoints @param list<JobResult> $completed */
            public function __construct(private array &$checkpoints, private array &$completed) {}
            public function enqueue(JobCommand $command, IdempotencyKey $key): JobId { throw new \BadMethodCallException(); }
            public function lease(JobType $type, WorkerId $worker): ?Lease { return null; }
            public function heartbeat(Lease $lease): bool { return $this->allowWrites; }
            public function checkpoint(Lease $lease, Checkpoint $checkpoint): bool {
                if (!$this->allowWrites) { return false; }
                $this->checkpoints[] = $checkpoint;
                return true;
            }
            public function complete(Lease $lease, JobResult $result): bool {
                if (!$this->allowWrites) { return false; }
                $this->completed[] = $result;
                return true;
            }
            public function cancel(Scope $scope, JobId $jobId): bool { return false; }
        };

        $orchestrator = new DistillationOrchestrator($jobs);
        $manifest = $orchestrator->executeFenced(
            $lease,
            'distill_r1_digest',
            new Sha256(str_repeat('d', 64)),
            static fn (): array => [
                'inputDigest' => new Sha256(str_repeat('1', 64)),
                'outputDigest' => new Sha256(str_repeat('2', 64)),
                'resourceId' => new ResourceId('01JSRS00000000000000000001'),
                'byteLength' => 512,
            ],
        );

        self::assertSame('job-checkpoint-1.0', $manifest->toWire()->get('schemaVersion'));
        self::assertCount(1, $checkpoints);
        self::assertCount(1, $completed);

        $jobs->allowWrites = false;
        try {
            $orchestrator->executeFenced(
                $lease,
                'distill_r1_digest',
                new Sha256(str_repeat('d', 64)),
                static fn (): array => [
                    'inputDigest' => new Sha256(str_repeat('1', 64)),
                    'outputDigest' => new Sha256(str_repeat('2', 64)),
                    'resourceId' => new ResourceId('01JSRS00000000000000000001'),
                    'byteLength' => 512,
                ],
            );
            self::fail('Expected stale_lease failure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Denied, $e->outcome);
            self::assertSame('stale_lease', $e->errorCode);
        }
    }

    /** @return array{0: ContractValidator, 1: PolicyChecks, 2: AccessContext, 3: JsonObject, 4: DocumentRevisionRef, 5: IrDocument, 6: DistillProfile} */
    private function fixture(): array
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
        $egress = new class ($receipts, $binding) implements EgressPolicy {
            public function __construct(private ReceiptFactory $r, private JsonObject $b) {}
            public function mayEgress(Scope $scope, EgressRequest $request): DecisionReceipt {
                $item = $request->toWire()->get('items')[0];
                $ref = $item->get('sourceVersionRefs')[0];
                $resource = new ResourceRef(new TenantId($ref->get('tenantId')), 'source_version', new SourceVersionId($ref->get('sourceVersionId')));
                return $this->r->issue(Decision::Allow, $scope, [$resource], Operation::from($request->toWire()->get('operation')), $this->b, 'rev1');
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

        $text = 'Conservation of energy holds in closed systems.';
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
