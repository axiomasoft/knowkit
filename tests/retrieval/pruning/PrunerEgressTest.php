<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Pruning;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Retrieval\Pruning\PolicyCheckedContextPruner;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\Chunk;
use KnowKit\Domain\ChunkId;
use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\ChunkSetId;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\DecisionReceipt;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\DocumentId;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\EffectiveProfile;
use KnowKit\Domain\EgressRequest;
use KnowKit\Domain\ExactSourceRef;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\IdempotencyKey;
use KnowKit\Domain\IrDigest;
use KnowKit\Domain\KbId;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ParserSignature;
use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Pruning\PrunedSubspan;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Domain\Query;
use KnowKit\Domain\QueryBinding;
use KnowKit\Domain\QueryGrant;
use KnowKit\Domain\ResourceRef;
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
use KnowKit\Domain\UtcInstant;
use KnowKit\Port\AccessPolicy;
use KnowKit\Port\Clock;
use KnowKit\Port\ContextPruner;
use KnowKit\Port\EgressPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('retrieval:pruning')]
final class PrunerEgressTest extends TestCase
{
    private function realValidator(): ContractValidator
    {
        $schemas = [];
        $files = glob(dirname(__DIR__, 3) . '/schemas/*.schema.json') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $schema = StrictJson::decode((string) file_get_contents($file), 4194304);
            self::assertInstanceOf(JsonObject::class, $schema);
            $schemas['urn:knowkit:schema:' . basename($file, '.schema.json') . ':1.0'] = $schema;
        }

