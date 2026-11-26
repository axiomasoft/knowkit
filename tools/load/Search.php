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

final class Search
{
    public static function run(): array
    {
        $report = [];
        LexicalFixture::run(function (LexicalFixture $f) use (&$report): void {
            $dataset = new Dataset($f);
            $dataset->load();
            $reference = Report::profile()['reference'];
            $sources = Report::intField($reference, 'sources');
            $chunks = Report::intField($reference, 'chunks');
            $clients = Report::intField($reference, 'searchClients');
            $ceiling = Report::intField($reference, 'lexicalWarmP95Ms');
            $lookups = Lookups::run($f->catalog->db);
            $cohort = self::cohort($dataset);
            $large = self::largePrefilter($dataset);
            $warmStats = $cohort['warmLatencyMs'];
            $report = Report::save('lexical', ['sources' => $sources, 'chunks' => $chunks, 'clients' => $clients,
                'prefilterSourcesPerClient' => 64, ...$cohort,
                'largePrefilter' => $large,
                'immutableLookupPlans' => $lookups,
                'manifestBytes' => $dataset->manifestBytes,
                'targetWarmP95Ms' => $ceiling, 'targetMet' => $warmStats['p95'] <= $ceiling,
                'postgres' => $f->catalog->db->execute('SHOW server_version')->fetchColumn(),
                'providerCalls' => 0, 'tokens' => 0,
                'setup' => 'Bulk fixture SQL excluded. 5000 EN and 5000 RU immutable source manifests, ten overlapping exact chunks per source. Existing fixture controls are outside generation 900.']);
        }, seal: false);

        return $report;
    }

    private static function cohort(Dataset $dataset): array
    {
        $sets = array_slice($dataset->sets, 0, 64);
        $scope = $dataset->scope($sets);
        $clients = Report::intField(Report::profile()['reference'], 'searchClients');
        $iterations = Report::intField(Report::profile()['quotas'], 'searchWarmIterationsPerClient');
        $actors = [];
        for ($client = 0; $client < $clients; ++$client) {
            $actors[] = new Actor(db: $dataset->fixture->catalog->db, config: ['operation' => 'search', 'sets' => $sets,
                'scope' => $scope, 'iterations' => $iterations]);
        }
        foreach ($actors as $actor) {
            $actor->go();
        }
        $cold = $warm = $cpus = $peaks = [];
        $languages = ['en' => [], 'ru' => []];
        foreach ($actors as $actor) {
            $result = $actor->result();
            $cold[] = $result['coldMs'];
            array_push($warm, ...$result['warmMs']);
            $cpus[] = $result['cpuMs'];
            $peaks[] = $result['peakBytes'];
            foreach ($result['languageMs'] as $language => $values) {
                array_push($languages[$language], ...$values);
            }
        }

        return ['coldLatencyMs' => Metrics::percentiles($cold), 'warmLatencyMs' => Metrics::percentiles($warm),
            'clientCpuMs' => array_sum($cpus), 'maxClientPeakBytes' => Metrics::maximum($peaks),
            'languageLatencyMs' => array_map(Metrics::percentiles(...), $languages)];
    }

    private static function largePrefilter(Dataset $dataset): array
    {
        $large = [];
        $cpu = Metrics::cpuMs();
        $scope = $dataset->scope(array_slice($dataset->sets, 0, 4096));
        try {
            $dataset->scope(array_slice($dataset->sets, 0, 4097));
            throw new \RuntimeException('Oversized permission prefilter accepted');
        } catch (D\Contract\ContractViolation $failure) {
            Qa::ensure(str_contains($failure->path, 'allowedSourceVersionIds'), 'wrong permission quota failure');
        }
        $index = $dataset->index($dataset->sets);
        $hits = Metrics::timed(operation: fn () => $index->search(scope: $scope, query: new D\Query('gravity'), k: 10), samples: $large);
        Qa::ensure(count($hits->candidates) === 10, 'large prefilter lost positive matches');
        self::assertHits(hits: $hits, sets: $dataset->sets);
        Qa::ensure($index->search(scope: $dataset->scope([]), query: new D\Query('gravity'), k: 10)->candidates === [], 'empty permission grant leaked');

        return ['sources' => 4096, 'chunks' => 40960, 'latencyMs' => Metrics::percentiles($large), 'oversizedGrantRejected' => true,
            'cpuMs' => Metrics::cpuMs() - $cpu, 'processPeakBytes' => memory_get_peak_usage(true)];
    }

    public static function client(Connection $db, array $config): array
    {
        $index = new PdoLexicalIndex(db: $db, transaction: new PdoCatalogTransaction($db),
            validator: PortFixture::validator(), canonical: new CanonicalChunkSets($config['sets']), ids: new TestIds());
        $samples = [];
        $languages = ['en' => [], 'ru' => []];
        $cpu = Metrics::cpuMs();
        for ($i = 0; $i <= $config['iterations']; ++$i) {
            $language = $i % 2 === 0 ? 'en' : 'ru';
            $query = new D\Query($language === 'en' ? 'gravity' : 'тест');
            $hits = Metrics::timed(operation: fn () => $index->search(scope: $config['scope'], query: $query, k: 10), samples: $samples);
            $languages[$language][] = $samples[array_key_last($samples)];
            Qa::ensure(count($hits->candidates) === 10, 'concurrent search returned incomplete candidates');
            self::assertHits(hits: $hits, sets: $config['sets']);
        }
        $cold = array_shift($samples);

        return ['coldMs' => $cold, 'warmMs' => $samples, 'languageMs' => $languages, 'cpuMs' => Metrics::cpuMs() - $cpu,
            'peakBytes' => Metrics::peakBytes()];
    }

    private static function assertHits(D\CandidateList $hits, array $sets): void
    {
        $expected = [];
        foreach ($sets as $set) {
            foreach ($set->chunkSet->chunks as $chunk) {
                $expected[$chunk->ref->chunkId->value] = $chunk->ref;
            }
        }
        foreach ($hits->candidates as $hit) {
            Qa::ensure(isset($expected[$hit->ref->chunkId->value]) && $expected[$hit->ref->chunkId->value] == $hit->ref,
                'foreign or mutable search identity escaped');
        }
    }
}
