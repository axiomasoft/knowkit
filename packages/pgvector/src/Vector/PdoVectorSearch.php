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
        // The exact pass already visits the authorized corpus. Count before LIMIT
        // in that statement instead of repeating all membership/lifecycle joins.
        $count = (int) ($exact[0]['permitted_count'] ?? 0);
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
        $dimensions = $vector->dimensions;
        $this->db->execute("CREATE TEMP TABLE $name (chunk_id text PRIMARY KEY, embedding public.vector($dimensions) NOT NULL) ON COMMIT DROP");
        $failed = null;
        $rows = [];
        try {
            // On error, the surrounding transaction rolls back CREATE TEMP TABLE as well.
            $this->db->execute("INSERT INTO $table " . SearchSql::permitted($this->db), $values);
            $this->db->execute("CREATE INDEX ON $table USING hnsw (embedding public.vector_l2_ops) WITH (m=16,ef_construction=64)");
            $this->db->execute("SELECT pg_catalog.set_config('hnsw.ef_search', ?, true)", [(string) $this->policy->efSearch]);
            $this->db->execute("SELECT pg_catalog.set_config('enable_seqscan', 'off', true)");
            $rows = $this->db->execute("SELECT chunk_id, embedding OPERATOR(public.<->) CAST(? AS public.vector) AS distance
                FROM $table ORDER BY embedding OPERATOR(public.<->) CAST(? AS public.vector) LIMIT CAST(? AS integer)",
                [$vector->literal, $vector->literal, $k])->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $caught) {
            $failed = $caught;
            throw $caught;
        } finally {
            // An aborted transaction rejects cleanup. Local GUCs and ON COMMIT DROP
            // are restored by rollback; do not replace the original failure.
            try {
                $this->db->execute("SELECT pg_catalog.set_config('enable_seqscan', 'on', true)");
                $this->db->execute("DROP TABLE IF EXISTS $table");
            } catch (\Throwable $cleanup) {
                if ($failed === null) {
                    throw $cleanup;
                }
            }
        }
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
