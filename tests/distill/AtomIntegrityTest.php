<?php

declare(strict_types=1);

namespace KnowKit\Tests\Distill;

use KnowKit\Application\Access\{AccessContext, HostIdentity, PolicyChecks, ReceiptFactory};
use KnowKit\Distill\Application\{R1DigestDistiller, R2PropositionDistiller};
use KnowKit\Distill\Domain\{DistillOutput, DistillProfile};
use KnowKit\Distill\Testing\ScriptedDistillTransport;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\{
    ActorId,
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
    UtcInstant,
};
use KnowKit\Port\{AccessPolicy, Clock, EgressPolicy};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('distill:contracts')]
final class AtomIntegrityTest extends TestCase
{
    public function testR1DigestDistillerPreservesAtomsAndIgnoresLiteralAtomTrap(): void
    {
        $trapPrefix = 'Маркер @@ATOM_0@@ встретился в тексте.';
        [$validator, $policy, $context, $binding, $revision, $ir, $profile, $formula, $code] = $this->irWithAtoms($trapPrefix);

        $nonce = substr($revision->irDigest->value, 0, 8);
        $m0 = "@@KK_ATOM_{$nonce}_0@@";
        $m1 = "@@KK_ATOM_{$nonce}_1@@";

        $modelOutput = json_encode([
            'blocks' => [
                ['blockId' => 'b1', 'text' => 'Маркер @@ATOM_0@@ описан рядом с формулой ' . $m0 . ' и кодом ' . $m1 . '.'],
            ],
        ], JSON_THROW_ON_ERROR);
        $transport = new ScriptedDistillTransport($profile, [new DistillOutput(true, $modelOutput, 100, 40)]);
        $distiller = new R1DigestDistiller($policy, $validator, $transport);

        $digest = $distiller->distill($context, $ir, $revision, $profile, static fn (): JsonObject => $binding);
        $sentPrompt = $transport->recordedRequests()[0]->promptBytes;

        self::assertStringContainsString('@@ATOM_0@@', $sentPrompt);
        self::assertStringContainsString($m0, $sentPrompt);
        self::assertStringContainsString($m1, $sentPrompt);
        self::assertStringNotContainsString($formula, $sentPrompt);
        self::assertStringNotContainsString($code, $sentPrompt);

        $restoredText = $digest->blocks[0]->text;
        self::assertStringContainsString('@@ATOM_0@@', $restoredText);
        self::assertStringContainsString($formula, $restoredText);
        self::assertStringContainsString($code, $restoredText);
        self::assertStringNotContainsString($m0, $restoredText);
    }

    public function testCollisionInSourceTextEscalatesNonceLength(): void
    {
        $irDigestHex = 'a94f0c12bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $trap = 'Literal @@KK_ATOM_a94f0c12_0@@ inside source text!';
        [$validator, $policy, $context, $binding, $revision, $ir, $profile] = $this->irWithAtoms($trap, $irDigestHex);

        $escalatedNonce = substr($irDigestHex, 0, 12);
        $m0 = "@@KK_ATOM_{$escalatedNonce}_0@@";
        $m1 = "@@KK_ATOM_{$escalatedNonce}_1@@";

        $modelOutput = json_encode([
            'blocks' => [
                ['blockId' => 'b1', 'text' => 'Escalated ' . $m0 . ' and ' . $m1],
            ],
        ], JSON_THROW_ON_ERROR);
        $transport = new ScriptedDistillTransport($profile, [new DistillOutput(true, $modelOutput, 90, 30)]);
        $distiller = new R1DigestDistiller($policy, $validator, $transport);

        $digest = $distiller->distill($context, $ir, $revision, $profile, static fn (): JsonObject => $binding);
        self::assertStringContainsString($m0, $transport->recordedRequests()[0]->promptBytes);
        self::assertCount(2, $digest->allVerbatimAtoms());
    }

