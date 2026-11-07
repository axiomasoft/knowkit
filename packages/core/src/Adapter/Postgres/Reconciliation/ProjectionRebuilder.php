<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Reconciliation;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, LiteralQuery, PdoLexicalIndex};
use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\IndexGenerations\{PdoReadBackend, ResourceInventory};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Port\IdGenerator;

/** Restores only derived FTS rows. All immutable memberships/citation coordinates remain unchanged. */
final readonly class ProjectionRebuilder
{
    /** @param \Closure(D\TenantId,JsonObject):array $loadSets Administrative verified canonical loader; I/O outside SQL. */
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx,
        private D\TenantId $tenant, private ContractValidator $validator, private IdGenerator $ids, private \Closure $loadSets)
    { if ($tx->db !== $db) { throw new \InvalidArgumentException('Shared transaction required'); } }
    public function rebuild(D\RecoveryPlan $plan, JsonObject $row): bool
    {
        if (!$this->tenant->equals($plan->tenantId) || $row->get('tenantId') !== $this->tenant->value) { self::fail('scope_mismatch',D\Outcome::Denied); }
        if ($this->db->pdo->inTransaction()) { throw new \LogicException('Canonical loading outside SQL required'); }
        if ($row->get('manifest_json') === null) {
            if ($row->get('state') !== 'failed') { self::fail('recovery_manifest_missing'); }
            // An explicitly failed, never-published build has no projection to reconstruct.
            return $this->tx->run(function() use($plan,$row): bool {
                $this->tx->lockTenant($this->tenant,D\LockMode::Update); $this->requireRun($plan);
                $g = $this->db->row('SELECT id FROM '.$this->db->table('index_generations').
                    " WHERE tenant_id=? AND id=? AND state='failed' AND manifest_json IS NULL AND manifest_digest IS NULL",
                    [$this->tenant->value,(new D\GenerationId($row->get('id')))->value]);
                $required = $this->db->row('SELECT sg.generation_id FROM '.$this->db->table('snapshot_generations').' sg JOIN '.
                    $this->db->table('knowledge_bases').' k ON k.tenant_id=sg.tenant_id AND k.id=sg.kb_id AND k.active_snapshot_id=sg.snapshot_id WHERE sg.tenant_id=? AND sg.generation_id=? LIMIT 1',
                    [$this->tenant->value,$row->get('id')]);
                if ($g === null || $required !== null) { self::fail('recovery_generation_required'); }
                return true;
            });
        }
        $wire = StrictJson::decode($row->get('manifest_json'));
        if (!$wire instanceof JsonObject) { self::fail('manifest_mismatch',D\Outcome::Incompatible); }
        $manifest = new D\IndexManifest($wire,$this->validator);
        if ($wire->get('tenantId') !== $this->tenant->value || $wire->get('kbId') !== $row->get('kb_id')
            || $wire->get('generationId') !== $row->get('id') || $row->get('manifest_digest') !== HashDomain::IndexManifest->digest($wire)) { self::fail('manifest_mismatch',D\Outcome::Incompatible); }
        if ($row->get('profile_id') !== PdoLexicalIndex::PROFILE || $wire->get('profile')->get('kind') !== 'lexical'
            || $wire->get('profile')->get('id') !== PdoLexicalIndex::PROFILE || $wire->get('embeddingSpaceDigest') !== null
            || $row->get('embedding_space_digest') !== null) { self::fail('profile_incompatible',D\Outcome::Incompatible); }
        $sets = ($this->loadSets)($this->tenant,$wire); $canonical = new CanonicalChunkSets($sets);
        $candidate = new D\ManifestCandidate($manifest,$sets,$wire->get('resources'));
        return $this->tx->run(function() use ($plan,$row,$wire,$manifest,$sets,$canonical,$candidate): bool {
            $this->tx->lockTenant($this->tenant,D\LockMode::Update);
            $this->requireRun($plan);
            $this->tx->lockKb(new D\KbId($row->get('kb_id')),D\LockMode::Update);
            $sources = [];
            foreach ($wire->get('members') as $m) { $sources[$m->get('sourceId')] = new D\SourceId($m->get('sourceId')); }
            ksort($sources,SORT_STRING); $this->tx->lockSources(array_values($sources),D\LockMode::Share);
            $this->tx->lockGeneration(new D\GenerationId($row->get('id')),D\LockMode::Update);
            $g = $this->db->row('SELECT manifest_digest,manifest_json FROM '.$this->db->table('index_generations').' WHERE tenant_id=? AND id=?', [$this->tenant->value,$row->get('id')]);
            if ($g === null || $g['manifest_digest'] !== $candidate->digest->value || Jcs::canonicalize(StrictJson::decode($g['manifest_json'])) !== $manifest->canonical()) { self::fail('manifest_mismatch',D\Outcome::Incompatible); }
            (new ResourceInventory($this->db))->assertExact($candidate);
            $this->db->execute("SELECT set_config('knowkit.recovery_run',?,true),set_config('knowkit.recovery_generation',?,true)", [$plan->id->value,$row->get('id')]);
            $this->db->execute('DELETE FROM '.$this->db->table('lexical_documents').' WHERE tenant_id=? AND generation_id=?', [$this->tenant->value,$row->get('id')]);
            foreach ($sets as $set) {
                foreach ($set->chunkSet->chunks as $chunk) {
                    $ref = $chunk->ref; $source = $ref->revisionRef->sourceRef;
                    $this->db->execute('INSERT INTO '.$this->db->table('lexical_documents').
                        " (tenant_id,id,generation_id,chunk_id,source_id,source_version_id,simple_tsvector,exact_tokens,text_digest)
                         VALUES (?,?,?,?,?,?,to_tsvector('pg_catalog.simple',CAST(? AS text)),ARRAY(SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb))),?)",
                        [$this->tenant->value,$this->ids->next()->value,$row->get('id'),$ref->chunkId->value,$source->sourceId->value,$source->sourceVersionId->value,
                            $chunk->text(),json_encode(LiteralQuery::identifiers($chunk->text()),JSON_THROW_ON_ERROR),$ref->chunkDigest->value]);
                }
            }
            (new PdoReadBackend($this->db,$canonical))->reload($manifest);
            return true;
        });
    }
    private function requireRun(D\RecoveryPlan $plan): void
    {
        $current = $this->db->row('SELECT r.fingerprint FROM '.$this->db->table('reconciliation_runs').' r JOIN '.$this->db->table('tenants').
            " t ON t.id=r.tenant_id AND t.reconciliation_run_id=r.id WHERE r.tenant_id=? AND r.id=? AND r.stage='manifests' AND t.serving_state='blocked'",
            [$this->tenant->value,$plan->id->value]);
        if ($current === null || $current['fingerprint'] !== $plan->fingerprint()) { self::fail('recovery_checkpoint_conflict',D\Outcome::Incompatible); }
    }
    private static function fail(string $code, D\Outcome $outcome = D\Outcome::Unknown): never
    { throw new D\DomainFailure($outcome,$code); }
}
