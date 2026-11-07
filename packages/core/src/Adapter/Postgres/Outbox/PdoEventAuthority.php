<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Outbox;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Port\EventAuthority;

/** Local committed metadata only. The returned watermark never authorizes serving. */
final readonly class PdoEventAuthority implements EventAuthority
{
    public function __construct(private Connection $db, private D\TenantId $tenant) {}
    public function version(D\DomainEvent $event): ?D\Decimal
    {
        $w = $event->toWire();
        if ($w->get('tenantId') !== $this->tenant->value) { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); }
        if ($this->db->pdo->inTransaction()) { throw new \LogicException('Authority read requires committed consumer transaction'); }
        $type = $w->get('aggregateType'); $id = $w->get('aggregateId'); $p = $w->get('payload');
        // SQL identifiers and the version meaning are a fixed adapter map, never event-supplied SQL.
        if ($type === 'source' && $p->get('sourceId') === $id) {
            $row = $this->db->row('SELECT lifecycle_epoch AS version FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$id]);
            $tombstone = $this->db->row('SELECT lifecycle_epoch AS version FROM '.$this->db->table('tombstones').' WHERE tenant_id=? AND source_id=?',[$this->tenant->value,$id]);
            if ($row === null) { $row = $tombstone; }
        } elseif ($type === 'knowledge_base' && $p->get('kbId') === $id) {
            $row = $this->db->row('SELECT pointer_version AS version FROM '.$this->db->table('knowledge_bases').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$id]);
        } elseif ($type === 'source_version' && $p->get('sourceVersionId') === $id) {
            $row = $this->db->row('SELECT s.lifecycle_epoch AS version FROM '.$this->db->table('source_versions').' v JOIN '.$this->db->table('sources').
                ' s ON s.tenant_id=v.tenant_id AND s.id=v.source_id WHERE v.tenant_id=? AND v.id=?',[$this->tenant->value,$id]);
        } elseif ($type === 'generation' && $p->get('generationId') === $id) {
            $row = $this->db->row('SELECT revision AS version FROM '.$this->db->table('index_generations').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$id]);
        } else { return null; }
        return $row === null ? null : new D\Decimal((string)$row['version']);
    }
}
