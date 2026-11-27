#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require __DIR__ . '/bootstrap.php';

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
use KnowKit\Application\Access\{HostIdentity, PolicyChecks, ScopedAllowPolicy};
use KnowKit\Application\Catalog\{CatalogCommands, MutationAuthorizer, SourceCommands};
use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Ingest\Text\{ParserSettings, TextParser};
use KnowKit\Application\Lifecycle\LifecycleCommands;
use KnowKit\Application\Publication\Activate\Publisher;
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\{Builder, ProjectionReadiness};
use KnowKit\Application\Retrieval\Disclosure\{CanonicalBuffer, Disclosures};
use KnowKit\Application\Retrieval\Selection\{BoundedSelector, CanonicalUnits, Selections};
use KnowKit\Application\Runtime\{InstanceRegistry, KnowledgeFacade, KnowledgeInstance, SingleLocalScopeFactory};
use KnowKit\Application\SourceNavigation;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Port as P;
use KnowKit\Registry\{AdapterRegistry, HostProfile, ReadinessReport};

/**
 * Minimal headless multi-instance PG 17 fixture:
 * boots two isolated instances (single_local + multi_tenant_strict) in one process
 * and verifies per-instance search/disclosure and cross-instance scope_mismatch denial.
 */
final class MultiInstanceExample
{
    public static function run(): void
    {
        $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
        if (preg_match('/^pgsql:.*(?:^|;)dbname=[a-zA-Z0-9_]+_test(?:;|$)/D', $dsn) !== 1) {
            throw new \RuntimeException('Disposable *_test database required');
        }
        $user = getenv('TEST_PG_USER') ?: 'knowkit_test';
        $password = getenv('TEST_PG_PASSWORD') ?: null;
        $validator = self::validator();
        $profile = self::hostProfile();

        self::withSandbox($dsn, $user, $password, function (Connection $dbA, string $blobA) use ($dsn, $user, $password, $validator, $profile): void {
            self::withSandbox($dsn, $user, $password, function (Connection $dbB, string $blobB) use ($dbA, $blobA, $validator, $profile): void {
                $idA = D\InstanceId::fromString('support_faq');
                $idB = D\InstanceId::fromString('enterprise_kb');

                $scopeA = (new SingleLocalScopeFactory($idA, 'support_kb', 'support.answer'))->scope(0);
                $scopeB = new D\Scope(
                    new D\TenantId(self::ulid('enterprise-tenant')),
                    new D\KbId(self::ulid('enterprise-kb')),
                    new D\ActorId(self::ulid('enterprise-actor')),
                    'enterprise.answer',
                    new D\Decimal('0'),
                    new D\Sha256(hash('sha256', 'enterprise-host-scope-v1')),
                );

                $runtimeA = self::buildFacade($dbA, $blobA, $validator, $idA, $scopeA);
                $runtimeB = self::buildFacade($dbB, $blobB, $validator, $idB, $scopeB);

                $registry = new InstanceRegistry();
                $registry->register(
                    id: $idA,
                    profile: $profile,
                    preset: D\IsolationPreset::SingleLocal,
                    factory: static fn (): KnowledgeInstance => new KnowledgeInstance(
                        id: $idA,
                        profile: $profile,
                        preset: D\IsolationPreset::SingleLocal,
                        facade: $runtimeA['facade'],
                        readiness: new ReadinessReport(true, true, true, true, false, false),
                    ),
                );
                $registry->register(
                    id: $idB,
                    profile: $profile,
                    preset: D\IsolationPreset::MultiTenantStrict,
                    factory: static fn (): KnowledgeInstance => new KnowledgeInstance(
                        id: $idB,
                        profile: $profile,
                        preset: D\IsolationPreset::MultiTenantStrict,
                        facade: $runtimeB['facade'],
                        readiness: new ReadinessReport(true, true, true, true, false, false),
                    ),
                );
                $registry->boot();

                $instA = $registry->get($idA);
                $instB = $registry->get($idB);

                $instA->facade->ingestText($scopeA, 'faq/refund.md', "# Refund\nSupport refunds within 14 days.\n");
                $instB->facade->ingestText($scopeB, 'policy/sla.md', "# SLA\nEnterprise SLA uptime is 99.99 percent.\n");

                $activeA = $runtimeA['host']->current()->scope;
                $activeB = $runtimeB['host']->current()->scope;

                $resA = $instA->facade->searchAndDisclose($activeA, new D\Query('refund'));
                $resB = $instB->facade->searchAndDisclose($activeB, new D\Query('uptime'));
                if ($resA->result->outcome !== D\Outcome::Success || $resB->result->outcome !== D\Outcome::Success) {
                    throw new \RuntimeException('Expected per-instance search to succeed');
                }

                // Cross-instance scope presentation must be denied with scope_mismatch.
                $cross = $instB->facade->searchAndDisclose($activeA, new D\Query('refund'));
                if ($cross->result->outcome !== D\Outcome::Denied || $cross->result->errorCode !== 'scope_mismatch') {
                    throw new \RuntimeException('Expected cross-instance scope_mismatch denial');
                }

                $report = [
                    'instances' => array_keys($registry->describeAll()),
                    'presets' => [
                        'support_faq' => $instA->preset->value,
                        'enterprise_kb' => $instB->preset->value,
                    ],
                    'supportOutcome' => $resA->result->outcome->value,
                    'enterpriseOutcome' => $resB->result->outcome->value,
                    'crossInstanceDenial' => $cross->result->errorCode,
                ];
                fwrite(STDOUT, Jcs::canonicalize($report) . "\n");
            });
        });
    }

