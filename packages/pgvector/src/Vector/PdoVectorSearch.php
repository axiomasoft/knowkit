<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Vector;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\PgVector\Domain\ManifestView;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Domain\SearchResult;
use KnowKit\PgVector\Domain\VectorPayload;

final readonly class PdoVectorSearch
{
    public function __construct(
        private Connection $db,
        private SearchPolicy $policy,
    ) {}

    public function search(D\AuthorizedSearchScope $scope, D\Query $query, int $k, ManifestView $view, VectorPayload $vector): SearchResult
    {
        $values = SearchSql::values($scope, $query, $view);
        $exact = $this->db->execute(SearchSql::exact($this->db), [...$values, $vector->literal, $k])->fetchAll(\PDO::FETCH_ASSOC);
        $count = (int) $this->db->execute('SELECT count(*) FROM (' . SearchSql::permitted($this->db) . ') corpus', $values)->fetchColumn();
        if ($this->policy->mode === 'exact' || $count === 0) {
            return $this->result($scope, $view, $exact, $count, null, false);
        }
        $ann = $this->ann($values, $vector, $k);
        $recall = $this->policy->recall(array_column($exact, 'chunk_id'), array_column($ann, 'chunk_id'));
        $fallback = $this->policy->requiresFallback($recall);

        return $this->result($scope, $view, $fallback ? $exact : $ann, $count, $recall, $fallback);
    }

    private function ann(array $values, VectorPayload $vector, int $k): array
    {
        $name = 'kk_authorized_' . bin2hex(random_bytes(12));
        $table = 'pg_temp.' . $name;
        $dimensions = count(json_decode($vector->literal, true, 512, JSON_THROW_ON_ERROR));
        $this->db->execute("CREATE TEMP TABLE $name (chunk_id text PRIMARY KEY, embedding public.vector($dimensions) NOT NULL) ON COMMIT DROP");
        // On error, the surrounding transaction rolls back CREATE TEMP TABLE as well.
        $this->db->execute("INSERT INTO $table " . SearchSql::permitted($this->db), $values);
        $this->db->execute("CREATE INDEX ON $table USING hnsw (embedding public.vector_l2_ops) WITH (m=16,ef_construction=64)");
        $this->db->execute("SELECT set_config('hnsw.ef_search', ?, true)", [(string) $this->policy->efSearch]);
        $this->db->execute("SELECT set_config('enable_seqscan', 'off', true)");
        $rows = $this->db->execute("SELECT chunk_id, embedding OPERATOR(public.<->) CAST(? AS public.vector) AS distance
            FROM $table ORDER BY embedding OPERATOR(public.<->) CAST(? AS public.vector) LIMIT CAST(? AS integer)",
            [$vector->literal, $vector->literal, $k])->fetchAll(\PDO::FETCH_ASSOC);
        $this->db->execute("DROP TABLE $table");
        usort($rows, static fn (array $a, array $b): int => ((float) $a['distance'] <=> (float) $b['distance'])
            ?: strcmp($a['chunk_id'], $b['chunk_id']));

        return $rows;
    }

    private function result(D\AuthorizedSearchScope $scope, ManifestView $view, array $rows, int $count, ?float $recall, bool $fallback): SearchResult
    {
        $candidates = [];
        foreach ($rows as $row) {
            if (!is_numeric($row['distance']) || !is_finite((float) $row['distance']) || (float) $row['distance'] < 0) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
            }
            $chunk = $view->chunk($row['chunk_id']);
            $candidates[] = new D\Candidate($chunk->ref, count($candidates) + 1, -(float) $row['distance']);
        }

        return new SearchResult(new D\CandidateList($scope, $candidates), $this->policy->mode,
            $recall === null || $fallback ? 'exact' : 'authorized-hnsw', $this->policy->fallback, $fallback, $recall, $count);
    }
}