    public function testMissingDuplicatedOrInventedMarkerFailsAtomIntegrity(): void
    {
        [$validator, $policy, $context, $binding, $revision, $ir, $profile] = $this->irWithAtoms('Intro');
        $nonce = substr($revision->irDigest->value, 0, 8);
        $m0 = "@@KK_ATOM_{$nonce}_0@@";
        $m1 = "@@KK_ATOM_{$nonce}_1@@";

        $badOutputs = [
            // Missing $m1
            json_encode(['blocks' => [['blockId' => 'b1', 'text' => 'Only ' . $m0]]], JSON_THROW_ON_ERROR),
            // Duplicated $m0
            json_encode(['blocks' => [['blockId' => 'b1', 'text' => $m0 . ' ' . $m0 . ' ' . $m1]]], JSON_THROW_ON_ERROR),
            // Invented $m99
            json_encode(['blocks' => [['blockId' => 'b1', 'text' => $m0 . ' ' . $m1 . " @@KK_ATOM_{$nonce}_99@@"]]], JSON_THROW_ON_ERROR),
        ];

        foreach ($badOutputs as $payload) {
            $transport = new ScriptedDistillTransport($profile, [new DistillOutput(true, $payload, 50, 20)]);
            $distiller = new R1DigestDistiller($policy, $validator, $transport);

            try {
                $distiller->distill($context, $ir, $revision, $profile, static fn (): JsonObject => $binding);
                self::fail('Expected atom_integrity_failed');
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Incompatible, $e->outcome);
                self::assertSame('atom_integrity_failed', $e->errorCode);
            }
        }
    }

    public function testR2PropositionDistillerRejectsLostAtomMarker(): void
    {
        [$validator, $policy, $context, $binding, $revision, $ir, $profile] = $this->irWithAtoms('Intro');
        $nonce = substr($revision->irDigest->value, 0, 8);
        $m0 = "@@KK_ATOM_{$nonce}_0@@";

        $payload = json_encode([
            'propositions' => [
                ['propositionId' => 'p1', 'statement' => 'Only ' . $m0, 'blockIds' => ['b1']],
            ],
        ], JSON_THROW_ON_ERROR);
        $transport = new ScriptedDistillTransport($profile, [new DistillOutput(true, $payload, 50, 20)]);
        $distiller = new R2PropositionDistiller($policy, $validator, $transport);

        try {
            $distiller->distill($context, $ir, $revision, $profile, static fn (): JsonObject => $binding);
            self::fail('Expected atom_integrity_failed');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Incompatible, $e->outcome);
            self::assertSame('atom_integrity_failed', $e->errorCode);
        }
    }

    /**
     * @return array{0: ContractValidator, 1: PolicyChecks, 2: AccessContext, 3: JsonObject, 4: DocumentRevisionRef, 5: IrDocument, 6: DistillProfile, 7: string, 8: string}
     */
    private function irWithAtoms(string $introText, ?string $irDigestHex = null): array
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
                $ref = $request->toWire()->get('items')[0]->get('sourceVersionRefs')[0];
                $resource = new ResourceRef(new TenantId($ref->get('tenantId')), 'source_version', new SourceVersionId($ref->get('sourceVersionId')));
                return $this->r->issue(Decision::Allow, $scope, [$resource], Operation::from($request->toWire()->get('operation')), $this->b, 'rev1');
            }
        };
        $policy = new PolicyChecks($access, $egress, $clock);
        $context = AccessContext::fromHost(new class ($scope) implements HostIdentity {
            public function __construct(private Scope $s) {}
            public function current(): ?PolicyContext { return new PolicyContext($this->s, 'distill-worker'); }
        });

        $irDigest = $irDigestHex ?? 'a94f0c12' . str_repeat('b', 56);
        $revision = new DocumentRevisionRef(
            new ExactSourceRef($tenant, new SourceId('01JSSR00000000000000000001'), new SourceVersionId('01JSSV00000000000000000001'), new SourceDigest(str_repeat('a', 64))),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest($irDigest),
            new ParserSignature('pdf-parser', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );

        $formula = '$$ E = mc^2 $$';
        $code = 'echo "ok";';
        $segText = $introText . "\n" . $formula . "\n" . $code;
        $iStart = 0;
        $iEnd = strlen($introText);
        $fStart = $iEnd + 1;
        $fEnd = $fStart + strlen($formula);
        $cStart = $fEnd + 1;
        $cEnd = $cStart + strlen($code);

        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => $tenant->value,
            'sourceVersionId' => $revision->sourceRef->sourceVersionId->value,
            'parserSignature' => ['name' => 'pdf-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [['segmentId' => 'seg1', 'text' => $segText, 'textDigest' => hash('sha256', $segText), 'status' => 'ok']],
            'blocks' => [
                [
                    'blockId' => 'b1',
                    'kind' => 'text',
                    'parentId' => null,
                    'order' => 0,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg1', 'blockId' => 'b1', 'byteStart' => $iStart, 'byteEnd' => $iEnd]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b2',
                    'kind' => 'formula',
                    'parentId' => null,
                    'order' => 1,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg1', 'blockId' => 'b2', 'byteStart' => $fStart, 'byteEnd' => $fEnd]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
                [
                    'blockId' => 'b3',
                    'kind' => 'code',
                    'parentId' => null,
                    'order' => 2,
                    'origin' => 'source',
                    'spans' => [['kind' => 'segment', 'segmentId' => 'seg1', 'blockId' => 'b3', 'byteStart' => $cStart, 'byteEnd' => $cEnd]],
                    'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                ],
            ],
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

        return [$validator, $policy, $context, $binding, $revision, $ir, $profile, $formula, $code];
    }
}
