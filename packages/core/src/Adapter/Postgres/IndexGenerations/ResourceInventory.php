<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\IndexGenerations;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain\ManifestCandidate as Candidate;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};

/** Tenant-qualified joins enumerate every referenced canonical unit and asset, not just supplied refs. */
final readonly class ResourceInventory
{
    public function __construct(private Connection $db) {}
    /** @param list<D\ChunkingResult> $sets @return list<JsonObject> */
    public function resources(D\TenantId $tenant, array $sets): array
    {
        $refs = [];
        foreach ($sets as $result) {
            $set = $result->chunkSet; $revision = $set->revisionRef; $source = $revision->sourceRef;
            if (!$tenant->equals($source->tenantId)) { throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch'); }
            $row = $this->db->row('SELECT r.*,v.source_id,v.source_digest,cs.manifest_digest,cs.chunker_signature FROM ' . $this->db->table('chunk_sets') .
                ' cs JOIN ' . $this->db->table('document_revisions') . ' r ON r.tenant_id=cs.tenant_id AND r.id=cs.revision_id JOIN ' .
                $this->db->table('source_versions') . ' v ON v.tenant_id=r.tenant_id AND v.id=r.source_version_id WHERE cs.tenant_id=? AND cs.id=?',
                [$tenant->value, $set->chunkSetId->value]);
            if ($row === null || $row['id'] !== $revision->documentRevisionId->value || $row['ir_digest'] !== $revision->irDigest->value
                || $row['source_version_id'] !== $source->sourceVersionId->value || $row['source_id'] !== $source->sourceId->value
                || $row['source_digest'] !== $source->sourceDigest->value || $row['manifest_digest'] !== $result->manifestDigest->value
                || $row['parser_name'] !== $revision->parserSignature->name || $row['parser_version'] !== $revision->parserSignature->version
                || $row['profile_digest'] !== $revision->parserSignature->profileDigest->value
                || Jcs::canonicalize(StrictJson::decode($row['chunker_signature'])) !== Jcs::canonicalize($set->chunkerSignature->toWire())) { self::fail(); }
            $ids = [[$row['ir_resource_id'], 'ir']];
            $revisionId = $revision->documentRevisionId->value;
            foreach ($this->db->execute(
                'SELECT u.text_resource_id AS id, \'canonical_slice\' AS kind FROM ' . $this->db->table('pages') . ' u JOIN ' . $this->db->table('documents') .
                ' d ON d.tenant_id=u.tenant_id AND d.id=u.document_id WHERE u.tenant_id=? AND d.revision_id=?
                 UNION ALL SELECT u.text_resource_id, \'canonical_slice\' FROM ' . $this->db->table('segments') . ' u JOIN ' . $this->db->table('documents') .
                ' d ON d.tenant_id=u.tenant_id AND d.id=u.document_id WHERE u.tenant_id=? AND d.revision_id=?
                 UNION ALL SELECT a.resource_id, \'asset\' FROM ' . $this->db->table('assets') . ' a WHERE a.tenant_id=? AND a.revision_id=?',
                [$tenant->value, $revisionId, $tenant->value, $revisionId, $tenant->value, $revisionId],
            )->fetchAll(\PDO::FETCH_ASSOC) as $unit) {
                $ids[] = [$unit['id'], $unit['kind']];
            }
            $chunks = $this->db->execute('SELECT id,canonical_payload_ref,content_digest FROM ' . $this->db->table('chunks') . ' WHERE tenant_id=? AND chunk_set_id=? ORDER BY id',
                [$tenant->value, $set->chunkSetId->value])->fetchAll(\PDO::FETCH_ASSOC);
            $expected = [];
            foreach ($set->chunks as $chunk) { $expected[$chunk->ref->chunkId->value] = $chunk->ref->chunkDigest->value; }
            if (count($chunks) !== count($expected)) { self::fail(); }
            foreach ($chunks as $chunk) {
                if (($expected[$chunk['id']] ?? null) !== $chunk['content_digest']) { self::fail(); }
                $ids[] = [$chunk['canonical_payload_ref'], 'canonical_slice'];
            }
            $ledger = [];
            $wanted = array_values(array_unique(array_map(static fn (array $pair): string => $pair[0], $ids)));
            if ($wanted !== []) {
                foreach ($this->db->execute(
                    'SELECT id,state,digest,byte_length,object_version FROM ' . $this->db->table('resource_ledger') .
                    ' WHERE tenant_id=? AND source_id=? AND id IN (SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))',
                    [$tenant->value, $source->sourceId->value, json_encode($wanted, JSON_THROW_ON_ERROR)],
                )->fetchAll(\PDO::FETCH_ASSOC) as $resource) {
                    $ledger[$resource['id']] = $resource;
                }
            }
            foreach ($ids as [$id, $kind]) {
                $resource = $ledger[$id] ?? null;
                if ($resource === null || $resource['state'] !== 'accepted' || $resource['digest'] === null || $resource['object_version'] === null) {
                    throw new D\DomainFailure(D\Outcome::Unavailable, 'resource_unavailable');
                }
                $ref = new JsonObject(['resourceId'=>$id,'kind'=>$kind,'digest'=>$resource['digest'],'byteLength'=>(string)$resource['byte_length']]);
                if (isset($refs[$id]) && Jcs::canonicalize($refs[$id]) !== Jcs::canonicalize($ref)) { self::fail(); }
                $refs[$id] = $ref;
            }
        }
        ksort($refs, SORT_STRING); return array_values($refs);
    }
    public function assertExact(Candidate $candidate): void
    {
        $actual = $this->resources(new D\TenantId($candidate->manifest->toWire()->get('tenantId')), $candidate->sets);
        if (Jcs::canonicalize($actual) !== Jcs::canonicalize($candidate->resources)) { self::fail(); }
        if (Jcs::canonicalize($this->coverage(new D\TenantId($candidate->manifest->toWire()->get('tenantId')), $candidate->sets))
            !== Jcs::canonicalize($candidate->manifest->toWire()->get('coverage'))) { self::fail(); }
    }
    /** Aggregate authoritative revision coverage once per exact revision. */
    public function coverage(D\TenantId $tenant, array $sets): JsonObject
    {
        $revisions = [];
        foreach ($sets as $set) {
            if (!$tenant->equals($set->chunkSet->revisionRef->sourceRef->tenantId)) { throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch'); }
            $revisions[$set->chunkSet->revisionRef->documentRevisionId->value] = true;
        }
        ksort($revisions, SORT_STRING); $counts = ['processed'=>0,'empty'=>0,'failed'=>0,'skipped'=>0];
        $expected = 0; $allExpected = true; $state = 'complete'; $missing = []; $diagnostics = [];
        $ids = array_keys($revisions);
        $stored = [];
        if ($ids !== []) {
            foreach ($this->db->execute(
                'SELECT id,coverage_json FROM ' . $this->db->table('document_revisions') .
                ' WHERE tenant_id=? AND id IN (SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))',
                [$tenant->value, json_encode($ids, JSON_THROW_ON_ERROR)],
            )->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $stored[$r['id']] = $r['coverage_json'];
            }
        }
        foreach ($ids as $id) {
            if (!isset($stored[$id])) { self::fail(); } $coverage = StrictJson::decode($stored[$id]);
            foreach ($counts as $key=>$n) { $counts[$key] += $coverage->get('counts')->get($key); }
            if ($coverage->get('counts')->has('expected')) { $expected += $coverage->get('counts')->get('expected'); } else { $allExpected = false; }
            if ($coverage->get('state') === 'unknown') { $state = 'unknown'; }
            elseif ($coverage->get('state') === 'partial' && $state === 'complete') { $state = 'partial'; }
            $missing = array_merge($missing, $coverage->get('missingFeatures')); $diagnostics = array_merge($diagnostics, $coverage->get('diagnostics'));
        }
        if ($allExpected) { $counts['expected'] = $expected; } $missing = array_values(array_unique($missing)); sort($missing, SORT_STRING);
        return new JsonObject(['state'=>$state,'counts'=>new JsonObject($counts),'missingFeatures'=>$missing,'diagnostics'=>$diagnostics]);
    }
    private static function fail(): never { throw new D\DomainFailure(D\Outcome::Incompatible, 'manifest_mismatch'); }
}
