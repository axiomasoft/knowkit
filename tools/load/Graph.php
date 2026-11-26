<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use KnowKit\Tests\Storage\Catalog\TestIds;
use KnowKit\Tooling\Qa;

final class Graph
{
    public static function run(bool $hot): array
    {
        $report = [];
        CatalogFixture::run(function (CatalogFixture $c) use ($hot, &$report): void {
            $quotas = Report::profile()['quotas'];
            $writers = $hot ? Report::intField($quotas, 'hotTenantGraphWriters') : Report::intField($quotas, 'tenants');
            self::seed(c: $c, hot: $hot);
            $actors = self::actors(c: $c, hot: $hot, writers: $writers);
            $start = hrtime(true);
            foreach ($actors as $actor) {
                $actor->go();
            }
            $latency = $wait = $peaks = [];
            $cpu = 0;
            foreach ($actors as $actor) {
                $result = $actor->result();
                array_push($latency, ...$result['latencyMs']);
                array_push($wait, ...$result['lockWaitMs']);
                $cpu += $result['cpuMs'];
                $peaks[] = $result['peakBytes'];
            }
            $duration = (hrtime(true) - $start) / 1e9;
            $writes = Report::intField(Report::profile()['quotas'], 'graphWritesPerWriter');
            Qa::ensure($c->count('dependency_nodes') === $writers * $writes, 'lost graph registration');
            $report = Report::save($hot ? 'hot-tenant' : 'many-tenants', ['tenants' => $hot ? 1 : $writers,
                'writers' => $writers, 'writes' => $writers * $writes, 'latencyMs' => Metrics::percentiles($latency),
                'lockWaitMs' => Metrics::percentiles($wait), 'cpuMs' => $cpu,
                'maxClientPeakBytes' => Metrics::maximum($peaks),
                'throughputWritesPerSecond' => $writers * $writes / $duration,
                'postgres' => $c->db->execute('SHOW server_version')->fetchColumn(),
                'lockWaitDefinition' => 'Wall time around the production tenant guard, including advisory/row guard round trips. No artificial sleep or injected lock delay.']);
        });

        return $report;
    }

    private static function seed(CatalogFixture $c, bool $hot): void
    {
        $quotas = Report::profile()['quotas'];
        $writes = Report::intField($quotas, 'graphWritesPerWriter');
        $sources = $hot ? Report::intField($quotas, 'hotTenantGraphWriters') * $writes : $writes;
        foreach (range(1, $hot ? 1 : Report::intField($quotas, 'tenants')) as $tenant) {
            $c->tenant($tenant);
            for ($source = 0; $source < $sources; ++$source) {
                $c->source(id: 100000 + $source, tenant: $tenant);
            }
        }
    }

    private static function actors(CatalogFixture $c, bool $hot, int $writers): array
    {
        $writes = Report::intField(Report::profile()['quotas'], 'graphWritesPerWriter');
        $actors = [];
        for ($writer = 0; $writer < $writers; ++$writer) {
            $actors[] = new Actor(db: $c->db, config: ['operation' => 'graph', 'tenant' => $hot ? 1 : $writer + 1,
                'start' => $hot ? $writer * $writes : 0, 'count' => $writes, 'writer' => $writer]);
        }

        return $actors;
    }

    public static function client(Connection $db, array $config): array
    {
        $tenant = new D\TenantId(CatalogFixture::id($config['tenant']));
        $tx = new PdoCatalogTransaction($db);
        $metadata = new NodeMetadata(db: $db, tx: $tx);
        $graph = new PdoDependencyStore(db: $db, tx: $tx, tenant: $tenant, ids: new TestIds(900000 + $config['writer'] * 1000));
        $latency = $wait = [];
        $cpu = Metrics::cpuMs();
        for ($i = 0; $i < $config['count']; ++$i) {
            $ref = new D\ResourceRef($tenant, 'source', new D\SourceId(CatalogFixture::id(100000 + $config['start'] + $i)));
            Metrics::timed(operation: function () use ($tx, $metadata, $graph, $ref, $tenant, &$wait): void {
                $tx->run(function () use ($tx, $metadata, $graph, $ref, $tenant, &$wait): void {
                    Metrics::timed(operation: fn () => $tx->lockTenant(tenantId: $tenant, mode: D\LockMode::Update), samples: $wait);
                    $identity = $metadata->resolve($ref)['identity'];
                    $graph->register(node: new D\Node($ref, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity))),
                        parents: new D\Parents(tenantId: $tenant, refs: []));
                    Qa::ensure($graph->checkAncestors(new D\NodeRefs(tenantId: $tenant, refs: [$ref]))->decision === D\Decision::Allow,
                        'registered graph node is not usable');
                });
            }, samples: $latency);
        }

        self::rejectForeign(tx: $tx, tenant: $tenant, graph: $graph);

        return ['latencyMs' => $latency, 'lockWaitMs' => $wait, 'cpuMs' => Metrics::cpuMs() - $cpu,
            'peakBytes' => Metrics::peakBytes()];
    }

    private static function rejectForeign(PdoCatalogTransaction $tx, D\TenantId $tenant, PdoDependencyStore $graph): void
    {
        $foreign = new D\TenantId(CatalogFixture::id(99));
        $tx->run(function () use ($tx, $tenant, $graph, $foreign): void {
            $tx->lockTenant(tenantId: $tenant, mode: D\LockMode::Share);
            try {
                $graph->checkAncestors(new D\NodeRefs(tenantId: $foreign, refs: []));
                throw new \RuntimeException('Cross-tenant graph access accepted');
            } catch (D\DomainFailure $failure) {
                Qa::ensure($failure->outcome === D\Outcome::Denied && $failure->errorCode === 'scope_mismatch', 'wrong graph tenant outcome');
            }
        });

    }
}
