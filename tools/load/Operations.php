<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\Postgres\Deletion\BlobDeletionTransport;
use KnowKit\Adapter\Postgres\Deletion\PdoDeletionStore;
use KnowKit\Application\Deletion\DeletionWorker;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Events\Outbox\Fixture as Events;
use KnowKit\Tests\Ops\Resilience\Runtime;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use KnowKit\Tooling\Qa;

require_once Report::root() . '/tests/events/outbox/Fixture.php';
require_once Report::root() . '/tests/ops/resilience/bootstrap.php';
require_once Report::root() . '/tests/storage/blobs/BlobFixture.php';

final class Operations
{
    public static function outbox(): array
    {
        $report = [];
        Events::run(function (Events $f) use (&$report): void {
            for ($i = 0; $i < 64; ++$i) {
                $f->append(Events::event(id: 500 + $i, version: (string) ($i + 1)));
            }
            $delivery = $f->delivery();
            $inbox = $f->inbox();
            $latency = $lag = [];
            $cpu = Metrics::cpuMs();
            Qa::ensure($delivery->status()->get('outstanding') === 64, 'missing initial outbox backlog');
            $leases = $delivery->leaseDelivery(64);
            Qa::ensure(count($leases) === 64, 'outbox batch incomplete');
            foreach ($leases as $lease) {
                Metrics::timed(operation: function () use ($delivery, $inbox, $lease, $f, &$lag): void {
                    $event = $delivery->event($lease);
                    Qa::ensure($inbox->apply($event) === 'applied', 'outbox effect not applied');
                    Qa::ensure($inbox->apply($event) === 'duplicate', 'outbox repeated effect');
                    $lag[] = (float) $f->catalog->db->execute('SELECT extract(epoch FROM(clock_timestamp()-enqueued_at))*1000 FROM outbox WHERE tenant_id=? AND id=?',
                        [$lease->tenantId->value, $lease->eventId->value])->fetchColumn();
                    Qa::ensure($delivery->ack($lease), 'healthy outbox ack lost');
                }, samples: $latency);
            }
            Qa::ensure($delivery->status()->get('outstanding') === 0 && $f->catalog->count('inbox') === 64, 'outbox backlog did not drain');
            $stats = Metrics::percentiles($lag);
            $report = Report::save('outbox', ['events' => 64, 'backlogBefore' => 64, 'backlogAfter' => 0,
                'lagMs' => $stats, 'deliveryLatencyMs' => Metrics::percentiles($latency), 'cpuMs' => Metrics::cpuMs() - $cpu,
                'targetP95Ms' => 5000, 'targetMet' => $stats['p95'] <= 5000,
                'transport' => 'Local durable PdoInbox; each delivery replayed once; no remote provider.',
                'postgres' => $f->catalog->db->execute('SHOW server_version')->fetchColumn()]);
        });

        return $report;
    }

    public static function purge(): array
    {
        $report = [];
        CatalogFixture::run(function (CatalogFixture $c) use (&$report): void {
            $c->tenant();
            $c->kb();
            $c->source();
            $c->db->execute("INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,'{}')",
                [CatalogFixture::id(1), CatalogFixture::id(400), CatalogFixture::id(2), CatalogFixture::id(100)]);
            $blobs = new BlobFixture($c);
            try {
                [$refs, $runtime, $task] = self::preparePurge(c: $c, blobs: $blobs);
                $store = new PdoDeletionStore(db: $c->db, tx: $c->tx, tenant: $runtime->tenant,
                    clock: $c->clock, validator: PortFixture::validator());
                $transport = new BlobDeletionTransport(store: $blobs->store, storageId: 'local-v1', exactVersionProof: true);
                $worker = new DeletionWorker(store: $store, transport: $transport, clock: $c->clock, validator: PortFixture::validator());
                $latency = [];
                $cpu = Metrics::cpuMs();
                foreach ($refs as $i => $ref) {
                    $receipt = Metrics::timed(operation: fn () => $worker->attempt(tenant: $runtime->tenant, task: $task,
                        resource: new D\ResourceId(CatalogFixture::id(600000 + $i)), attempt: new D\AttemptId(CatalogFixture::id(800000 + $i))), samples: $latency);
                    Qa::ensure($receipt->toWire()->get('result') === 'deleted' && !is_file($blobs->path($ref)), 'local purge did not delete exact object');
                }
                Qa::ensure($store->aggregate(tenant: $runtime->tenant, task: $task) === 'done', 'purge backlog not done');
                $report = Report::save('purge', ['resources' => 32, 'backlogBefore' => 32, 'backlogAfter' => 0,
                    'latencyMs' => Metrics::percentiles($latency), 'cpuMs' => Metrics::cpuMs() - $cpu,
                    'aggregate' => 'done', 'transport' => 'Real local immutable blobs and fsync deletion receipts',
                    'postgres' => $c->db->execute('SHOW server_version')->fetchColumn()]);
            } finally {
                BlobFixture::removeTree($blobs->root);
            }
        });

        return $report;
    }

    private static function preparePurge(CatalogFixture $c, BlobFixture $blobs): array
    {
        $refs = [];
        for ($i = 0; $i < 32; ++$i) {
            $refs[] = $blobs->put(resource: 600000 + $i, writer: 700000 + $i, bytes: 'capacity-purge-v1-' . $i);
        }
        $runtime = new Runtime(db: $c->db, options: ['scope' => $c->scope()]);
        $task = $runtime->lifecycle->requestPurge(scope: $c->scope(),
            command: new D\PurgeRequest(new D\SourceId(CatalogFixture::id(2)), new D\Decimal('0')),
            key: new D\IdempotencyKey('capacity-purge-v1'));

        return [$refs, $runtime, $task];
    }
}
