<?php
declare(strict_types=1);
namespace KnowKit\Adapter\LocalBlob;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\RecoveryEvidence as Evidence;

/** Administrative verifier using the same private filesystem safety/digest checks as LocalBlobStore.
 * The existing compact inventory cap (10,000 entries, depth 5) fails closed; every returned page is <=100.
 * Host supplies the authoritative journal, canonical projection loader and real denied-source canary.
 */
final readonly class RecoveryEvidence implements Evidence
{
    private PrivateFilesystem $fs;
    /** @param \Closure(D\RecoveryPlan,D\Decimal,int):array $journal
     * @param \Closure(D\RecoveryPlan,JsonObject):bool $rebuild
     * @param \Closure(D\RecoveryPlan):bool $canary
     * @param null|\Closure(D\RecoveryPlan,JsonObject):string $external */
    public function __construct(private D\TenantId $tenant, string $root, private string $storageId,
        private int $maxBytes, private \Closure $journal, private \Closure $rebuild, private \Closure $canary,
        private ?\Closure $external = null)
    {
        if ($storageId === '' || strlen($storageId)>128 || $maxBytes<1 || $maxBytes>=PHP_INT_MAX) { throw new \InvalidArgumentException('Bounded storage configuration required'); }
        $this->fs = new PrivateFilesystem($root);
    }
    private function scoped(D\RecoveryPlan $plan): void
    { if (!$this->tenant->equals($plan->tenantId)) { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); } }
    public function journal(D\RecoveryPlan $plan, D\Decimal $after, int $limit): array
    { $this->scoped($plan); return ($this->journal)($plan,$after,$limit); }
    public function objects(D\RecoveryPlan $plan, ?string $after, int $limit): array
    {
        $this->scoped($plan);
        if ($limit<1 || $limit>100) { throw new \InvalidArgumentException('Bounded page required'); }
        $keys = $this->fs->inventory($this->tenant->value); sort($keys,SORT_STRING); $rows = [];
        foreach ($keys as $key) {
            if ($after !== null && strcmp($this->storageId.'/'.$key,$after)<=0) { continue; }
            $p = explode('/',$key); $source = null; $staging = null; $version = null;
            try {
                $source = new D\SourceId($p[1]??'');
                if (count($p)===4 && $p[2]==='staging') { $staging = (new D\Ulid($p[3]))->value; }
                elseif (count($p)===5 && $p[2]==='sha256') { new D\Sha256($p[3]); $version = (new D\ResourceId($p[4]))->value; }
            } catch (\InvalidArgumentException) { /* Unknown identities remain explicit inventory members. */ }
            $rows[] = new JsonObject(['tenantId'=>$this->tenant->value,'sourceId'=>$source?->value,'storageId'=>$this->storageId,
                'storageKey'=>$key,'objectVersion'=>$version,'stagingId'=>$staging]);
            if (count($rows)===$limit) { break; }
        }
        return $rows;
    }
    public function resource(D\RecoveryPlan $plan, JsonObject $resource): string
    {
        $this->scoped($plan);
        if ($resource->get('tenantId') !== $this->tenant->value) { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); }
        if ($resource->get('owner_kind') === 'external' || $resource->get('storage_id') !== $this->storageId) {
            return $this->external === null ? 'unknown' : ($this->external)($plan,$resource);
        }
        $source = new D\SourceId($resource->get('source_id')); $key = $resource->get('storage_key');
        if (!is_string($key) || !str_starts_with($key,$this->tenant->value.'/'.$source->value.'/')) { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); }
        try {
            $h = $this->fs->open($key);
            try {
                if ($resource->get('digest') === null || $resource->get('object_version') === null) { return 'unknown'; }
                $ref = new D\BlobRef($this->tenant,$source,new D\Sha256($resource->get('digest')),new D\Uint64($resource->get('byte_length')),
                    $this->storageId,$resource->get('object_version'),'owned');
                $exact = $this->tenant->value.'/'.$source->value.'/sha256/'.$ref->digest->value.'/'.$ref->objectVersion;
                if ($exact !== $key) { return 'incompatible'; }
                $this->fs->verify($h,$ref,$this->maxBytes); return 'verified';
            } finally { fclose($h); }
        } catch (D\DomainFailure $e) {
            if ($e->errorCode === 'blob_missing') { return 'absent'; }
            if ($e->outcome === D\Outcome::Denied) { throw $e; }
            return $e->outcome->value;
        }
    }
    public function rebuild(D\RecoveryPlan $plan, JsonObject $generation): bool
    { $this->scoped($plan); return ($this->rebuild)($plan,$generation); }
    public function deniedCanary(D\RecoveryPlan $plan): bool
    { $this->scoped($plan); return ($this->canary)($plan); }
}
