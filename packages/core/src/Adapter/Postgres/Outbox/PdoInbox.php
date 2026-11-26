<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Outbox;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\{EventInbox, IdGenerator};

/** A separate consumer store transaction owns BOTH invalidation and inbox acknowledgment. */
final readonly class PdoInbox implements EventInbox
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private D\TenantId $tenant,
        private string $consumer,
        private IdGenerator $ids,
    ) {
        if ($db !== $tx->db || preg_match('/\A[a-z][a-z0-9_.:-]{0,127}\z/D', $consumer) !== 1) {
            throw new \InvalidArgumentException('Shared connection and consumer identity required');
        }
    }

    private function scope(D\DomainEvent $event): void
    {
        if ($event->toWire()->get('tenantId') !== $this->tenant->value) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
    }

    private function lock(): bool
    {
        $this->db->execute(
            'INSERT INTO ' . $this->db->table('event_consumers') . ' (tenant_id,consumer_id) VALUES (?,?) ON CONFLICT DO NOTHING',
            [$this->tenant->value, $this->consumer],
        );
        $row = $this->db->row(
            'SELECT blocked FROM ' . $this->db->table('event_consumers') . ' WHERE tenant_id=? AND consumer_id=? FOR UPDATE',
            [$this->tenant->value, $this->consumer],
        );
        return (bool) ($row['blocked'] ?? false);
    }

    private function block(string $code): string
    {
        $this->db->execute(
            'UPDATE ' . $this->db->table('event_consumers') . ' SET blocked=true,error_code=? WHERE tenant_id=? AND consumer_id=?',
            [$code, $this->tenant->value, $this->consumer],
        );
        return 'blocked';
    }

    public function apply(D\DomainEvent $event): string
    {
        $this->scope($event);
        $w = $event->toWire();
        return $this->tx->run(function () use ($event, $w): string {
            $this->tx->lockTenant($this->tenant, D\LockMode::Update);
            if ($this->lock()) {
                return 'blocked';
            }
            $keys = [$this->tenant->value, $this->consumer, $w->get('aggregateType'), $w->get('aggregateId')];
            $envelope = hash('sha256', $event->canonical());
            $old = $this->db->row(
                'SELECT payload_digest,envelope_digest FROM ' . $this->db->table('inbox') . ' WHERE tenant_id=? AND consumer_id=? AND event_id=?',
                [$this->tenant->value, $this->consumer, $w->get('eventId')],
            );
            if ($old !== null) {
                if ($old['payload_digest'] !== $w->get('payloadDigest') || $old['envelope_digest'] !== $envelope) {
                    return $this->block('event_identity_conflict');
                }
                $projection = $this->projectionRow($keys);
                return ($projection['version_gap'] ?? false) ? 'gap' : 'duplicate';
            }
            $sequence = $this->db->row(
                'SELECT payload_digest FROM ' . $this->db->table('event_sequences') .
                ' WHERE tenant_id=? AND consumer_id=? AND aggregate_type=? AND aggregate_id=? AND aggregate_version=?',
                [...$keys, $w->get('aggregateVersion')],
            );
            if ($sequence !== null && $sequence['payload_digest'] !== $w->get('payloadDigest')) {
                return $this->block('aggregate_sequence_conflict');
            }
            $current = $this->projectionRow($keys);
            $version = new D\Decimal((string) $w->get('aggregateVersion'));
            $previous = new D\Decimal((string) ($current['aggregate_version'] ?? '0'));
            $gap = self::compare($version, $previous) > 0 && self::compare($version, $previous->next()) > 0;
            $payload = $w->get('payload');
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('event_projections') .
                ' (tenant_id,consumer_id,aggregate_type,aggregate_id,aggregate_version,lifecycle_epoch,source_policy_epoch,pointer_version,version_gap)
                VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT (tenant_id,consumer_id,aggregate_type,aggregate_id) DO UPDATE SET
                aggregate_version=greatest(event_projections.aggregate_version,EXCLUDED.aggregate_version),
                lifecycle_epoch=greatest(event_projections.lifecycle_epoch,EXCLUDED.lifecycle_epoch),
                source_policy_epoch=greatest(event_projections.source_policy_epoch,EXCLUDED.source_policy_epoch),
                pointer_version=greatest(event_projections.pointer_version,EXCLUDED.pointer_version),
                version_gap=event_projections.version_gap OR EXCLUDED.version_gap',
                [
                    ...$keys,
                    $version->value,
                    $payload->get('lifecycleEpoch') ?? ($w->get('aggregateType') === 'source' ? $version->value : '0'),
                    $payload->get('sourcePolicyEpoch') ?? '0',
                    $payload->get('pointerVersion') ?? '0',
                    $gap,
                ],
            );
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('inbox') . ' (tenant_id,id,consumer_id,event_id,payload_digest,applied_at,envelope_digest)
                VALUES (?,?,?,?,?,clock_timestamp(),?)',
                [$this->tenant->value, $this->ids->next()->value, $this->consumer, $w->get('eventId'), $w->get('payloadDigest'), $envelope],
            );
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('event_sequences') .
                ' (tenant_id,consumer_id,aggregate_type,aggregate_id,aggregate_version,event_id,payload_digest) VALUES (?,?,?,?,?,?,?) ON CONFLICT DO NOTHING',
                [...$keys, $version->value, $w->get('eventId'), $w->get('payloadDigest')],
            );
            return ($gap || ($current['version_gap'] ?? false)) ? 'gap' : 'applied';
        });
    }

    private static function compare(D\Decimal $a, D\Decimal $b): int
    {
        return strlen($a->value) <=> strlen($b->value) ?: strcmp($a->value, $b->value);
    }

    private function projectionRow(array $keys): ?array
    {
        return $this->db->row(
            'SELECT * FROM ' . $this->db->table('event_projections') . ' WHERE tenant_id=? AND consumer_id=? AND aggregate_type=? AND aggregate_id=?',
            $keys,
        );
    }

    public function reconcile(D\DomainEvent $event, D\Decimal $authoritativeVersion): void
    {
        $this->scope($event);
        $w = $event->toWire();
        $this->tx->run(function () use ($w, $authoritativeVersion): void {
            $this->tx->lockTenant($this->tenant, D\LockMode::Update);
            if ($this->lock()) {
                return;
            }
            // A concurrent newer event cannot be cleared by an older authoritative read.
            $this->db->execute(
                'UPDATE ' . $this->db->table('event_projections') . ' SET version_gap=false
                WHERE tenant_id=? AND consumer_id=? AND aggregate_type=? AND aggregate_id=? AND aggregate_version<=?',
                [$this->tenant->value, $this->consumer, $w->get('aggregateType'), $w->get('aggregateId'), $authoritativeVersion->value],
            );
        });
    }

    public function quarantine(D\Sha256 $envelopeDigest): void
    {
        $this->tx->run(function () use ($envelopeDigest): void {
            $this->tx->lockTenant($this->tenant, D\LockMode::Update);
            $this->lock();
            $this->db->execute(
                'INSERT INTO ' . $this->db->table('event_quarantine') . " (tenant_id,consumer_id,envelope_digest,error_code)
                VALUES (?,?,?,'poison_event') ON CONFLICT DO NOTHING",
                [$this->tenant->value, $this->consumer, $envelopeDigest->value],
            );
        });
    }

    /** Operational invalidation markers, never a serving/access decision. */
    public function projection(string $aggregateType, D\Ulid $aggregateId): ?JsonObject
    {
        $row = $this->projectionRow([$this->tenant->value, $this->consumer, $aggregateType, $aggregateId->value]);
        return $row === null ? null : new JsonObject([
            'aggregateVersion' => (string) $row['aggregate_version'],
            'lifecycleEpoch' => (string) $row['lifecycle_epoch'],
            'sourcePolicyEpoch' => (string) $row['source_policy_epoch'],
            'pointerVersion' => (string) $row['pointer_version'],
            'invalidated' => (bool) $row['invalidated'],
            'stale' => (bool) $row['stale'],
            'versionGap' => (bool) $row['version_gap'],
        ]);
    }
}
