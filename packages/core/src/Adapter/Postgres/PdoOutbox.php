<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{DomainEvent, DomainFailure, Outcome, TenantId};
use KnowKit\Domain\Contract\{Jcs, StrictJson};
use KnowKit\Port\Outbox;

/** Append only, caller transaction. Delivery owns separate short transactions. */
final readonly class PdoOutbox implements Outbox
{
    public function __construct(private Connection $db, private PdoCatalogTransaction $transaction)
    {
        if ($db !== $transaction->db) {
            throw new \InvalidArgumentException('Shared caller connection required');
        }
    }

    public function append(DomainEvent $event): void
    {
        $w = $event->toWire();
        $this->transaction->requireTenant(new TenantId((string) $w->get('tenantId')), true);
        $this->db->execute(
            'INSERT INTO ' . $this->db->table('outbox') .
            '(tenant_id,id,event_json,payload_digest,aggregate_version) VALUES (?,?,?::jsonb,?,?) ON CONFLICT (tenant_id,id) DO NOTHING',
            [
                $w->get('tenantId'),
                $w->get('eventId'),
                $event->canonical(),
                $w->get('payloadDigest'),
                $w->get('aggregateVersion'),
            ],
        );
        $stored = $this->db->row(
            'SELECT event_json FROM ' . $this->db->table('outbox') . ' WHERE tenant_id=? AND id=?',
            [$w->get('tenantId'), $w->get('eventId')],
        );
        if ($stored === null || Jcs::canonicalize(StrictJson::decode((string) $stored['event_json'])) !== $event->canonical()) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
