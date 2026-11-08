<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Vector;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\PgVector\Domain\QueryVector;
use KnowKit\PgVector\Domain\ManifestView;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Domain\SearchResult;
use KnowKit\Port\VectorIndex;

/** Reference-only adapter. The host's provider callback performs fresh processing/egress checks.
 * Final text disclosure remains the core Selection/Disclosure authority's responsibility.
 */
final readonly class PdoVectorIndex implements VectorIndex
{
    /** @param \Closure(D\AuthorizedSearchScope,D\Query,D\EmbeddingSpace): QueryVector $embedQuery */
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private ContractValidator $validator,
        private PdoGenerationBackend $backend,
        private PdoVectorWriter $writer,
        private \Closure $embedQuery,
        private SearchPolicy $policy = new SearchPolicy(),
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared catalog connection required');
        }
    }

    public function index(D\GenerationId $generationId, D\ChunkSet $chunkSet, D\EmbeddingSpace $space): D\IndexReceipt
    {
        $tenant = $chunkSet->revisionRef->sourceRef->tenantId;
        $this->tx->requireTenant($tenant, true);
        $row = $this->db->row('SELECT manifest_json FROM ' . $this->db->table('index_generations')
            . ' WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant->value, $generationId->value]);
        if ($row === null || $row['manifest_json'] === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'staged_manifest_required');
        }
        $view = $this->backend->view(new D\IndexManifest(StrictJson::decode($row['manifest_json']), $this->validator));
        if (!$view->generation->equals($generationId)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_manifest_mismatch');
        }
        if (!$view->fingerprint->equals($space->digest())) {
            self::spaceMismatch();
        }

        return $this->writer->index($view, $chunkSet, $space);
    }

    public function search(D\AuthorizedSearchScope $scope, D\Query $query, int $k, D\EmbeddingSpace $space): D\CandidateList
    {
        return $this->searchWithDiagnostics($scope, $query, $k, $space)->candidates;
    }

    public function searchWithDiagnostics(D\AuthorizedSearchScope $scope, D\Query $query, int $k, D\EmbeddingSpace $space): SearchResult
    {
        if ($k < 1 || $k > 512) {
            throw new \InvalidArgumentException('Top-k must be 1..512');
        }
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Provider I/O must precede catalog transaction');
        }
        if ($scope->embeddingSpaceDigest?->value !== $space->digest()->value) {
            self::spaceMismatch();
        }
        $this->backend->sealed($scope);
        $vector = ($this->embedQuery)($scope, $query, $space)->payload($scope, $query, $space);

        return $this->tx->run(function () use ($scope, $query, $k, $vector): SearchResult {
            $this->tx->lockTenant($scope->tenantId, D\LockMode::Share);
            $this->tx->lockKb($scope->kbId, D\LockMode::Share);
            // Re-resolve after remote provider I/O; a rebuild cannot swap this pinned generation.
            $view = $this->backend->sealed($scope);

            return (new PdoVectorSearch($this->db, $this->policy))->search($scope, $query, $k, $view, $vector);
        });
    }

    private static function spaceMismatch(): never
    {
        throw new D\DomainFailure(D\Outcome::Incompatible, 'space_mismatch');
    }

    public function __debugInfo(): array
    {
        return ['profile' => ManifestView::PROFILE, 'searchMode' => $this->policy->mode];
    }
}