    /** @param \Closure(Connection, string): void $fn */
    private static function withSandbox(string $dsn, string $user, ?string $password, \Closure $fn): void
    {
        $pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = 'knowkit_mi_' . bin2hex(random_bytes(8));
        $role = 'knowkit_mi_role_' . bin2hex(random_bytes(8));
        $blobDir = sys_get_temp_dir() . '/knowkit-mi-' . bin2hex(random_bytes(8));
        mkdir($blobDir, 0700, true);
        $pdo->exec('CREATE SCHEMA ' . $schema);
        try {
            $pdo->exec('SET search_path TO ' . $schema);
            (new Migrate($pdo, $schema))->apply(dirname(__DIR__, 2) . '/packages/core/migrations/postgres/001_metadata.sql');
            $pdo->exec('CREATE ROLE ' . $role . ' NOLOGIN NOSUPERUSER');
            try {
                $pdo->exec('GRANT USAGE ON SCHEMA ' . $schema . ' TO ' . $role);
                $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $schema . ' TO ' . $role);
                $pdo->exec('SET ROLE ' . $role);
                $fn(new Connection($pdo, $schema), $blobDir);
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec('RESET ROLE');
                $pdo->exec('DROP OWNED BY ' . $role);
                $pdo->exec('DROP ROLE ' . $role);
            }
        } finally {
            $pdo->exec('SET search_path TO public');
            $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
            self::rmTree($blobDir);
        }
    }

    /** @return array{facade: KnowledgeFacade, host: HostIdentity} */
    private static function buildFacade(
        Connection $db,
        string $blobDir,
        ContractValidator $validator,
        D\InstanceId $instanceId,
        D\Scope $initialScope,
    ): array {
        $tx = new PdoCatalogTransaction($db);
        $clock = new class($db) implements P\Clock {
            public function __construct(private Connection $db) {}

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
                return new D\Ulid(MultiInstanceExample::ulid(bin2hex(random_bytes(16))));
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
            $rows = [];
            if ($operation === null || in_array($operation, [D\Operation::Retrieve, D\Operation::Disclose, D\Operation::Navigate], true)) {
                $rows = $db->execute(
                    'SELECT s.id,s.lifecycle_epoch,s.source_policy_epoch FROM ' . $db->table('sources') . ' s'
                    . ' JOIN ' . $db->table('source_memberships') . ' m ON m.tenant_id=s.tenant_id AND m.source_id=s.id'
                    . ' WHERE s.tenant_id=? AND m.kb_id=? GROUP BY s.id,s.tenant_id ORDER BY s.id',
                    [$scope->tenantId->value, $scope->kbId->value],
                )->fetchAll(\PDO::FETCH_ASSOC);
            } elseif ($resource !== null && $resource->kind === 'source') {
                $row = $db->row(
                    'SELECT id,lifecycle_epoch,source_policy_epoch FROM ' . $db->table('sources') . ' WHERE tenant_id=? AND id=?',
                    [$scope->tenantId->value, $resource->id->value],
                );
                if ($row !== null) {
                    $rows[] = $row;
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
            public function __construct(private Connection $db, private D\Scope $identity) {}

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
            $db, $tx, $authority->requireAllow(...), $clock, $ids, $events->create(...), $outbox, bin2hex(random_bytes(32)),
        );
        $catalogCommands = new CatalogCommands($catalog, $tx, $authority);
        $sourceCommands = new SourceCommands(
            $catalog->registerSourceMetadata(...), $catalog->acceptSourceVersion(...), $tx, $authority,
        );
        $dependencies = new PdoDependencyStore($db, $tx, $initialScope->tenantId, $ids);
        $lifecycle = new LifecycleCommands(
            new PdoLifecycleStore(
                $db, $tx, $initialScope->tenantId, $clock, $ids, $validator, $events->create(...), $dependencies, $outbox, $authority->requireAllow(...),
            ),
            $catalog,
            $tx,
            $authority,
            $catalog->acceptSourceVersion(...),
        );
        $ledger = new PdoResourceLedger($db, $tx, $initialScope->tenantId, 'local', $clock);
        $callbacks = [];
        foreach (['inspectWriter', 'finalizeIdentity', 'closeWriter', 'markUncertain', 'requireAccepted',
            'listUnreferenced', 'quarantine', 'recoveryRef', 'reconcileWriter'] as $method) {
            $callbacks[$method] = $ledger->$method(...);
        }
        $blobs = new LocalBlobStore(
            $initialScope->tenantId,
            $blobDir,
            'local',
            8388608,
            $callbacks,
            static fn (D\BlobRef $ref) => $initialScope->assertTenant($ref->tenantId),
            static fn (): never => throw new D\DomainFailure(D\Outcome::Unavailable, 'deletion_binding_required'),
            $ids,
            $clock,
            $validator,
        );

        $tx->run(function () use ($tx, $catalog, $initialScope): void {
            $tx->lockTenant($initialScope->tenantId, D\LockMode::Update);
            if ($catalog->getTenant($initialScope->tenantId) === null) {
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

        $sets = [];
        $documents = [];
        $ingestAndPublish = static function (
            D\Scope $scope,
            string $sourceKey,
            string $utf8,
            string $mediaType,
            string $rightsRef,
        ) use (
            $db, $tx, $catalog, $catalogCommands, $sourceCommands, $dependencies, $blobs, $ledger, $authority,
            $events, $outbox, $clock, $ids, $validator, $policy, $host, &$sets, &$documents
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

                    public function __construct(private string $bytes) {}

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

                    public function close(): void {}
                };

                return $blobs->putImmutable($reservation, $stream);
            };

            $sourceId = new D\SourceId($ids->next()->value);
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
                        new D\Node($resource, new D\Fingerprint(\KnowKit\Domain\Contract\HashDomain::DependencyNodeIdentity->digest($identity))),
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
                $db, $tx, $authority->prepare(...), $authority->requireAllow(...), $inventory,
                $probe->verify(...), $probe->verifyBackend(...), (new ProjectionReadiness())->check(...),
                $events->create(...), $outbox,
            );
            $store = new PdoActivation($db, $tx, $staging, $clock, $ids, $validator, $events->create(...), $outbox);
            $index = new PdoLexicalIndex($db, $tx, $validator, $canonical, $ids);
            $publisher = new Publisher($store, $authority, $probe);

            $queue = new \KnowKit\Adapter\Postgres\Jobs\PdoJobQueue(
                $db, $tx, $activeScope, $ids, ['local_text' => ['text']],
                static fn (): D\JobCheckpointManifest => throw new D\DomainFailure(D\Outcome::Unavailable, 'checkpoint_unavailable'),
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
                new D\JobCommand($activeScope, $version->ref, null, new D\JobType('local_text'), $processing, new D\UtcInstant('2000-01-01T00:00:00Z')),
                new D\IdempotencyKey('job-' . $generationId->value),
            );
            $lease = $queue->lease(new D\JobType('local_text'), new D\WorkerId($ids->next()->value));
            if ($lease === null) {
                throw new \RuntimeException('Failed to lease indexing job');
            }
            $staging->stage($lease, $candidate);
            $tx->run(function () use ($tx, $activeScope, $generationId, $index, $set): void {
                $tx->lockTenant($activeScope->tenantId, D\LockMode::Update);
                $index->index($generationId, $set->chunkSet);
            });
            $staging->seal($lease, $candidate);
            $receipt = $publisher->publish(new D\ActivationRequest($lease, $candidate, new D\Decimal('0'), new D\IdempotencyKey('pub-' . $generationId->value)));
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
            policyBinding: static fn (D\Scope $s): JsonObject => $binding($s),
        );

        return ['facade' => $facade, 'host' => $host];
    }

    private static function validator(): ContractValidator
    {
        $schemas = [];
        foreach (glob(dirname(__DIR__, 2) . '/schemas/*.schema.json') ?: [] as $path) {
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new \RuntimeException('Schema unreadable: ' . $path);
            }
            $wire = StrictJson::decode($bytes, 4194304);
            $schemas[$wire->get('$id')] = $wire;
        }

        return new ContractValidator(new SchemaSet($schemas));
    }

    private static function hostProfile(): HostProfile
    {
        $registry = new AdapterRegistry();
        $registry->register(
            new \KnowKit\Registry\AdapterDescriptor(
                id: new D\AdapterId('plain-php.clock'),
                kind: D\AdapterKind::Clock,
                version: '1.0.0',
                configSchema: StrictJson::decode('{"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"urn:example:clock:1","type":"object","unevaluatedProperties":false}'),
                capabilities: [],
            ),
            new class implements \KnowKit\Registry\Factory {
                public function create(D\EffectiveProfile $profile): object
                {
                    throw new \LogicException('Unused in example');
                }
            },
        );
        $registry->boot();

        return HostProfile::fromJson(
            json_encode([
                'schemaVersion' => 'host-profile:1',
                'id' => 'plain-php-multi-instance',
                'version' => '1.0.0',
                'adapterBindings' => [
                    'clock' => [
                        'adapterId' => 'plain-php.clock',
                        'config' => new \stdClass(),
                        'capabilities' => [],
                        'featureClaims' => [],
                    ],
                ],
                'parserProfiles' => new \stdClass(),
                'searchProfiles' => new \stdClass(),
                'coveragePolicy' => ['version' => '1', 'state' => 'complete'],
                'quotas' => ['maxBytes' => 4096, 'maxFragments' => 32, 'maxAssets' => 0],
            ], JSON_THROW_ON_ERROR),
            $registry,
        );
    }

    public static function ulid(string $seed): string
    {
        $bits = '00';
        foreach (str_split(substr(hash('sha256', $seed), 0, 32)) as $digit) {
            $bits .= str_pad(decbin((int) hexdec($digit)), 4, '0', STR_PAD_LEFT);
        }
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $id = '';
        foreach (str_split($bits, 5) as $part) {
            $id .= $alphabet[(int) bindec($part)];
        }

        return $id;
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}

try {
    MultiInstanceExample::run();
    exit(0);
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(4);
}
