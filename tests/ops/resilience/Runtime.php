<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogRepository;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Adapter\Postgres\Outbox\PdoInbox;
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Application\Catalog\MutationAuthorizer;
use KnowKit\Application\Catalog\SourceCommands;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Lifecycle\LifecycleCommands;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CurrentOwner;
use KnowKit\Tests\Storage\Catalog\TestClock;
use KnowKit\Tests\Storage\Catalog\TestIds;

/** Composes the candidate adapters without reseeding or loading a vendor copy of core. */
final class Runtime
{
    public readonly PdoCatalogTransaction $tx;
    public readonly Transaction $transaction;
    public readonly D\TenantId $tenant;
    public readonly D\Scope $scope;
    public readonly PdoDependencyStore $graph;
    public readonly LifecycleCommands $lifecycle;
    public readonly SourceCommands $sources;
    public readonly TestClock $clock;
    public readonly TestIds $ids;

    public function __construct(
        public readonly Connection $db,
        public readonly array $options,
    ) {
        $this->tenant = new D\TenantId(Fixture::id(1));
        $this->scope = $options['scope'] ?? new D\Scope(
            tenantId: $this->tenant,
            kbId: new D\KbId(Fixture::id(100)),
            actorId: new D\ActorId(Fixture::id(101)),
            purpose: 'catalog.manage',
            policyEpoch: new D\Decimal('0'),
            hostScopeHash: new D\Sha256(str_repeat('a', 64)),
        );
        $this->tx = new PdoCatalogTransaction($db);
        $this->transaction = new Transaction(inner: $this->tx, pause: $options['precommit'] ?? false);
        $this->clock = new TestClock();
        $this->ids = new TestIds($options['idStart'] ?? random_int(1000000, 900000000));
        $authority = new MutationAuthorizer(owner: new CurrentOwner($db), clock: $this->clock);
        $outbox = new PdoOutbox(db: $db, transaction: $this->tx);
        $events = new DomainEventFactory(clock: $this->clock, ids: $this->ids, validator: PortFixture::validator());
        $repository = new PdoCatalogRepository(
            db: $db,
            transaction: $this->tx,
            checkReceipt: $authority->requireAllow(...),
            clock: $this->clock,
            ids: $this->ids,
            createEvent: $events->create(...),
            outbox: $outbox,
            cursorSecret: str_repeat('test cursor authentication ', 2),
        );
        $this->graph = new PdoDependencyStore(db: $db, tx: $this->tx, tenant: $this->tenant, ids: $this->ids);
        $store = new PdoLifecycleStore(
            db: $db,
            tx: $this->tx,
            tenant: $this->tenant,
            clock: $this->clock,
            ids: $this->ids,
            validator: PortFixture::validator(),
            createEvent: $events->create(...),
            dependencies: $this->graph,
            outbox: $outbox,
            checkReceipt: $authority->requireAllow(...),
        );
        $this->lifecycle = new LifecycleCommands(
            store: $store,
            catalog: $repository,
            transaction: $this->transaction,
            authority: $authority,
            acceptVersion: $repository->acceptSourceVersion(...),
        );
        $this->sources = new SourceCommands(
            registerMetadata: $repository->registerSourceMetadata(...),
            acceptMetadata: $repository->acceptSourceVersion(...),
            transaction: $this->transaction,
            authority: $authority,
        );
    }

    public function execute(string $operation): array
    {
        return match ($operation) {
            'revoke', 'purge' => $this->mutate($operation),
            'publish' => $this->publish(),
            'register' => $this->register(),
            'graph-register' => $this->graphRegister(),
            'consume' => $this->consume(),
            'enqueue', 'claim', 'stale', 'takeover', 'complete', 'job-write' => (new Jobs($this))->execute($operation),
            'write', 'recover-object', 'delete', 'read' => (new Storage($this))->execute($operation),
            'restore' => (new Recovery($this))->execute(),
            default => throw new \InvalidArgumentException('Unknown fault operation'),
        };
    }

    private function mutate(string $operation): array
    {
        $source = $this->options['sourceId'] ?? new D\SourceId(Fixture::id($this->options['source'] ?? 2));
        $epoch = new D\Decimal('0');
        $key = new D\IdempotencyKey($this->options['key'] ?? $operation);
        if ($operation === 'purge') {
            $task = $this->lifecycle->requestPurge(
                scope: $this->scope,
                command: new D\PurgeRequest(sourceId: $source, expectedLifecycleEpoch: $epoch),
                key: $key,
            );

            return ['status' => 'committed', 'task' => $task->value];
        }
        $this->lifecycle->revoke(
            scope: $this->scope,
            command: new D\Revocation(sourceId: $source, expectedLifecycleEpoch: $epoch),
            key: $key,
        );

        return ['status' => 'committed'];
    }

    private function publish(): array
    {
        $this->lifecycle->publishSnapshot(
            scope: $this->scope,
            version: new D\SourceVersionRef(
                tenantId: $this->tenant,
                sourceId: new D\SourceId(Fixture::id(2)),
                sourceVersionId: new D\SourceVersionId(Fixture::id(3)),
                sourceDigest: new D\SourceDigest(hash('sha256', 'version-3')),
            ),
            snapshot: new D\SnapshotId(Fixture::id($this->options['snapshot'] ?? 110)),
            expectedLifecycleEpoch: new D\Decimal('0'),
            expectedPointerVersion: new D\Decimal('0'),
            key: new D\IdempotencyKey($this->options['key'] ?? 'publish'),
        );

        return ['status' => 'committed'];
    }

    private function register(): array
    {
        $context = new D\MutationContext(new JsonObject([
            'idempotencyKey' => $this->options['key'] ?? 'register-unseen',
            'requestId' => Fixture::id(901),
            'expectedRevision' => '0',
            'expectedEpoch' => '0',
        ]), PortFixture::validator());
        $this->sources->register(
            scope: $this->scope,
            command: new D\RegisterSource(
                sourceId: new D\SourceId(Fixture::id(77)),
                ownerRef: 'owner:test',
                origin: 'host:upload',
            ),
            context: $context,
        );

        return ['status' => 'committed'];
    }

    private function graphRegister(): array
    {
        $this->transaction->run(function (): void {
            $this->tx->lockTenant(tenantId: $this->tenant, mode: D\LockMode::Update);
            $ref = new D\ResourceRef(tenantId: $this->tenant, kind: 'resource', id: new D\ResourceId(Fixture::id(600)));
            $identity = (new NodeMetadata(db: $this->db, tx: $this->tx))->resolve($ref)['identity'];
            $this->graph->register(
                node: new D\Node(ref: $ref, identityDigest: new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity))),
                parents: new D\Parents(tenantId: $this->tenant, refs: [
                    new D\ResourceRef(tenantId: $this->tenant, kind: 'chunk', id: new D\ChunkId(Fixture::id(11))),
                ]),
            );
        });

        return ['status' => 'committed'];
    }

    private function consume(): array
    {
        $inbox = new PdoInbox(db: $this->db, tx: $this->tx, tenant: $this->tenant, consumer: 'resilience', ids: $this->ids);

        return ['status' => $inbox->apply($this->options['event'])];
    }
}