        return new ContractValidator(new SchemaSet($schemas));
    }

    private function permissiveValidator(): ContractValidator
    {
        $ids = [
            'urn:knowkit:schema:egress-request:1.0',
            'urn:knowkit:schema:decision-receipt:1.0',
            'urn:knowkit:schema:query-grant:1.0',
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

    /**
     * @return array{0: AccessContext, 1: QueryBinding, 2: PrunableUnit, 3: PruningPolicy, 4: JsonObject}
     */
    private function fixture(): array
    {
        $scope = new Scope(
            new TenantId('01JSC420000000000000000000'),
            new KbId('01JSKB00000000000000000001'),
            new ActorId('01JSAC00000000000000000001'),
            'test.egress',
            new Decimal('1'),
            new Sha256(str_repeat('d', 64)),
        );
        $host = new class ($scope) implements HostIdentity {
            public function __construct(private Scope $scope) {}
            public function current(): ?PolicyContext { return new PolicyContext($this->scope, 'test-host'); }
        };
        $context = AccessContext::fromHost($host);
        $binding = new JsonObject(['kbPolicyEpoch' => '1']);
        $query = new QueryBinding(
            new Query('импульс'),
            new Query('импульс'),
            'rev1',
            new EffectiveProfile('test', '1.0', []),
            SelectionLimits::profile('compact'),
        );

        $text = 'Закон сохранения импульса.';
        $revision = new DocumentRevisionRef(
            new ExactSourceRef(
                $scope->tenantId,
                new SourceId('01JSSR00000000000000000001'),
                new SourceVersionId('01JSSV00000000000000000001'),
                new SourceDigest(str_repeat('a', 64)),
            ),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(str_repeat('b', 64)),
            new ParserSignature('md-parser', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );
        $chunk = new Chunk(
            new ChunkRef($revision, new ChunkSetId('01JSCS00000000000000000001'), new ChunkId('01JSCK00000000000000000001'), new Sha256(hash('sha256', $text))),
            new DocumentId('01JSD000000000000000000001'),
            [new Span(TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, strlen($text)), new Sha256(hash('sha256', $text)))],
            $text,
        );

        return [
            $context,
            $query,
            new PrunableUnit($chunk),
            new PruningPolicy(300, true, false, 0, 1024),
            $binding,
        ];
    }

    public function testDenyAllBlocksBeforeAnyTransportCall(): void
    {
        [$context, $query, $unit, $policy, $binding] = $this->fixture();
        $validator = $this->permissiveValidator();
        $clock = new class implements Clock {
            public function now(): UtcInstant { return new UtcInstant('2026-11-27T10:00:00Z'); }
        };
        $allowAccess = new class ($validator, $binding) implements AccessPolicy {
            public function __construct(private ContractValidator $v, private JsonObject $b) {}
            public function authorizeQuery(Scope $scope): QueryGrant {
                return new QueryGrant(new JsonObject(['decision' => 'allow', 'policyBindingDigest' => HashDomain::PolicyBinding->digest($this->b)]), $this->v);
            }
            public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt {
                $wire = new JsonObject([
                    'decision' => 'Allow',
                    'hostScopeHash' => $scope->hostScopeHash->value,
                    'resourceRefs' => [$resource->toWire()],
                    'operation' => $operation->value,
                    'ownerRevision' => 'rev1',
                    'checkedAt' => '2026-11-27T10:00:00Z',
                    'expiresAt' => '2026-11-27T11:00:00Z',
                    'binding' => $this->b,
                ]);
                return new DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), $this->v);
            }
        };
        $denyEgress = new class ($validator, $binding) implements EgressPolicy {
            public function __construct(private ContractValidator $v, private JsonObject $b) {}
            public function mayEgress(Scope $scope, EgressRequest $request): DecisionReceipt {
                $items = $request->toWire()->get('items');
                $ref = $items[1]->get('sourceVersionRefs')[0];
                $resource = new ResourceRef(new TenantId($ref->get('tenantId')), 'source_version', new SourceVersionId($ref->get('sourceVersionId')));
                $wire = new JsonObject([
                    'decision' => 'Deny',
                    'hostScopeHash' => $scope->hostScopeHash->value,
                    'resourceRefs' => [$resource->toWire()],
                    'operation' => $request->toWire()->get('operation'),
                    'ownerRevision' => 'rev1',
                    'checkedAt' => '2026-11-27T10:00:00Z',
                    'expiresAt' => '2026-11-27T11:00:00Z',
                    'binding' => $this->b,
                ]);
                return new DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), $this->v);
            }
        };

        $calls = 0;
        $inner = new class ($calls) implements ContextPruner {
            public function __construct(private int &$calls) {}
            public function prune(QueryBinding $query, array $units, PruningPolicy $policy): array {
                $this->calls++;
                return [];
            }
        };

        $pruner = new PolicyCheckedContextPruner(
            $inner,
            new PolicyChecks($allowAccess, $denyEgress, $clock),
            $validator,
            new JsonObject(['providerId' => 'ext', 'accountId' => 'acc', 'region' => 'eu-central-1']),
        );

        try {
            $pruner->prune($context, $query, [$unit], $policy, static fn (): JsonObject => $binding);
            self::fail('Expected egress denial');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Denied, $e->outcome);
        }

        self::assertSame(0, $calls);
    }

    public function testAllowForwardsQueryAndEvidenceManifestAndCallsInnerPruner(): void
    {
        [$context, $query, $unit, $policy, $binding] = $this->fixture();
        $validator = $this->permissiveValidator();
        $clock = new class implements Clock {
            public function now(): UtcInstant { return new UtcInstant('2026-11-27T10:00:00Z'); }
        };
        $allowAccess = new class ($validator, $binding) implements AccessPolicy {
            public function __construct(private ContractValidator $v, private JsonObject $b) {}
            public function authorizeQuery(Scope $scope): QueryGrant {
                return new QueryGrant(new JsonObject(['decision' => 'allow', 'policyBindingDigest' => HashDomain::PolicyBinding->digest($this->b)]), $this->v);
            }
            public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt {
                $wire = new JsonObject([
                    'decision' => 'Allow',
                    'hostScopeHash' => $scope->hostScopeHash->value,
                    'resourceRefs' => [$resource->toWire()],
                    'operation' => $operation->value,
                    'ownerRevision' => 'rev1',
                    'checkedAt' => '2026-11-27T10:00:00Z',
                    'expiresAt' => '2026-11-27T11:00:00Z',
                    'binding' => $this->b,
                ]);
                return new DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), $this->v);
            }
        };
        $observedRequest = null;
        $allowEgress = new class ($validator, $binding, $observedRequest) implements EgressPolicy {
            public function __construct(private ContractValidator $v, private JsonObject $b, private ?EgressRequest &$obs) {}
            public function mayEgress(Scope $scope, EgressRequest $request): DecisionReceipt {
                $this->obs = $request;
                $items = $request->toWire()->get('items');
                $ref = $items[1]->get('sourceVersionRefs')[0];
                $resource = new ResourceRef(new TenantId($ref->get('tenantId')), 'source_version', new SourceVersionId($ref->get('sourceVersionId')));
                $wire = new JsonObject([
                    'decision' => 'Allow',
                    'hostScopeHash' => $scope->hostScopeHash->value,
                    'resourceRefs' => [$resource->toWire()],
                    'operation' => $request->toWire()->get('operation'),
                    'ownerRevision' => 'rev1',
                    'checkedAt' => '2026-11-27T10:00:00Z',
                    'expiresAt' => '2026-11-27T11:00:00Z',
                    'binding' => $this->b,
                ]);
                return new DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), $this->v);
            }
        };

        $calls = 0;
        $inner = new class ($calls) implements ContextPruner {
            public function __construct(private int &$calls) {}
            public function prune(QueryBinding $query, array $units, PruningPolicy $policy): array {
                $this->calls++;
                return array_map(static fn (PrunableUnit $u): PrunedSelection => new PrunedSelection($u->chunkRef(), [new PrunedSubspan(0, strlen($u->text()), 1000)]), $units);
            }
        };

        $pruner = new PolicyCheckedContextPruner(
            $inner,
            new PolicyChecks($allowAccess, $allowEgress, $clock),
            $validator,
            new JsonObject(['providerId' => 'ext', 'accountId' => 'acc', 'region' => 'eu-central-1']),
        );

        // Duplicate identical unit: egress manifest must deduplicate it.
        $result = $pruner->prune($context, $query, [$unit, $unit], $policy, static fn (): JsonObject => $binding);
        self::assertSame(1, $calls);
        self::assertCount(2, $result);
        self::assertInstanceOf(EgressRequest::class, $observedRequest);
        $items = $observedRequest->toWire()->get('items');
        self::assertCount(2, $items);
        self::assertSame('QUERY', $items[0]->get('category'));
        self::assertSame('EVIDENCE', $items[1]->get('category'));
        self::assertCount(1, $items[1]->get('sourceVersionRefs'));
        self::assertSame([], $this->realValidator()->validateValue($observedRequest->toWire(), 'urn:knowkit:schema:egress-request:1.0'));
    }
}
