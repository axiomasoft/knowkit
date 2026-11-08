<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\LocalBlob\LocalBlobStore;
use KnowKit\Adapter\Postgres\Deletion\PdoDeletionStore;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Application\Deletion\DeletionWorker;
use KnowKit\Application\Resources\ReadAuthority;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\DeletionTransport;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Blobs\Bytes;
use KnowKit\Tests\Storage\Catalog\CurrentOwner;
use PHPUnit\Framework\Assert;

final readonly class Storage
{
    public function __construct(
        private Runtime $runtime,
    ) {}

    public function execute(string $operation): array
    {
        if ($operation === 'delete') {
            return $this->delete();
        }
        $r = $this->runtime;
        $ledger = new PdoResourceLedger(db: $r->db, tx: $r->tx, tenant: $r->tenant, storageId: 'local-v1', clock: $r->clock);
        $store = $this->store($ledger);
        if ($operation === 'read') {
            $stream = $store->open($r->options['ref']);
            $stream->close();

            return ['status' => 'readable'];
        }
        $reservation = $r->options['reservation'];
        if ($operation === 'recover-object') {
            $ref = $store->reconcileWriter(
                r: $reservation,
                proveRunnerStopped: static fn (): bool => $r->options['stopped'],
            );

            return ['accepted' => $ref !== null, 'digest' => $ref?->digest->value];
        }
        $ledger->reserveWriter($reservation);
        $ref = $store->putImmutable(r: $reservation, stream: new Bytes('abc'));

        return ['status' => 'committed', 'digest' => $ref->digest->value];
    }

    private function store(PdoResourceLedger $ledger): LocalBlobStore
    {
        $r = $this->runtime;
        $callbacks = [];
        foreach (['inspectWriter', 'finalizeIdentity', 'closeWriter', 'markUncertain', 'requireAccepted',
            'listUnreferenced', 'quarantine', 'recoveryRef', 'reconcileWriter'] as $method) {
            $callbacks[$method] = $ledger->$method(...);
        }
        $authority = new ReadAuthority(scope: $r->scope, owner: new CurrentOwner($r->db), clock: $r->clock, currentBinding: $ledger->readBinding(...));
        $fileSyncs = 0;
        $sync = static function ($handle, string $kind) use ($r, &$fileSyncs): bool {
            Assert::assertFalse($r->db->pdo->inTransaction());
            $synced = fsync($handle);
            if ($kind === 'file' && ++$fileSyncs === 2 && ($r->options['objectBarrier'] ?? false)) {
                Process::barrier('OBJECT_DURABLE');
            }

            return $synced;
        };

        return new LocalBlobStore(
            tenant: $r->tenant,
            root: $r->options['root'],
            storageId: 'local-v1',
            maxBytes: 1048576,
            ledger: $callbacks,
            authorizeRead: $authority->requireAllow(...),
            deletionBinding: $ledger->deletionBinding(...),
            ids: $r->ids,
            clock: $r->clock,
            validator: PortFixture::validator(),
            sync: $sync,
        );
    }

    private function delete(): array
    {
        $r = $this->runtime;
        $store = new PdoDeletionStore(db: $r->db, tx: $r->tx, tenant: $r->tenant, clock: $r->clock, validator: PortFixture::validator());
        $transport = new class($r) implements DeletionTransport {
            public int $calls = 0;

            public function __construct(
                private Runtime $runtime,
            ) {}

            public function provesExactVersion(D\DeletionTarget $target): bool
            {
                return true;
            }

            public function delete(D\DeletionTarget $target, D\AttemptId $attempt): D\DeletionReceipt
            {
                $r = $this->runtime;
                Assert::assertFalse($r->db->pdo->inTransaction());
                ++$this->calls;
                if (($r->options['window'] ?? '') === 'before-effect') {
                    Process::barrier('BEFORE_EFFECT');
                }
                // A transport-failure double with a durable exact-version effect, not a provider qualification.
                $r->db->execute(
                    'INSERT INTO resilience_provider_effects VALUES (?,?,?) ON CONFLICT DO NOTHING',
                    [$target->tenantId->value, $target->resourceId->value, $target->objectVersion],
                );
                if (($r->options['window'] ?? '') === 'after-effect') {
                    Process::barrier('AFTER_EFFECT');
                }

                return new D\DeletionReceipt(new JsonObject([
                    'attemptId' => $attempt->value,
                    'taskId' => $target->taskId->value,
                    'resourceId' => $target->resourceId->value,
                    'inFlight' => false,
                    'result' => 'deleted',
                    'providerReceipt' => 'synthetic-exact-version',
                    'knownExpiry' => null,
                    'observedAt' => $r->clock->now()->value,
                ]), PortFixture::validator());
            }
        };
        $receipt = (new DeletionWorker(store: $store, transport: $transport, clock: $r->clock, validator: PortFixture::validator()))->attempt(
            tenant: $r->tenant,
            task: $r->options['task'],
            resource: $r->options['resource'],
            attempt: $r->options['attempt'],
        );

        return ['result' => $receipt->toWire()->get('result'), 'calls' => $transport->calls, 'aggregate' => $store->aggregate(tenant: $r->tenant, task: $r->options['task'])];
    }
}
