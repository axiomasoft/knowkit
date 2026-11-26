<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Outbox;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, JsonObject};
use KnowKit\Port\OutboxDispatch;

final readonly class PdoDelivery implements OutboxDispatch
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private D\TenantId $tenant,
        private ContractValidator $validator,
        private int $leaseSeconds = 60,
    ) {
        if ($db !== $tx->db || $leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('Shared connection and bounded lease required');
        }
    }

    /** @phpstan-impure */
    public function leaseDelivery(int $limit): array
    {
        if ($limit < 1 || $limit > 64) {
            throw new \InvalidArgumentException('Batch must be between 1 and 64');
        }
        return $this->tx->run(function () use ($limit): array {
            $this->tx->lockTenant($this->tenant, D\LockMode::Update);
            $rows = $this->db->execute(
                'SELECT id FROM ' . $this->db->table('outbox') . " WHERE tenant_id=? AND
                ((delivery_state='pending' AND available_at<=clock_timestamp()) OR (delivery_state='leased' AND lease_until<=clock_timestamp()))
                ORDER BY available_at,id LIMIT ? FOR UPDATE SKIP LOCKED",
                [$this->tenant->value, $limit],
            )->fetchAll(\PDO::FETCH_ASSOC);
            $leases = [];
            foreach ($rows as $row) {
                $token = bin2hex(random_bytes(32));
                $leased = $this->db->row(
                    'UPDATE ' . $this->db->table('outbox') . " SET delivery_state='leased',delivery_fence=delivery_fence+1,
                    lease_token_hash=?,lease_until=clock_timestamp()+(? * interval '1 second'),attempts=attempts+1
                    WHERE tenant_id=? AND id=? RETURNING delivery_fence,to_char(lease_until AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS expires",
                    [hash('sha256', $token), $this->leaseSeconds, $this->tenant->value, $row['id']],
                );
                if ($leased === null) {
                    throw new D\DomainFailure(D\Outcome::Unavailable, 'storage_unavailable');
                }
                $leases[] = new D\DeliveryLease(
                    $this->tenant,
                    new D\EventId((string) $row['id']),
                    new D\Decimal((string) $leased['delivery_fence']),
                    $token,
                    new D\UtcInstant((string) $leased['expires']),
                );
            }
            return $leases;
        });
    }

    private function scope(D\DeliveryLease $lease): void
    {
        if (!$this->tenant->equals($lease->tenantId)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
    }

    public function event(D\DeliveryLease $lease): D\DomainEvent
    {
        $this->scope($lease);
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Delivery read requires committed lease');
        }
        $row = $this->db->row(
            'SELECT event_json FROM ' . $this->db->table('outbox') . " WHERE tenant_id=? AND id=? AND delivery_state='leased'
            AND delivery_fence=? AND lease_token_hash=? AND lease_until>clock_timestamp()",
            [$this->tenant->value, $lease->eventId->value, $lease->fence->value, $lease->tokenHash()->value],
        );
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'delivery_lease_lost');
        }
        return new D\DomainEvent(
            $this->validator->decodeValid((string) $row['event_json'], 'urn:knowkit:schema:domain-event:1.0'),
            $this->validator,
        );
    }

    public function ack(D\DeliveryLease $lease): bool
    {
        return $this->finish($lease, 'delivered', 0, null);
    }

    public function retry(D\DeliveryLease $lease, int $delaySeconds): bool
    {
        if ($delaySeconds < 1 || $delaySeconds > 3600) {
            throw new \InvalidArgumentException('Bounded positive retry required');
        }
        return $this->finish($lease, 'pending', $delaySeconds, 'transport_error');
    }

    public function quarantine(D\DeliveryLease $lease): bool
    {
        return $this->finish($lease, 'quarantined', 0, 'poison_event');
    }

    private function finish(D\DeliveryLease $lease, string $state, int $delay, ?string $error): bool
    {
        $this->scope($lease);
        return $this->tx->run(function () use ($lease, $state, $delay, $error): bool {
            $this->tx->lockTenant($this->tenant, D\LockMode::Update);
            return $this->db->execute(
                'UPDATE ' . $this->db->table('outbox') . " SET delivery_state=?,lease_until=NULL,
                lease_token_hash=NULL,available_at=clock_timestamp()+(? * interval '1 second'),last_error_code=?,transport_errors=transport_errors+?
                WHERE tenant_id=? AND id=? AND delivery_state='leased' AND delivery_fence=? AND lease_token_hash=? AND lease_until>clock_timestamp()",
                [
                    $state,
                    $delay,
                    $error,
                    $error === 'transport_error' ? 1 : 0,
                    $this->tenant->value,
                    $lease->eventId->value,
                    $lease->fence->value,
                    $lease->tokenHash()->value,
                ],
            )->rowCount() === 1;
        });
    }

    public function status(): JsonObject
    {
        $row = $this->db->row(
            'SELECT count(*) FILTER (WHERE delivery_state IN (\'pending\',\'leased\')) AS outstanding,
            count(*) FILTER (WHERE delivery_state=\'quarantined\') AS quarantined,coalesce(sum(transport_errors),0)::text AS errors,
            coalesce(floor(extract(epoch FROM (clock_timestamp()-min(enqueued_at) FILTER (WHERE delivery_state IN (\'pending\',\'leased\'))))),0)::text AS lag
            FROM ' . $this->db->table('outbox') . ' WHERE tenant_id=?',
            [$this->tenant->value],
        );
        return new JsonObject([
            'outstanding' => (int) ($row['outstanding'] ?? 0),
            'quarantined' => (int) ($row['quarantined'] ?? 0),
            'transportErrors' => (string) ($row['errors'] ?? '0'),
            'oldestOutstandingSeconds' => (string) ($row['lag'] ?? '0'),
        ]);
    }
}
