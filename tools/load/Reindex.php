<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoLexicalIndex;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use KnowKit\Tests\Storage\Catalog\TestIds;
use KnowKit\Tooling\Qa;

require_once __DIR__ . '/Dataset.php';

final class Reindex
{
    public static function run(): array
    {
        $report = [];
        LexicalFixture::run(function (LexicalFixture $f) use (&$report): void {
            $before = $f->catalog->count('index_memberships');
            $actors = [];
            for ($i = 0; $i < 2; ++$i) {
                $actors[] = new Actor(db: $f->catalog->db, config: ['operation' => 'reindex',
                    'sets' => array_values($f->sets), 'writer' => $i]);
            }
            foreach ($actors as $actor) {
                $actor->go();
            }
            $samples = [];
            foreach ($actors as $actor) {
                array_push($samples, ...$actor->result()['latencyMs']);
            }
            Qa::ensure($f->catalog->count('index_memberships') === $before, 'concurrent reindex duplicated immutable memberships');
            $f->seal();
            $set = $f->sets['allowed']->chunkSet;
            Limits::failure(operation: fn () => $f->indexSet($set), code: 'generation_sealed');
            $hits = $f->index->search(scope: $f->scope(names: ['allowed', 'tie']), query: new D\Query('gravity'), k: 10);
            Qa::ensure(count($hits->candidates) === 2, 'reindex changed search outcome');
            $report = Report::save('reindex', ['writers' => 2, 'replaysPerWriter' => 16,
                'membershipsBefore' => $before, 'membershipsAfter' => $f->catalog->count('index_memberships'),
                'latencyMs' => Metrics::percentiles($samples), 'sealedMutation' => 'incompatible/generation_sealed']);
        }, seal: false);

        return $report;
    }

    public static function client(Connection $db, array $config): array
    {
        $tx = new PdoCatalogTransaction($db);
        $index = new PdoLexicalIndex(db: $db, transaction: $tx, validator: PortFixture::validator(),
            canonical: new CanonicalChunkSets($config['sets']), ids: new TestIds(9000000 + $config['writer'] * 10000));
        $samples = [];
        for ($i = 0; $i < 16; ++$i) {
            Metrics::timed(operation: function () use ($config, $tx, $index): void {
                $set = $config['sets'][0]->chunkSet;
                $tx->run(function () use ($tx, $index, $set): void {
                    $tx->lockTenant(tenantId: $set->revisionRef->sourceRef->tenantId, mode: D\LockMode::Update);
                    $receipt = $index->index(generationId: new D\GenerationId(Dataset::id(14)), chunkSet: $set);
                    Qa::ensure($receipt->indexedCount === count($set->chunks), 'reindex receipt changed');
                });
            }, samples: $samples);
        }

        return ['latencyMs' => $samples];
    }
}
