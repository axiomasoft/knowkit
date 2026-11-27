<?php
declare(strict_types=1);

namespace KnowKit\Tests\Runtime\MultiInstance;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, PdoLexicalIndex, PdoSelectionCatalog};
use KnowKit\Adapter\LocalBlob\LocalBlobStore;
use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Adapter\Postgres\Catalog\{Connection, Migrate, PdoCatalogRepository, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Documents\{DocumentStorage, PdoDocumentStore, UnitStorage};
use KnowKit\Adapter\Postgres\IndexGenerations\{PdoReadBackend, PdoStagingGenerations, ResourceInventory};
use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Adapter\Postgres\Navigation\PdoNavigationStore;
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Adapter\Postgres\Publication\PdoActivation;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\{PdoCanonicalDocuments, PdoUsages};
use KnowKit\Application\Access\{AccessContext, HostIdentity, PolicyChecks, ScopedAllowPolicy};
use KnowKit\Application\Catalog\{CatalogCommands, MutationAuthorizer, SourceCommands};
use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Ingest\Text\{ParserSettings, TextParser};
use KnowKit\Application\Lifecycle\LifecycleCommands;
use KnowKit\Application\Publication\Activate\Publisher;
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\{Builder, ProjectionReadiness};
use KnowKit\Application\Retrieval\Disclosure\{CanonicalBuffer, Disclosures};
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Selection\{BoundedSelector, CanonicalUnits, Selections};
use KnowKit\Application\Runtime\{InstanceRegistry, KnowledgeFacade, KnowledgeInstance, SingleLocalScopeFactory};
use KnowKit\Application\SourceNavigation;
use KnowKit\Conformance\SchemaLoader;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject, StrictJson};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Port as P;
use KnowKit\Registry\{AdapterRegistry, HostProfile, ReadinessReport};
use KnowKit\Tests\Tooling\DatabaseSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/packages/core/conformance/autoload.php';
require_once dirname(__DIR__, 3) . '/examples/custom-adapter/FakeHost.php';

#[Group('runtime:multi-instance')]
final class KnowledgeFacadeTest extends TestCase
{
    public function testSingleLocalFullLifecycleOnPostgres17(): void
    {
        $validator = SchemaLoader::load(dirname(__DIR__, 3) . '/schemas');
        $sandbox = new DatabaseSandbox();
        $sandbox->run(function (DatabaseSandbox $sb) use ($validator): void {
            (new Migrate($sb->connection, $sb->schema))->apply(
                dirname(__DIR__, 3) . '/packages/core/migrations/postgres/001_metadata.sql',
            );
            $this->withUnprivilegedRole($sb, function (DatabaseSandbox $sb) use ($validator): void {
                $blobDir = sys_get_temp_dir() . '/knowkit-w1-single-' . bin2hex(random_bytes(6));
                mkdir($blobDir, 0700, true);
                try {
                    $instanceId = D\InstanceId::fromString('support_faq');
                    $scopeFactory = new SingleLocalScopeFactory($instanceId, 'support_kb', 'support.answer');
                    $scope = $scopeFactory->scope(policyEpoch: 0);

                    $registry = new InstanceRegistry();
                    $profile = $this->profile();
                    $harness = $this->buildRuntime($sb, $blobDir, $validator, $instanceId, $scope);
                    $registry->register(
                        id: $instanceId,
                        profile: $profile,
                        preset: D\IsolationPreset::SingleLocal,
                        factory: static fn (): KnowledgeInstance => new KnowledgeInstance(
                            id: $instanceId,
                            profile: $profile,
                            preset: D\IsolationPreset::SingleLocal,
                            facade: $harness['facade'],
                            readiness: new ReadinessReport(true, true, true, true, false, false),
                        ),
                    );
                    $registry->boot();

                    $facade = $registry->get($instanceId)->facade;

                    // 1. Ingest -> Publish
                    $receipt = $facade->ingestText(
                        scope: $scope,
                        sourceKey: 'faq/refund-policy.md',
                        utf8: "# Refund Policy\nRefunds are issued within 14 days with a valid order number.\n",
                    );
                    self::assertInstanceOf(D\PublishReceipt::class, $receipt);

                    // 2. Search -> FreshDisclosure (using current host scope after catalog epoch advance)
                    $activeScope = $harness['host']->current()->scope;
                    $outcome = $facade->searchAndDisclose($activeScope, new D\Query('refund order'));
                    self::assertSame(D\Outcome::Success, $outcome->result->outcome, (string) $outcome->result->errorCode);
                    self::assertInstanceOf(D\EvidenceSet::class, $outcome->result->data);
                    $evidenceJson = $outcome->result->data->canonical();
                    self::assertStringContainsString('14 days', $evidenceJson);

                    // 3. Revoke -> Denied/Empty (no usable evidence disclosed)
                    $sourceId = $harness['lastSourceId']();
                    $facade->revoke($activeScope, $sourceId, 'source_revoked');

                    $afterRevoke = $facade->searchAndDisclose($harness['host']->current()->scope, new D\Query('refund order'));
                    self::assertNotSame(D\Outcome::Success, $afterRevoke->result->outcome);
                    self::assertNull($afterRevoke->result->data);
                } finally {
                    $this->rmTree($blobDir);
                }
            });
        });
    }

