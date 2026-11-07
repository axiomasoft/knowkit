<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

interface ReconciliationStore
{
    /** Durably closes readiness before any recovery I/O; same ID requires same header. */
    public function start(D\RecoveryPlan $plan): void;
    /** @phpstan-impure */
    public function checkpoint(D\RecoveryPlan $plan): array;
    /** Bounded keyset scan: ledger, manifests or immutable outbox events. @return list<JsonObject> */
    public function scan(D\RecoveryPlan $plan, string $stage, ?string $after, int $limit): array;
    /** Atomically rechecks the checkpoint revision, applies replay/findings and advances the page.
     * @param list<JsonObject> $rows @param list<string> $outcomes */
    public function advance(D\RecoveryPlan $plan, array $before, array $after, array $rows, array $outcomes): void;
}
