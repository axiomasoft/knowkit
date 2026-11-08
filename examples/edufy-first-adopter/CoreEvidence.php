<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoLexicalIndex;
use KnowKit\Adapter\Lexical\PdoSelectionCatalog;
use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Disclosure;
use KnowKit\Application\Retrieval;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Port\ReferenceCache;

/** Maps host-owned current eligibility to existing public core retrieval/disclosure services. */
final class CoreEvidence implements Retrieval, Disclosure
{
    public readonly ReferenceCache $cache;
    private readonly PdoSelections $records;
    private readonly Selections $selections;
    private readonly Disclosures $disclosures;
    /** @var array<string,D\SelectionToken> */
    private array $tokens = [];
    public ?D\Selection $lastSelection = null;

    public function __construct(
        private readonly HostAuthority $authority,
        ?ReferenceCache $cache = null,
    ) {
        $r = $authority->runtime;
        $host = $r->host;
        $canonical = new CanonicalChunkSets(array_values($r->text->sets));
        $catalog = new PdoSelectionCatalog($host->db, $canonical, $host->validator, $r->text->documents, new CanonicalUnits());
        $index = new PdoLexicalIndex($host->db, $r->tx, $host->validator, $canonical, $host);
        $checks = new PolicyChecks($authority, $authority, $host);
        $this->cache = $cache ?? new InMemoryReferenceCache($host);
        $this->records = new PdoSelections($host->db, new D\SelectionRecordCodec($host->validator));
        $this->selections = new Selections($checks, $index, $catalog, $this->records,
            new BoundedSelector(), $host, $host, $host->validator, $this->cache);
        $buffers = new CanonicalBuffer($catalog,
            new PdoCanonicalDocuments($host->db, $r->storage->blobs, $host->validator), $host);
        $usages = new PdoUsages($host->db, $r->tx, $r->dependencies);
        $this->disclosures = new Disclosures($this->selections, $checks, $buffers, $usages, $host, $host->validator);
    }

    public function retrieve(D\Scope $scope, D\Query $query, D\Limit $limit): D\RetrievalOutcome
    {
        $this->authority->requireCurrent($scope);
        $r = $this->authority->runtime;
        $snapshot = $r->tx->run(function () use ($r, $scope): ?D\PublicationSnapshot {
            $r->tx->lockTenant($scope->tenantId, D\LockMode::Share);
            $r->tx->lockKb($scope->kbId, D\LockMode::Share);

            return $r->catalog->resolveSnapshot($scope->tenantId, $scope->kbId,
                D\SnapshotSelector::active(), new D\ProfileId(PdoLexicalIndex::PROFILE));
        });
        if ($snapshot === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'snapshot_missing');
        }
        $binding = (new Normalizer())->bind($query,
            new D\EffectiveProfile('local-text', '1', ['lexical' => new D\AdapterId('postgres.fts')]),
            D\SelectionLimits::profile('compact', maxFragments: min(4, $limit->k)));
        $outcome = $this->selections->retrieve($this->authority->context(), $binding, $snapshot->ref,
            $snapshot->generationIds[0], $this->authority->eligible(), $r->host->binding(), topK: $limit->k);
        if ($outcome->result->data instanceof D\Selection && $outcome->token !== null) {
            $this->lastSelection = $outcome->result->data;
            $this->tokens[$this->lastSelection->id()->value] = $outcome->token;
        }

        return $outcome;
    }

    public function disclose(D\Scope $scope, D\DisclosureRequest $request): D\DisclosureOutcome
    {
        $this->authority->requireCurrent($scope);
        $token = $this->tokens[$request->toWire()->get('selectionId')] ?? null;
        $record = $token === null ? null : $this->records->get($scope, $token->hash());
        if ($record === null) {
            throw new D\DomainFailure(D\Outcome::Denied, 'selection_invalid');
        }
        $this->authority->bindingOverride = $record->selection->policyBinding();
        try {
            return $this->disclosures->disclose($this->authority->context(), $token, $record->query, $record->snapshot, $request);
        } finally {
            $this->authority->bindingOverride = null;
        }
    }
}