    public function testMultiInstanceIsolationInSameProcess(): void
    {
        $validator = SchemaLoader::load(dirname(__DIR__, 3) . '/schemas');
        $sandboxA = new DatabaseSandbox();
        $sandboxB = new DatabaseSandbox();

        $sandboxA->run(function (DatabaseSandbox $sbA) use ($sandboxB, $validator): void {
            $sandboxB->run(function (DatabaseSandbox $sbB) use ($sbA, $validator): void {
                $migration = dirname(__DIR__, 3) . '/packages/core/migrations/postgres/001_metadata.sql';
                (new Migrate($sbA->connection, $sbA->schema))->apply($migration);
                (new Migrate($sbB->connection, $sbB->schema))->apply($migration);

                $this->withUnprivilegedRole($sbA, function (DatabaseSandbox $sbA) use ($sbB, $validator): void {
                    $this->withUnprivilegedRole($sbB, function (DatabaseSandbox $sbB) use ($sbA, $validator): void {
                        $blobA = sys_get_temp_dir() . '/knowkit-w1-a-' . bin2hex(random_bytes(6));
                        $blobB = sys_get_temp_dir() . '/knowkit-w1-b-' . bin2hex(random_bytes(6));
                        mkdir($blobA, 0700, true);
                        mkdir($blobB, 0700, true);

                        try {
                            $idA = D\InstanceId::fromString('support_faq');
                            $idB = D\InstanceId::fromString('enterprise_kb');
                            $scopeA = (new SingleLocalScopeFactory($idA, 'shared_kb', 'support.answer'))->scope(0);
                            $scopeB = (new SingleLocalScopeFactory($idB, 'shared_kb', 'support.answer'))->scope(0);

                            $runtimeA = $this->buildRuntime($sbA, $blobA, $validator, $idA, $scopeA);
                            $runtimeB = $this->buildRuntime($sbB, $blobB, $validator, $idB, $scopeB);

                            $profile = $this->profile();
                            $registry = new InstanceRegistry();
                            $registry->register($idA, $profile, D\IsolationPreset::SingleLocal, static fn (): KnowledgeInstance => new KnowledgeInstance(
                                $idA, $profile, D\IsolationPreset::SingleLocal, $runtimeA['facade'],
                                new ReadinessReport(true, true, true, true, false, false),
                            ));
                            $registry->register($idB, $profile, D\IsolationPreset::MultiTenantStrict, static fn (): KnowledgeInstance => new KnowledgeInstance(
                                $idB, $profile, D\IsolationPreset::MultiTenantStrict, $runtimeB['facade'],
                                new ReadinessReport(true, true, true, true, false, false),
                            ));
                            $registry->boot();

                            $instA = $registry->get($idA);
                            $instB = $registry->get($idB);

                            $text = "# Shared Title\nAlpha beta gamma token isolation check.\n";
                            $instA->facade->ingestText($scopeA, 'faq/policy.md', $text);
                            $instB->facade->ingestText($scopeB, 'faq/policy.md', $text);

                            $activeScopeA = $runtimeA['host']->current()->scope;
                            $activeScopeB = $runtimeB['host']->current()->scope;

                            // Obtain SelectionToken from instance A
                            $snapshotA = $runtimeA['catalog']->resolveSnapshot(
                                $activeScopeA->tenantId,
                                $activeScopeA->kbId,
                                D\SnapshotSelector::active(),
                                new D\ProfileId(PdoLexicalIndex::PROFILE),
                            );
                            self::assertNotNull($snapshotA);
                            $boundQuery = (new Normalizer())->bind(
                                new D\Query('alpha gamma'),
                                $runtimeA['effectiveProfile'],
                                D\SelectionLimits::profile('compact'),
                            );
                            $retrievalA = $runtimeA['selections']()->retrieve(
                                AccessContext::fromHost($runtimeA['host']),
                                $boundQuery,
                                $snapshotA->ref,
                                $snapshotA->generationIds[0],
                                $runtimeA['eligible']($activeScopeA),
                                $runtimeA['binding']($activeScopeA),
                            );
                            self::assertInstanceOf(D\Selection::class, $retrievalA->result->data, (string) $retrievalA->result->errorCode);
                            self::assertNotNull($retrievalA->token);

                            $disclosureRequest = new D\DisclosureRequest(new JsonObject([
                                'selectionId' => $retrievalA->result->data->id()->value,
                                'chunkRefs' => array_map(
                                    static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(),
                                    $retrievalA->result->data->candidateRefs(),
                                ),
                                'disclosureAttemptId' => $runtimeA['ids']->next()->value,
                            ]), $validator);

                            // Presenting instance A's SelectionToken to instance B must be denied with scope_mismatch
                            $crossOutcome = $instB->facade->discloseToken(
                                $activeScopeB,
                                $retrievalA->token,
                                $boundQuery,
                                $snapshotA->ref,
                                $disclosureRequest,
                            );
                            self::assertSame(D\Outcome::Denied, $crossOutcome->result->outcome);
                            self::assertSame('scope_mismatch', $crossOutcome->result->errorCode);
                            self::assertNull($crossOutcome->result->data);

                            // Presenting instance A's scope to instance B's searchAndDisclose is also denied
                            $crossSearch = $instB->facade->searchAndDisclose($activeScopeA, new D\Query('alpha'));
                            self::assertSame(D\Outcome::Denied, $crossSearch->result->outcome);
                            self::assertSame('scope_mismatch', $crossSearch->result->errorCode);

                            // Each instance serves its own scope normally
                            $okA = $instA->facade->searchAndDisclose($activeScopeA, new D\Query('alpha'));
                            $okB = $instB->facade->searchAndDisclose($activeScopeB, new D\Query('alpha'));
                            self::assertSame(D\Outcome::Success, $okA->result->outcome);
                            self::assertSame(D\Outcome::Success, $okB->result->outcome);
                        } finally {
                            $this->rmTree($blobA);
                            $this->rmTree($blobB);
                        }
                    });
                });
            });
        });
    }

    /** @param \Closure(DatabaseSandbox): void $fn */
    private function withUnprivilegedRole(DatabaseSandbox $sb, \Closure $fn): void
    {
        $role = 'knowkit_test_' . bin2hex(random_bytes(8));
        $sb->connection->exec('CREATE ROLE ' . $role . ' NOLOGIN NOSUPERUSER');
        try {
            $sb->connection->exec('GRANT USAGE ON SCHEMA ' . $sb->schema . ' TO ' . $role);
            $sb->connection->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $sb->schema . ' TO ' . $role);
            $sb->connection->exec('SET ROLE ' . $role);
            $fn($sb);
        } finally {
            if ($sb->connection->inTransaction()) {
                $sb->connection->rollBack();
            }
            $sb->connection->exec('RESET ROLE');
            $sb->connection->exec('DROP OWNED BY ' . $role);
            $sb->connection->exec('DROP ROLE ' . $role);
        }
    }

    /**
     * @return array{
     *     facade: KnowledgeFacade,
     *     catalog: PdoCatalogRepository,
     *     selections: Selections,
     *     host: HostIdentity,
     *     ids: P\IdGenerator,
     *     effectiveProfile: D\EffectiveProfile,
     *     eligible: \Closure(D\Scope): list<D\SourceVersionId>,
     *     binding: \Closure(D\Scope): JsonObject,
     *     lastSourceId: \Closure(): D\SourceId
     * }
     */
    private function buildRuntime(
        DatabaseSandbox $sb,
        string $blobDir,
        ContractValidator $validator,
        D\InstanceId $instanceId,
        D\Scope $initialScope,
    ): array {
        $db = new Connection($sb->connection, $sb->schema);
        $tx = new PdoCatalogTransaction($db);

        $clock = new class($db) implements P\Clock {
            public function __construct(private Connection $db)
            {
            }

            public function now(): D\UtcInstant
            {
                $now = $this->db->execute('SELECT clock_timestamp()')->fetchColumn();

                return new D\UtcInstant((new \DateTimeImmutable((string) $now))
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
            }
        };

        $ids = new class implements P\IdGenerator {
            public function next(): D\Ulid
            {
                $bits = '00';
                foreach (str_split(substr(hash('sha256', bin2hex(random_bytes(32))), 0, 32)) as $digit) {
                    $bits .= str_pad(decbin((int) hexdec($digit)), 4, '0', STR_PAD_LEFT);
                }
                $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
                $id = '';
                foreach (str_split($bits, 5) as $part) {
                    $id .= $alphabet[(int) bindec($part)];
                }

                return new D\Ulid($id);
            }
        };

        $eligible = static function (D\Scope $scope) use ($db): array {
            $sql = 'SELECT DISTINCT v.id FROM ' . $db->table('source_versions') . ' v'
                . ' JOIN ' . $db->table('sources') . ' s ON s.tenant_id=v.tenant_id AND s.id=v.source_id'
                . ' JOIN ' . $db->table('source_memberships') . ' m ON m.tenant_id=s.tenant_id AND m.source_id=s.id'
                . ' JOIN ' . $db->table('source_version_state') . ' vs ON vs.tenant_id=v.tenant_id AND vs.id=v.id'
                . " WHERE v.tenant_id=? AND m.kb_id=? AND s.lifecycle_state='active' AND vs.usability='usable'"
                . ' ORDER BY v.id';

            return array_values(array_map(
                static fn (array $row): D\SourceVersionId => new D\SourceVersionId((string) $row['id']),
                $db->execute($sql, [$scope->tenantId->value, $scope->kbId->value])->fetchAll(\PDO::FETCH_ASSOC),
            ));
        };

        $binding = static function (D\Scope $scope, ?D\ResourceRef $resource = null, ?D\Operation $operation = null) use ($db): JsonObject {
            $tenant = $db->row('SELECT tenant_policy_epoch FROM ' . $db->table('tenants') . ' WHERE id=?', [$scope->tenantId->value]);
            if ($operation === null || in_array($operation, [D\Operation::Retrieve, D\Operation::Disclose, D\Operation::Navigate], true)) {
                $rows = $db->execute(
                    'SELECT s.id,s.lifecycle_epoch,s.source_policy_epoch FROM ' . $db->table('sources') . ' s'
                    . ' JOIN ' . $db->table('source_memberships') . ' m ON m.tenant_id=s.tenant_id AND m.source_id=s.id'
                    . ' WHERE s.tenant_id=? AND m.kb_id=? GROUP BY s.id,s.tenant_id ORDER BY s.id',
                    [$scope->tenantId->value, $scope->kbId->value],
                )->fetchAll(\PDO::FETCH_ASSOC);
            } else {
                $rows = [];
                if ($resource !== null && $resource->kind === 'source') {
                    $row = $db->row(
                        'SELECT id,lifecycle_epoch,source_policy_epoch FROM ' . $db->table('sources') . ' WHERE tenant_id=? AND id=?',
                        [$scope->tenantId->value, $resource->id->value],
                    );
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }
            }

            $sources = array_map(static fn (array $row): JsonObject => new JsonObject([
                'sourceId' => (string) $row['id'],
                'lifecycleEpoch' => (string) $row['lifecycle_epoch'],
                'sourcePolicyEpoch' => (string) $row['source_policy_epoch'],
            ]), $rows);

            return new JsonObject([
                'tenantPolicyEpoch' => (string) ($tenant['tenant_policy_epoch'] ?? '0'),
                'kbPolicyEpoch' => $scope->policyEpoch->value,
                'sources' => $sources,
            ]);
        };

        $policy = new ScopedAllowPolicy(
            instanceId: $instanceId,
            tenantId: $initialScope->tenantId,
            kbId: $initialScope->kbId,
            expectedHostScopeHash: $initialScope->hostScopeHash,
            clock: $clock,
            validator: $validator,
            defaultDisclosureMode: DisclosureMode::VerbatimAllowed,
            eligibleSourceVersions: $eligible,
            bindingResolver: $binding,
        );

        $egress = new class implements P\EgressPolicy {
            public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
            {
                throw new D\DomainFailure(D\Outcome::Denied, 'egress_denied');
            }
        };

        $host = new class($db, $initialScope) implements HostIdentity {
            public function __construct(private Connection $db, private D\Scope $identity)
            {
            }

            public function current(): PolicyContext
            {
                $row = $this->db->row(
                    'SELECT kb_policy_epoch FROM ' . $this->db->table('knowledge_bases') . ' WHERE tenant_id=? AND id=?',
                    [$this->identity->tenantId->value, $this->identity->kbId->value],
                );
                $scope = new D\Scope(
                    $this->identity->tenantId,
                    $this->identity->kbId,
                    $this->identity->actorId,
                    $this->identity->purpose,
                    new D\Decimal((string) ($row['kb_policy_epoch'] ?? $this->identity->policyEpoch->value)),
                    $this->identity->hostScopeHash,
                );

                return new PolicyContext($scope, 'local-owner', ['source:read']);
            }
        };

        $authority = new MutationAuthorizer($policy, $clock);
        $events = new DomainEventFactory($clock, $ids, $validator);
        $outbox = new PdoOutbox($db, $tx);
        $catalog = new PdoCatalogRepository(
            $db,
            $tx,
            $authority->requireAllow(...),
            $clock,
            $ids,
            $events->create(...),
            $outbox,
            bin2hex(random_bytes(32)),
        );
        $catalogCommands = new CatalogCommands($catalog, $tx, $authority);
        $sourceCommands = new SourceCommands(
            $catalog->registerSourceMetadata(...),
            $catalog->acceptSourceVersion(...),
            $tx,
            $authority,
        );
        $dependencies = new PdoDependencyStore($db, $tx, $initialScope->tenantId, $ids);
        $lifecycleStore = new PdoLifecycleStore(
            $db,
            $tx,
            $initialScope->tenantId,
            $clock,
            $ids,
            $validator,
            $events->create(...),
            $dependencies,
            $outbox,
            $authority->requireAllow(...),
        );
        $lifecycle = new LifecycleCommands(
            $lifecycleStore,
            $catalog,
            $tx,
            $authority,
            $catalog->acceptSourceVersion(...),
        );

        $ledger = new PdoResourceLedger($db, $tx, $initialScope->tenantId, 'local', $clock);
        $callbacks = [];
        foreach ([
            'inspectWriter', 'finalizeIdentity', 'closeWriter', 'markUncertain',
            'requireAccepted', 'listUnreferenced', 'quarantine', 'recoveryRef', 'reconcileWriter',
        ] as $method) {
            $callbacks[$method] = $ledger->$method(...);
        }
        $blobs = new LocalBlobStore(
            $initialScope->tenantId,
            $blobDir,
            'local',
            8388608,
            $callbacks,
            static function (D\BlobRef $ref) use ($initialScope): void {
                $initialScope->assertTenant($ref->tenantId);
            },
            static function (): never {
                throw new D\DomainFailure(D\Outcome::Unavailable, 'deletion_binding_required');
            },
            $ids,
            $clock,
            $validator,
        );

        $sets = [];
        $documents = [];
        $lastSourceId = null;
        $getLastSourceId = static function () use (&$lastSourceId): D\SourceId {
            return $lastSourceId ?? throw new \LogicException('No source ingested');
        };

        // Bootstrap tenant + KB row for the single-local instance
        $tx->run(function () use ($tx, $catalog, $initialScope): void {
            $tx->lockTenant($initialScope->tenantId, D\LockMode::Update);
            $txTenant = $catalog->getTenant($initialScope->tenantId);
            if ($txTenant === null) {
                $catalog->insertTenant($initialScope->tenantId, new D\Tenant($initialScope->tenantId, 'ready'));
            }
        });
        $catalogCommands->createKnowledgeBase(
            $initialScope,
            new D\CreateKB(
                $initialScope->kbId,
                'kb-' . $instanceId->value,
                'local-owner:1',
                new D\ProfileId('postgres-fts-simple-v1'),
                new D\CoveragePolicy(),
            ),
            new D\MutationContext(new JsonObject([
                'idempotencyKey' => 'kb-create-' . $instanceId->value,
                'requestId' => $ids->next()->value,
            ]), $validator),
        );
        // Refresh scope to current KB epoch after KB creation
        $currentScope = $host->current()->scope;

        $ingestAndPublish = static function (
            D\Scope $scope,
            string $sourceKey,
            string $utf8,
            string $mediaType,
            string $rightsRef,
        ) use (
            $db, $tx, $catalog, $catalogCommands, $sourceCommands, $dependencies, $blobs, $ledger, $authority,
            $events, $outbox, $clock, $ids, $validator, $policy, $host, &$sets, &$documents, &$lastSourceId
        ): D\PublishReceipt {
            $activeScope = $host->current()->scope;
            if (!$scope->tenantId->equals($activeScope->tenantId)
                || !$scope->kbId->equals($activeScope->kbId)
                || !$scope->hostScopeHash->equals($activeScope->hostScopeHash)) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }

            $putBlob = static function (D\SourceId $sourceId, string $bytes, D\Decimal $epoch) use ($blobs, $ledger, $activeScope, $ids): D\BlobRef {
                $resource = new D\ResourceId($ids->next()->value);
                $reservation = new D\BlobWriteReservation(
                    new D\ResourceRef($activeScope->tenantId, 'resource', $resource),
                    $sourceId,
                    new D\WriterId($ids->next()->value),
                    new D\Decimal('0'),
                    $epoch,
                    new D\Sha256(hash('sha256', $bytes)),
                    new D\Uint64((string) strlen($bytes)),
                    null,
                );
                $ledger->reserveWriter($reservation);
                $stream = new class($bytes) implements P\ReadableStream {
                    private int $offset = 0;

                    public function __construct(private string $bytes)
                    {
                    }

                    public function read(int $maxBytes): string
                    {
                        $chunk = substr($this->bytes, $this->offset, $maxBytes);
                        $this->offset += strlen($chunk);

                        return $chunk;
                    }

                    public function eof(): bool
                    {
                        return $this->offset >= strlen($this->bytes);
                    }

                    public function close(): void
                    {
                    }
                };

                return $blobs->putImmutable($reservation, $stream);
            };

            $sourceId = new D\SourceId($ids->next()->value);
            $lastSourceId = $sourceId;
            $source = $sourceCommands->register(
                $activeScope,
                new D\RegisterSource($sourceId, $rightsRef, 'local:' . $sourceKey),
                new D\MutationContext(new JsonObject([
                    'idempotencyKey' => 'reg-' . $sourceId->value,
                    'requestId' => $ids->next()->value,
                ]), $validator),
            );
            $catalogCommands->addMembership(
                $activeScope,
                new D\AddMembership($sourceId, null, new D\CatalogMetadata()),
                new D\MutationContext(new JsonObject([
                    'idempotencyKey' => 'mem-' . $sourceId->value,
                    'requestId' => $ids->next()->value,
                ]), $validator),
            );
            $activeScope = $host->current()->scope;

            $versionId = new D\SourceVersionId($ids->next()->value);
            $ref = new D\SourceVersionRef($activeScope->tenantId, $sourceId, $versionId, new D\SourceDigest(hash('sha256', $utf8)));
            $blob = $putBlob($sourceId, $utf8, $source->lifecycleEpoch);
            $version = $sourceCommands->acceptVersion(
                $activeScope,
                new D\SourceVersion($ref, $blob, 'text/plain', $rightsRef, $clock->now()),
                new D\MutationContext(new JsonObject([
                    'idempotencyKey' => 'ver-' . $versionId->value,
                    'requestId' => $ids->next()->value,
                ]), $validator),
            );

            $parser = ParserSettings::profile('text');
            $chunker = new D\ChunkerProfile('local-text', 'structural', '1', new JsonObject(['maxBytes' => 1200, 'overlapBlocks' => 0]));
            $document = (new TextParser($validator))->parse(new D\SourceBytes($version->ref, $utf8, 'text/plain'), $parser);
            $revision = new D\DocumentRevisionRef(
                $version->ref,
                new D\DocumentRevisionId($ids->next()->value),
                new D\IrDigest(hash('sha256', $document->canonical())),
                $parser->signature,
            );
            $documentId = new D\DocumentId($ids->next()->value);
            $set = (new StructuralChunker($revision, $documentId))->build($document, $chunker);

            $irBlob = $putBlob($sourceId, $document->canonical(), $source->lifecycleEpoch);
            $segments = $blocks = [];
            foreach ($document->segments() as $segment) {
                $sbBlob = $putBlob($sourceId, (string) $segment->get('text'), $source->lifecycleEpoch);
                $segments[$segment->get('segmentId')] = new UnitStorage($ids->next(), new D\ResourceId($sbBlob->objectVersion));
            }
            foreach ($document->toWire()->get('blocks') as $block) {
                $blocks[$block->get('blockId')] = $ids->next();
            }
            $docStorage = new DocumentStorage($documentId, $sourceKey, new D\ResourceId($irBlob->objectVersion), $clock->now(), [], $segments, $blocks, []);
            $chunkMeta = [];
            foreach ($set->chunkSet->chunks as $chunk) {
                $cb = $putBlob($sourceId, $chunk->text(), $source->lifecycleEpoch);
                $chunkMeta[$chunk->ref->chunkId->value] = new \KnowKit\Adapter\Postgres\Catalog\ChunkMetadata(
                    new D\ResourceId($cb->objectVersion),
                    $set->provenance($chunk->ref->chunkId->value),
                    $set->structurePath($chunk->ref->chunkId->value),
                );
            }

            $tx->run(function () use ($tx, $db, $activeScope, $sourceId, $document, $set, $docStorage, $chunkMeta, $version, $revision, $catalog, $dependencies, $outbox, $events): void {
                $tx->lockTenant($activeScope->tenantId, D\LockMode::Update);
                $tx->lockSources([$sourceId], D\LockMode::Update);
                (new PdoDocumentStore($db, $tx, $outbox, $events->create(...)))->save(
                    $activeScope->tenantId,
                    $set->chunkSet->revisionRef,
                    $document,
                    $docStorage,
                    new D\Parents($activeScope->tenantId, [new D\ResourceRef($activeScope->tenantId, 'source_version', $version->ref->sourceVersionId)]),
                );
                $catalog->insertChunkSet($activeScope->tenantId, $set->chunkSet, new \KnowKit\Adapter\Postgres\Catalog\ChunkSetMetadata($set->manifestDigest, $chunkMeta));
                foreach ($set->chunkSet->chunks as $chunk) {
                    $resource = new D\ResourceRef($activeScope->tenantId, 'chunk', $chunk->ref->chunkId);
                    $identity = (new \KnowKit\Adapter\Postgres\Dependencies\NodeMetadata($db, $tx))->resolve($resource)['identity'];
                    $dependencies->register(
                        new D\Node($resource, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity))),
                        new D\Parents($activeScope->tenantId, [new D\ResourceRef($activeScope->tenantId, 'document_revision', $revision->documentRevisionId)]),
                    );
                }
            });

            $generationId = new D\GenerationId($ids->next()->value);
            $inventory = new ResourceInventory($db);
            $resources = [];
            foreach ($inventory->resources($activeScope->tenantId, [$set]) as $resource) {
                $resources[] = $resource;
            }
            $candidate = (new Builder($validator))->build($activeScope, $generationId, [$set], $resources, $inventory->coverage($activeScope->tenantId, [$set]));

            $canonical = new CanonicalChunkSets([$set]);
            $probe = new IndexReadabilityProbe(
                new PdoReadBackend($db, $canonical),
                static function (D\TenantId $tenant, JsonObject $res) use ($db, $blobs): P\ReadableStream {
                    $row = $db->row(
                        'SELECT * FROM ' . $db->table('resource_ledger') . ' WHERE tenant_id=? AND id=?',
                        [$tenant->value, $res->get('resourceId')],
                    );
                    if ($row === null) {
                        throw new D\DomainFailure(D\Outcome::Unavailable, 'resource_unavailable');
                    }

                    return $blobs->open(new D\BlobRef(
                        $tenant,
                        new D\SourceId((string) $row['source_id']),
                        new D\Sha256((string) $row['digest']),
                        new D\Uint64((string) $row['byte_length']),
                        (string) $row['storage_id'],
                        (string) $row['object_version'],
                        (string) $row['owner_kind'],
                    ));
                },
            );

            $staging = new PdoStagingGenerations(
                $db,
                $tx,
                $authority->prepare(...),
                $authority->requireAllow(...),
                $inventory,
                $probe->verify(...),
                $probe->verifyBackend(...),
                (new ProjectionReadiness())->check(...),
                $events->create(...),
                $outbox,
            );
            $store = new PdoActivation($db, $tx, $staging, $clock, $ids, $validator, $events->create(...), $outbox);
            $index = new PdoLexicalIndex($db, $tx, $validator, $canonical, $ids);
            $publisher = new Publisher($store, $authority, $probe);

            $queue = new \KnowKit\Adapter\Postgres\Jobs\PdoJobQueue(
                $db,
                $tx,
                $activeScope,
                $ids,
                ['local_text' => ['text']],
                static fn (D\Lease $l, D\Checkpoint $c): D\JobCheckpointManifest => throw new D\DomainFailure(D\Outcome::Unavailable, 'checkpoint_unavailable'),
            );
            $processing = new D\ProcessingIdentity(
                $version->ref->sourceVersionId,
                $version->ref->sourceDigest,
                $parser->signature,
                new D\ParserSignature('none', '1', new D\Fingerprint(hash('sha256', 'none'))),
                $chunker->signature,
                'ir-1.0',
                new D\Fingerprint(hash('sha256', PdoLexicalIndex::PROFILE)),
                null,
            );
            $queue->enqueue(
                new D\JobCommand(
                    $activeScope,
                    $version->ref,
                    null,
                    new D\JobType('local_text'),
                    $processing,
                    new D\UtcInstant('2000-01-01T00:00:00Z'),
                ),
                new D\IdempotencyKey('job-' . $generationId->value),
            );
            $lease = $queue->lease(new D\JobType('local_text'), new D\WorkerId($ids->next()->value));
            self::assertNotNull($lease);
            $request = new D\ActivationRequest($lease, $candidate, new D\Decimal('0'), new D\IdempotencyKey('pub-' . $generationId->value));
            $staging->stage($lease, $candidate);
            $tx->run(function () use ($tx, $activeScope, $generationId, $index, $set): void {
                $tx->lockTenant($activeScope->tenantId, D\LockMode::Update);
                $index->index($generationId, $set->chunkSet);
            });
            $staging->seal($lease, $candidate);
            $receipt = $publisher->publish($request);

            $sets[$generationId->value] = $set;
            $documents[$revision->irDigest->value] = $document;

            return $receipt;
        };

        $checks = new PolicyChecks($policy, $egress, $clock);
        $cache = new InMemoryReferenceCache($clock);
        $records = new PdoSelections($db, new D\SelectionRecordCodec($validator));
        $usages = new PdoUsages($db, $tx, $dependencies);

        $makeSelections = static function () use ($db, $tx, $validator, $clock, $ids, $checks, $records, $cache, &$sets, &$documents): array {
            $canonicalSets = new CanonicalChunkSets(array_values($sets));
            $selectionCatalog = new PdoSelectionCatalog($db, $canonicalSets, $validator, $documents, new CanonicalUnits());
            $lexicalIndex = new PdoLexicalIndex($db, $tx, $validator, $canonicalSets, $ids);
            $selections = new Selections($checks, $lexicalIndex, $selectionCatalog, $records, new BoundedSelector(), $clock, $ids, $validator, $cache);

            return [$selections, $selectionCatalog];
        };
        $makeDisclosures = static function () use ($makeSelections, $checks, $db, $blobs, $validator, $usages, $ids): Disclosures {
            [$selections, $selectionCatalog] = $makeSelections();
            $canonicalBuffer = new CanonicalBuffer($selectionCatalog, new PdoCanonicalDocuments($db, $blobs, $validator), $ids);

            return new Disclosures($selections, $checks, $canonicalBuffer, $usages, $ids, $validator);
        };
        $navigation = new SourceNavigation($host, $policy, $checks, new PdoNavigationStore($db, $tx, $usages, $validator, $ids), new PdoCanonicalDocuments($db, $blobs, $validator), $clock, $ids);
        $effectiveProfile = new D\EffectiveProfile('local-text', '1', ['lexical' => new D\AdapterId('postgres.fts')]);

        $facade = new KnowledgeFacade(
            host: $host,
            catalog: $catalog,
            transaction: $tx,
            selections: static fn (): Selections => $makeSelections()[0],
            disclosures: $makeDisclosures,
            navigation: $navigation,
            lifecycle: $lifecycle,
            ids: $ids,
            validator: $validator,
            effectiveProfile: $effectiveProfile,
            searchProfileId: new D\ProfileId(PdoLexicalIndex::PROFILE),
            ingestAndPublish: $ingestAndPublish,
            eligibleVersions: $eligible,
            policyBinding: $binding,
        );

        return [
            'facade' => $facade,
            'catalog' => $catalog,
            'selections' => static fn (): Selections => $makeSelections()[0],
            'host' => $host,
            'ids' => $ids,
            'effectiveProfile' => $effectiveProfile,
            'eligible' => $eligible,
            'binding' => $binding,
            'lastSourceId' => $getLastSourceId,
        ];
    }

    private function profile(): HostProfile
    {
        $harness = new \KnowKit\Conformance\AdapterHarness(
            SchemaLoader::load(dirname(__DIR__, 3) . '/schemas'),
        );
        $host = new \KnowKit\Examples\CustomAdapter\FakeHost($harness->fixture);
        $doc = StrictJson::decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/host-profiles/project-a.json'),
        );
        self::assertInstanceOf(JsonObject::class, $doc);
        $registry = $host->registry();
        self::assertInstanceOf(AdapterRegistry::class, $registry);

        return new HostProfile($doc, $registry);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
